<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Courrier\Mail\ReponseFinaleCourrierExterneMail;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Courrier\Notifications\DispatchCourrierNotification;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DgDisponibilite;

class DispatchCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_dg_decide_plusieurs_dispatchs_sans_dupliquer_courrier_ni_dossier(): void
    {
        Notification::fake();
        $directionCentrale = Direction::factory()->create();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $directionCentrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $directionCentrale);
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'numero_enregistrement' => '2026-000777',
            'reference_documentaire' => 'ONT/DG/2026/000777',
            'numero_depart' => 'DEP-2026-0099',
        ]);
        $identite = [$courrier->id, $courrier->dossier_id, $courrier->numero_enregistrement, $courrier->reference_documentaire, $courrier->numero_depart];

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [
            ['type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Traiter le fond.'],
            ['type' => 'exterieur', 'destinataire_externe_nom' => 'Partenaire A', 'destinataire_externe_email' => 'contact@example.test', 'instruction' => 'Transmettre une copie.'],
            ['type' => 'classement', 'instruction' => 'Classer au dossier.'],
        ]])->assertOk()->assertJsonPath('data.statut', CourrierStatut::EN_DISPATCH->value)
            ->assertJsonCount(3, 'data.dispatchs');

        $this->assertDatabaseCount('courriers', 1);
        $this->assertDatabaseCount('dossiers', 1);
        $this->assertDatabaseCount('dispatchs_courrier', 3);
        $courrier->refresh();
        $this->assertSame($identite, [$courrier->id, $courrier->dossier_id, $courrier->numero_enregistrement, $courrier->reference_documentaire, $courrier->numero_depart]);
        Notification::assertSentTo($sec2, DispatchCourrierNotification::class);
    }

    public function test_sec2_execute_independamment_et_statut_global_attend_la_derniere_destination(): void
    {
        Notification::fake();
        $directionCentrale = Direction::factory()->create();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $directionCentrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $directionCentrale);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [
            ['type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Réceptionner.'],
            ['type' => 'classement', 'instruction' => 'Classer.'],
        ]])->assertOk();
        [$interne, $classement] = $courrier->dispatchs()->orderBy('id')->get();

        $this->receptionnerDispatchSec2($interne, $sec2);

        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$interne->id}/executer")->assertOk();
        $this->assertSame(CourrierStatut::EN_DISPATCH, $courrier->fresh()->statut);
        $this->assertSame(DispatchStatut::EXECUTE, $interne->fresh()->statut);
        Notification::assertSentTo($secretariat, DispatchCourrierNotification::class);

        $this->receptionnerDispatchSec2($classement, $sec2);

        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$classement->id}/executer")->assertUnprocessable();
        $this->assertDatabaseMissing('classements_documents', ['dispatch_courrier_id' => $classement->id]);
        $this->receptionnerDispatchSec2($classement, $sec2);
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$classement->id}/classer", ['emplacement' => 'Archives'])->assertOk();
        $this->assertSame(CourrierStatut::DISPATCH_EXECUTE, $courrier->fresh()->statut);
        $this->assertDatabaseHas('courrier_transitions', ['courrier_id' => $courrier->id, 'nouveau_statut' => 'dispatch_execute']);
    }

    public function test_dispatch_exterieur_exige_reference_ou_preuve_et_reutilise_pieces_jointes(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [[
            'type' => 'exterieur', 'destinataire_externe_nom' => 'Ministère', 'instruction' => 'Expédier.',
        ]]])->assertOk();
        $dispatch = $courrier->dispatchs()->firstOrFail();

        $this->receptionnerDispatchSec2($dispatch, $sec2);

        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertUnprocessable()->assertJsonValidationErrors('preuve');
        $this->receptionnerDispatchSec2($dispatch, $sec2);
        $this->actingAs($sec2)->post("/api/v1/dispatchs/{$dispatch->id}/executer", [
            'reference_transmission' => 'BORD-2026-01',
            'preuve' => UploadedFile::fake()->create('bordereau.pdf', 40, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.reference_transmission', 'BORD-2026-01')
            ->assertJsonPath('data.preuve_disponible', true);
        $this->assertDatabaseHas('courrier_pieces_jointes', ['courrier_id' => $courrier->id, 'libelle' => 'Preuve de dispatch #'.$dispatch->id]);
    }

    public function test_un_dispatch_exterieur_reste_operationnel_sans_declencher_de_reponse_finale_publique(): void
    {
        Mail::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $documentInterne = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$documentInterne->id}/dispatchs", ['destinations' => [[
            'type' => 'exterieur',
            'destinataire_externe_nom' => 'Partenaire institutionnel',
            'destinataire_externe_email' => 'partenaire@example.test',
            'instruction' => 'Transmettre selon le workflow historique.',
        ]]])->assertOk();
        $dispatch = $documentInterne->dispatchs()->firstOrFail();
        $this->receptionnerDispatchSec2($dispatch, $sec2);
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer", [
            'reference_transmission' => 'BORD-EXT-001',
        ])->assertOk();

        Mail::assertNotQueued(ReponseFinaleCourrierExterneMail::class);
    }

    public function test_secretariat_direction_ne_voit_que_son_dispatch_execute_et_confirme_reception(): void
    {
        $centrale = Direction::factory()->create();
        $directionA = Direction::factory()->create();
        $directionB = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $secretariatA = User::factory()->secretariatDirection($directionA)->create();
        $secretariatB = User::factory()->secretariatDirection($directionB)->create();
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'niveau_confidentialite' => NiveauConfidentialite::SECRET,
        ]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [[
            'type' => 'direction', 'direction_id' => $directionA->id, 'instruction' => 'Confidentiel.',
        ]]])->assertOk();
        $dispatch = $courrier->dispatchs()->firstOrFail();

        $this->actingAs($secretariatA)->getJson('/api/v1/dispatchs/boite-direction')->assertOk()->assertJsonCount(0, 'data');
        $this->receptionnerDispatchSec2($dispatch, $sec2);
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertOk();
        $this->actingAs($secretariatA)->getJson('/api/v1/dispatchs/boite-direction')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($secretariatB)->getJson('/api/v1/dispatchs/boite-direction')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($secretariatB)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertForbidden();
        $this->actingAs($secretariatA)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();
        $this->assertSame($secretariatA->id, $dispatch->fresh()->accuse_reception_par_id);
    }

    public function test_destinations_incoherentes_dupliquees_et_direction_non_operationnelle_sont_refusees(): void
    {
        $centrale = Direction::factory()->create();
        $inactive = Direction::factory()->create(['est_operationnelle' => false]);
        $dg = $this->agent(Poste::DG, $centrale);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [[
            'type' => 'direction', 'direction_id' => $inactive->id, 'instruction' => 'Non.',
        ]]])->assertUnprocessable()->assertJsonValidationErrors('destinations.0.direction_id');
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [
            ['type' => 'classement', 'instruction' => 'Classer.'], ['type' => 'classement', 'instruction' => 'Encore.'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('destinations.1');
        $this->assertDatabaseCount('dispatchs_courrier', 0);
    }

    public function test_dga_en_interim_decide_mais_seul_sec2_execute_et_aucune_mutation_generique_n_existe(): void
    {
        $centrale = Direction::factory()->create();
        $destination = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $centrale);
        $dga = $this->agent(Poste::DGA, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $directeur = User::factory()->directeurDirection($destination)->create();
        DgDisponibilite::definir(false);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [[
            'type' => 'classement', 'instruction' => 'Décision interdite à SEC2.',
        ]]])->assertNotFound();
        $this->actingAs($dga)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [[
            'type' => 'direction', 'direction_id' => $destination->id, 'instruction' => 'Pour traitement.',
        ]]])->assertOk()->assertJsonPath('data.dispatchs.0.decisionnaire.id', $dga->id)
            ->assertJsonPath('data.dispatchs.0.autorite_poste', Poste::DG->value);
        $dispatch = $courrier->dispatchs()->firstOrFail();

        $this->actingAs($dg)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertForbidden();
        $this->actingAs($dga)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertForbidden();
        $this->actingAs($directeur)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertForbidden();
        $this->actingAs($sec2)->putJson("/api/v1/dispatchs/{$dispatch->id}", ['direction_id' => $centrale->id])->assertNotFound();
        $this->receptionnerDispatchSec2($dispatch, $sec2);
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertOk();
        $dispatch->refresh();
        $this->assertSame($destination->id, $dispatch->direction_id);
        $this->assertSame($sec2->id, $dispatch->execute_par_id);
        $this->assertNotNull($dispatch->execute_at);
    }

    public function test_un_cycle_direction_termine_permet_un_second_cycle_de_classement_sans_changer_les_identites(): void
    {
        $centrale = Direction::factory()->create();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $directeur = User::factory()->directeurDirection($direction)->create();
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'numero_enregistrement' => '2026-000321', 'reference_documentaire' => 'REF-A', 'numero_depart' => 'DEP-A',
        ]);
        $identites = $courrier->only(['id', 'dossier_id', 'numero_enregistrement', 'reference_documentaire', 'numero_depart']);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [[
            'type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Traiter A.',
        ]]])->assertOk();
        $premier = $courrier->dispatchs()->firstOrFail();
        $this->assertSame(1, $premier->cycle);
        $this->receptionnerDispatchSec2($premier, $sec2);
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$premier->id}/executer")->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [['type' => 'classement', 'instruction' => 'Trop tôt.']]])->assertUnprocessable();

        $this->actingAs($secretariat)->postJson("/api/v1/dispatchs/{$premier->id}/accuser-reception")->assertOk();
        $traitement = TraitementDirection::query()->firstOrFail();
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/transmettre-directeur")->assertOk();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/prendre-en-charge")->assertOk();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/decision", ['decision' => 'retour_a_preparer'])->assertOk();
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/document-produit", [
            'objet' => 'B', 'contenu' => ['type' => 'doc', 'content' => []],
        ])->assertCreated();
        $this->assertDatabaseCount('documents_produits_direction', 1);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [[
            'type' => 'classement', 'instruction' => 'Document B encore actif.',
        ]]])->assertUnprocessable();
        DocumentProduitDirection::query()->firstOrFail()->update(['statut' => 'entre_circuit']);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [[
            'type' => 'classement', 'instruction' => 'Classer A.',
        ]]])->assertOk();
        $classement = $courrier->dispatchs()->where('cycle', 2)->firstOrFail();
        $this->assertSame('classement', $classement->type_destination->value);
        $this->assertSame($dg->id, $classement->decisionnaire_id);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [['type' => 'classement', 'instruction' => 'Double clic.']]])->assertForbidden();
        $this->assertSame(2, $courrier->dispatchs()->count());

        $this->receptionnerDispatchSec2($classement, $sec2);

        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$classement->id}/classer", ['emplacement' => 'Archives A'])->assertOk();
        $classementDocument = $courrier->classement()->firstOrFail();
        $this->actingAs($sec2)->postJson("/api/v1/classements-documents/{$classementDocument->id}/archiver")->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [['type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Interdit.']]])->assertUnprocessable();

        $this->assertSame($identites, $courrier->fresh()->only(array_keys($identites)));
        $this->assertSame($courrier->dossier_id, DocumentProduitDirection::query()->firstOrFail()->courrier->dossier_id);
    }

    public function test_toutes_les_branches_directionnelles_doivent_etre_terminees_avant_le_cycle_suivant(): void
    {
        $centrale = Direction::factory()->create();
        $directionA = Direction::factory()->create();
        $directionB = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $secretariatA = User::factory()->secretariatDirection($directionA)->create();
        $secretariatB = User::factory()->secretariatDirection($directionB)->create();
        $directeurA = User::factory()->directeurDirection($directionA)->create();
        $directeurB = User::factory()->directeurDirection($directionB)->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [
            ['type' => 'direction', 'direction_id' => $directionA->id, 'instruction' => 'A'],
            ['type' => 'direction', 'direction_id' => $directionB->id, 'instruction' => 'B'],
        ]])->assertOk();
        [$dispatchA, $dispatchB] = $courrier->dispatchs()->orderBy('id')->get();
        foreach ([$dispatchA, $dispatchB] as $dispatch) {
            $this->receptionnerDispatchSec2($dispatch, $sec2);
            $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertOk();
        }
        $this->terminerTraitement($dispatchA, $secretariatA, $directeurA);
        $this->actingAs($secretariatB)->postJson("/api/v1/dispatchs/{$dispatchB->id}/accuser-reception")->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [['type' => 'classement', 'instruction' => 'Trop tôt']]])->assertUnprocessable();

        $traitementB = TraitementDirection::query()->where('dispatch_courrier_id', $dispatchB->id)->firstOrFail();
        $this->actingAs($secretariatB)->postJson("/api/v1/traitements-direction/{$traitementB->id}/transmettre-directeur")->assertOk();
        $this->actingAs($directeurB)->postJson("/api/v1/traitements-direction/{$traitementB->id}/prendre-en-charge")->assertOk();
        $this->actingAs($directeurB)->postJson("/api/v1/traitements-direction/{$traitementB->id}/decision", ['decision' => 'traitement_termine'])->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [['type' => 'classement', 'instruction' => 'Classer']]])->assertOk();
        $this->assertDatabaseHas('dispatchs_courrier', ['courrier_id' => $courrier->id, 'cycle' => 2, 'type_destination' => 'classement']);
    }

    private function terminerTraitement(DispatchCourrier $dispatch, User $secretariat, User $directeur): void
    {
        $this->actingAs($secretariat)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();
        $traitement = TraitementDirection::query()->where('dispatch_courrier_id', $dispatch->id)->firstOrFail();
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/transmettre-directeur")->assertOk();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/prendre-en-charge")->assertOk();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/decision", ['decision' => 'traitement_termine'])->assertOk();
    }
}
