<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\ClassementDocument;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class WorkflowTransversalCyclesTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_parcours_a_b_c_classement_documents_et_archivage_dossier(): void
    {
        Storage::fake('local');
        Notification::fake();
        $centrale = Direction::factory()->create();
        $dmc = Direction::factory()->create();
        $dep = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $centrale);
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $centrale);
        $dg = $this->agent(Poste::DG, $centrale);
        $assistant = $this->agent(Poste::ASSISTANT_1, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $secretariatDmc = User::factory()->secretariatDirection($dmc)->create();
        $directeurDmc = User::factory()->directeurDirection($dmc)->create();
        $secretariatDep = User::factory()->secretariatDirection($dep)->create();
        $directeurDep = User::factory()->directeurDirection($dep)->create();

        $idA = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Courrier A', 'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire',
            'piece_jointe' => UploadedFile::fake()->create('a.pdf', 20, 'application/pdf'),
        ])->assertCreated()->json('data.id');
        $a = Courrier::withoutGlobalScopes()->findOrFail($idA);
        $dossierId = $a->dossier_id;
        $numeroA = $a->numero_enregistrement;
        $this->presenterADg($a, $sec1, $dg);

        $missionId = $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/missions", [
            'assistant_id' => $assistant->id, 'instruction' => 'Analyser A.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/dispatchs", ['destinations' => [['type' => 'direction', 'direction_id' => $dmc->id, 'instruction' => 'Interdit pendant mission.']]])->assertUnprocessable();
        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$missionId}/prendre-en-charge")->assertOk();
        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$missionId}/retourner", ['compte_rendu' => 'Analyse A terminée.'])->assertOk();

        $b = $this->traiterEtProduire($a, $dmc, $dg, $sec2, $secretariatDmc, $directeurDmc, 'Courrier B');
        $this->assertSame($dossierId, $b->dossier_id);
        $this->recevoirEtPresenterADg($b, $reception, $sec1, $dg);
        $referenceB = $b->fresh()->reference_documentaire;
        $numeroB = $b->fresh()->numero_enregistrement;

        $c = $this->traiterEtProduire($b, $dep, $dg, $sec2, $secretariatDep, $directeurDep, 'Courrier C');
        $this->assertSame($dossierId, $c->dossier_id);
        $this->recevoirEtPresenterADg($c, $reception, $sec1, $dg);
        $referenceC = $c->fresh()->reference_documentaire;
        $numeroC = $c->fresh()->numero_enregistrement;

        foreach ([$a, $b, $c] as $index => $document) {
            $cyclePrecedent = (int) $document->dispatchs()->max('cycle');
            $this->actingAs($dg)->postJson("/api/v1/courriers/{$document->id}/dispatchs", ['destinations' => [[
                'type' => 'classement', 'instruction' => 'Classer le document.',
            ]]])->assertOk();
            $dispatchClassement = $document->dispatchs()->where('type_destination', 'classement')->firstOrFail();
            $this->assertSame($cyclePrecedent + 1, $dispatchClassement->cycle);
            $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatchClassement->id}/classer", [
                'cote' => 'DOSSIER-X-'.($index + 1), 'emplacement' => 'Archives / X',
            ])->assertOk();
            $classement = ClassementDocument::query()->where('courrier_id', $document->id)->firstOrFail();
            $this->actingAs($sec2)->postJson("/api/v1/classements-documents/{$classement->id}/archiver")->assertOk();
        }

        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$dossierId}/decision-archivage")->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/dossiers/{$dossierId}/archiver")->assertOk()->assertJsonPath('data.statut_archivage', 'archive');

        $this->assertDatabaseCount('courriers', 3);
        $this->assertDatabaseCount('dossiers', 1);
        $this->assertDatabaseHas('document_relations', ['document_source_id' => $b->id, 'document_cible_id' => $a->id, 'type_relation' => 'produit_a_partir_de']);
        $this->assertDatabaseHas('document_relations', ['document_source_id' => $c->id, 'document_cible_id' => $b->id, 'type_relation' => 'produit_a_partir_de']);
        $this->assertNotSame($a->id, $b->id);
        $this->assertNotSame($b->id, $c->id);
        $this->assertNotSame($numeroA, $numeroB);
        $this->assertNotSame($numeroB, $numeroC);
        $this->assertNotSame($referenceB, $referenceC);
        $this->assertSame($numeroA, $a->fresh()->numero_enregistrement);
        $this->assertSame($referenceB, $b->fresh()->reference_documentaire);
        $this->assertSame($referenceC, $c->fresh()->reference_documentaire);
        $this->assertDatabaseCount('classements_documents', 3);
        $this->assertDatabaseCount('document_relations', 2);
        $this->assertDatabaseHas('courrier_pieces_jointes', ['courrier_id' => $a->id]);
        $this->assertDatabaseHas('dossiers', ['id' => $dossierId, 'statut_archivage' => 'archive']);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/dispatchs", ['destinations' => [['type' => 'classement', 'instruction' => 'Interdit après archive.']]])->assertUnprocessable();
    }

    private function presenterADg(Courrier $courrier, User $sec1, User $dg): void
    {
        $this->actingAs($sec1)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();
        $this->actingAs($sec1)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-tri")->assertOk();
        $this->actingAs($sec1)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();
        $this->actingAs($sec1)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-avis-dg", ['degre_urgence' => 'urgent'])->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();
    }

    private function traiterEtProduire(Courrier $source, Direction $direction, User $dg, User $sec2, User $secretariat, User $directeur, string $objet): Courrier
    {
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$source->id}/dispatchs", ['destinations' => [[
            'type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Préparer un retour.',
        ]]])->assertOk();
        $dispatch = DispatchCourrier::query()->where('courrier_id', $source->id)->latest('id')->firstOrFail();
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertOk();
        $this->actingAs($secretariat)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();
        $traitement = TraitementDirection::query()->where('dispatch_courrier_id', $dispatch->id)->firstOrFail();
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/transmettre-directeur")->assertOk();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/prendre-en-charge")->assertOk();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/decision", ['decision' => 'retour_a_preparer'])->assertOk();
        $processusId = $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/document-produit", [
            'objet' => $objet, 'contenu' => ['type' => 'doc', 'content' => []],
        ])->assertCreated()->json('data.id');
        $this->actingAs($secretariat)->postJson("/api/v1/documents-produits-direction/{$processusId}/soumettre")->assertOk();
        $this->actingAs($directeur)->postJson("/api/v1/documents-produits-direction/{$processusId}/valider")->assertOk();
        $this->actingAs($secretariat)->postJson("/api/v1/documents-produits-direction/{$processusId}/transmettre-reception")->assertOk();

        return DocumentProduitDirection::query()->findOrFail($processusId)->courrier;
    }

    private function recevoirEtPresenterADg(Courrier $courrier, User $reception, User $sec1, User $dg): void
    {
        $processus = DocumentProduitDirection::query()->where('courrier_id', $courrier->id)->firstOrFail();
        $this->actingAs($reception)->postJson("/api/v1/documents-produits-direction/{$processus->id}/recevoir")->assertOk();
        $this->presenterADg($courrier, $sec1, $dg);
    }
}
