<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Courrier\Enums\MissionDocumentaireType;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class ProjetReponseDTest extends CourrierTestCase
{
    use RefreshDatabase;

    private array $contenu = [
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Projet officiel.']]]],
    ];

    public function test_cycle_dg1_redige_dg2_corrige_et_valide_puis_dg_signe_et_sec2_envoie(): void
    {
        Storage::fake('local');
        [$a, $dg, $dg1, $dg2, $sec2] = $this->acteurs(Poste::ASSISTANT_1);

        $missionId = $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/demander-preparation-reponse", [
            'assistant_id' => $dg1->id,
            'instruction' => 'Préparer une réponse favorable sur base des avis du dossier.',
        ])->assertCreated()->assertJsonPath('data.type', 'preparation_reponse')->json('data.id');

        $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/prendre-en-charge")->assertOk();
        $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse", [
            'objet' => 'Réponse officielle',
            'destinataire_externe_nom' => 'Entreprise Test ONT',
            'destinataire_externe_email' => 'contact@example.test',
            'projet_reponse_contenu' => $this->contenu,
        ])->assertCreated();

        $mission = MissionDocumentaire::query()->findOrFail($missionId);
        $d = Courrier::withoutGlobalScopes()->findOrFail($mission->projet_courrier_id);
        $this->assertSame('sortant', $d->sens->value);
        $this->assertSame($a->id, $d->en_reponse_a_courrier_id);
        $this->assertSame($a->dossier_id, $d->dossier_id);
        $this->assertDatabaseHas('document_relations', ['document_source_id' => $d->id, 'document_cible_id' => $a->id, 'type_relation' => 'reponse_a']);

        $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse/soumettre", [
            'projet_reponse_contenu' => $this->contenu,
        ])->assertOk()->assertJsonPath('data.relecteur.id', $dg2->id);

        $this->actingAs($dg2)->postJson("/api/v1/courriers/{$d->id}/accuser-reception")->assertOk();
        $this->actingAs($dg2)->postJson("/api/v1/courriers/{$d->id}/renvoyer-pour-correction", [
            'observation' => 'Préciser la date de prise d’effet.',
        ])->assertOk()->assertJsonPath('data.statut', CourrierStatut::PROJET_A_REDIGER->value);

        $corrige = [...$this->contenu, 'version_test' => 2];
        $this->actingAs($dg1)->patchJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse", [
            'projet_reponse_contenu' => $corrige,
        ])->assertOk();
        $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse/soumettre", [
            'projet_reponse_contenu' => $corrige,
        ])->assertOk()->assertJsonPath('data.id', $d->id);

        $this->actingAs($dg2)->postJson("/api/v1/courriers/{$d->id}/accuser-reception")->assertOk();
        $this->actingAs($dg2)->postJson("/api/v1/courriers/{$d->id}/valider-relecture")->assertOk();
        $this->assertSame(MissionDocumentaireStatut::RETOURNEE, $mission->fresh()->statut);

        $signature = $this->actingAs($dg)->postJson("/api/v1/courriers/{$d->id}/signer")
            ->assertOk()->assertJsonPath('data.statut', 'signe');
        $this->assertNotNull($signature->json('data.numero_depart'));
        $this->assertNotNull($signature->json('data.pdf_sha256'));

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$d->id}/accuser-reception")->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$d->id}/envoyer", [
            'destinataire_externe_nom' => 'Entreprise Test ONT',
            'destinataire_externe_email' => 'contact@example.test',
            'mode_expedition' => 'courriel',
        ])->assertOk()->assertJsonPath('data.statut', 'envoye');

        $a->refresh();
        $this->assertSame('entrant', $a->sens->value);
        $this->assertNull($a->signataire_id);
        $this->assertNull($a->numero_depart);
        $this->assertNull($a->projet_reponse_contenu);
    }

    public function test_dg2_redige_et_dg1_est_impose_comme_relecteur(): void
    {
        [$a, $dg, $dg1, $dg2] = $this->acteurs(Poste::ASSISTANT_2);

        $missionId = $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/demander-preparation-reponse", [
            'assistant_id' => $dg2->id,
            'instruction' => 'Préparer une réponse.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($dg2)->postJson("/api/v1/missions-documentaires/{$missionId}/prendre-en-charge")->assertOk();
        $this->actingAs($dg2)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse", [
            'objet' => 'Réponse', 'destinataire_externe_nom' => 'Demandeur', 'projet_reponse_contenu' => $this->contenu,
        ])->assertCreated();

        $response = $this->actingAs($dg2)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse/soumettre", [
            'projet_reponse_contenu' => $this->contenu,
        ])->assertOk();
        $response->assertJsonPath('data.relecteur.id', $dg1->id);
    }

    public function test_securite_unicite_et_compatibilite_legacy(): void
    {
        [$a, $dg, $dg1, $dg2] = $this->acteurs(Poste::ASSISTANT_1);
        $this->actingAs($dg1)->postJson('/api/v1/missions-documentaires/999999/projet-reponse', [])->assertNotFound();

        $missionId = $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/demander-preparation-reponse", [
            'assistant_id' => $dg1->id, 'instruction' => 'Préparer la réponse.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/prendre-en-charge")->assertOk();
        $payload = ['objet' => 'Réponse', 'destinataire_externe_nom' => 'Demandeur', 'projet_reponse_contenu' => $this->contenu];
        $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse", $payload)->assertCreated();
        $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse", $payload)->assertUnprocessable();
        $this->actingAs($dg2)->patchJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse", ['projet_reponse_contenu' => $this->contenu])->assertForbidden();

        $legacy = Courrier::factory()->create([
            'statut' => CourrierStatut::PROJET_A_REDIGER,
            'projet_reponse_contenu' => $this->contenu,
        ]);
        $this->assertSame($this->contenu, $legacy->fresh()->projet_reponse_contenu);
        $this->assertDatabaseMissing('missions_documentaires', ['courrier_id' => $legacy->id]);

        $generale = MissionDocumentaire::query()->create([
            'courrier_id' => $a->id, 'dossier_id' => $a->dossier_id, 'demandeur_id' => $dg->id,
            'autorite_poste' => Poste::DGA, 'assistant_id' => $dg2->id, 'instruction' => 'Mission historique',
            'statut' => MissionDocumentaireStatut::RETOURNEE, 'envoyee_at' => now(),
        ])->fresh();
        $this->assertSame(MissionDocumentaireType::GENERALE, $generale->type);
        $this->assertNull($generale->projet_courrier_id);
    }

    /** @return array{Courrier, User, User, User, User} */
    private function acteurs(Poste $redacteur): array
    {
        $direction = Direction::factory()->create();
        $a = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'direction_destination_id' => $direction->id,
            'expediteur_externe_nom' => 'Entreprise Test ONT',
            'expediteur_externe_email' => 'contact@example.test',
        ]);
        $dg = $this->agent(Poste::DG, $direction);
        $dg1 = $this->agent(Poste::ASSISTANT_1, $direction);
        $dg2 = $this->agent(Poste::ASSISTANT_2, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);

        $this->assertContains($redacteur, [$dg1->poste, $dg2->poste]);

        return [$a, $dg, $dg1, $dg2, $sec2];
    }
}
