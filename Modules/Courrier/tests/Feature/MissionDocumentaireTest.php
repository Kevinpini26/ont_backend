<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Courrier\Notifications\MissionDocumentaireNotification;
use Modules\Courrier\Services\MissionDocumentaireService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DelegationPoste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class MissionDocumentaireTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_dg_peut_missionner_chacun_de_ses_assistants_avec_identite_documentaire_inchangee(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $assistant1 = $this->agent(Poste::ASSISTANT_1, $direction);
        $assistant2 = $this->agent(Poste::ASSISTANT_2, $direction);
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'numero_enregistrement' => '2026-000001',
        ]);
        $identite = [$courrier->id, $courrier->dossier_id, $courrier->numero_enregistrement];

        $premiere = $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/missions", [
            'assistant_id' => $assistant1->id,
            'instruction' => 'Analyser le dossier.',
        ])->assertCreated()
            ->assertJsonPath('data.statut', MissionDocumentaireStatut::ASSIGNEE->value)
            ->assertJsonPath('data.autorite_poste', Poste::DG->value)
            ->assertJsonPath('data.assistant.id', $assistant1->id);

        $missionId = $premiere->json('data.id');
        Notification::assertSentTo($assistant1, MissionDocumentaireNotification::class);
        $this->actingAs($assistant1)->postJson("/api/v1/missions-documentaires/{$missionId}/prendre-en-charge")
            ->assertOk()
            ->assertJsonPath('data.statut', MissionDocumentaireStatut::EN_COURS->value);
        $this->actingAs($assistant1)->postJson("/api/v1/missions-documentaires/{$missionId}/retourner", [
            'compte_rendu' => 'Analyse terminée.',
        ])->assertOk();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/missions", [
            'assistant_id' => $assistant2->id,
            'instruction' => 'Vérifier cette analyse.',
        ])->assertCreated()->assertJsonPath('data.assistant.id', $assistant2->id);

        $this->assertCount(2, $courrier->missionsDocumentaires()->get());
        $this->assertSame($identite, [$courrier->fresh()->id, $courrier->dossier_id, $courrier->numero_enregistrement]);
        $this->assertDatabaseCount('courriers', 1);
        $this->assertDatabaseCount('dossiers', 1);
    }

    public function test_dga_ne_peut_missionner_que_assistant_dga_et_les_relations_croisees_sont_refusees(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $dga = $this->agent(Poste::DGA, $direction);
        $assistantDg = $this->agent(Poste::ASSISTANT_1, $direction);
        $assistantDga = $this->agent(Poste::ASSISTANT_DGA, $direction);
        $courrierDga = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $courrierDg = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $courrierDga->transitions()->create([
            'statut' => $courrierDga->statut,
            'nouveau_statut' => $courrierDga->statut,
            'destinataire_poste' => Poste::DGA,
            'created_at' => now(),
        ]);
        $courrierDg->transitions()->create([
            'statut' => $courrierDg->statut,
            'nouveau_statut' => $courrierDg->statut,
            'destinataire_poste' => Poste::DGA,
            'created_at' => now(),
        ]);

        $mission = $this->actingAs($dga)->postJson("/api/v1/courriers/{$courrierDga->id}/missions", [
            'assistant_id' => $assistantDga->id,
            'instruction' => 'Vérifier les éléments.',
        ])->assertCreated()->assertJsonPath('data.autorite_poste', Poste::DGA->value);

        $this->actingAs($dga)->postJson("/api/v1/courriers/{$courrierDg->id}/missions", [
            'assistant_id' => $assistantDg->id,
            'instruction' => 'Interdit.',
        ])->assertUnprocessable()->assertJsonValidationErrors('assistant_id');
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrierDg->id}/missions", [
            'assistant_id' => $assistantDga->id,
            'instruction' => 'Interdit.',
        ])->assertUnprocessable()->assertJsonValidationErrors('assistant_id');

        $id = $mission->json('data.id');
        $this->actingAs($assistantDga)->postJson("/api/v1/missions-documentaires/{$id}/prendre-en-charge")->assertOk();
        $this->actingAs($assistantDga)->postJson("/api/v1/missions-documentaires/{$id}/retourner", [
            'compte_rendu' => 'Vérification terminée.',
        ])->assertOk()->assertJsonPath('data.demandeur.id', $dga->id);
        Notification::assertSentTo($dga, MissionDocumentaireNotification::class);
    }

    public function test_instruction_est_obligatoire_immuable_et_une_seule_mission_dg_peut_etre_active(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $assistant1 = $this->agent(Poste::ASSISTANT_1, $direction);
        $assistant2 = $this->agent(Poste::ASSISTANT_2, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/missions", ['assistant_id' => $assistant1->id])
            ->assertUnprocessable()->assertJsonValidationErrors('instruction');
        $missionId = $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/missions", [
            'assistant_id' => $assistant1->id,
            'instruction' => 'Instruction initiale.',
        ])->assertCreated()->json('data.id');

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/missions", [
            'assistant_id' => $assistant2->id,
            'instruction' => 'Mission parallèle.',
        ])->assertUnprocessable()->assertJsonValidationErrors('courrier');
        $this->actingAs($dg)->putJson("/api/v1/missions-documentaires/{$missionId}", [
            'instruction' => 'Instruction modifiée.',
        ])->assertNotFound();
        $this->assertSame('Instruction initiale.', MissionDocumentaire::findOrFail($missionId)->instruction);
    }

    public function test_assistant_ne_peut_ni_decider_ni_envoyer_a_sec2_ni_dispatcher(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $assistant = $this->agent(Poste::ASSISTANT_1, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/missions", [
            'assistant_id' => $assistant->id,
            'instruction' => 'Analyser.',
        ])->assertCreated();

        $this->actingAs($assistant)->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'favorable'])->assertForbidden();
        $this->actingAs($assistant)->postJson("/api/v1/courriers/{$courrier->id}/dispatcher-direction")->assertForbidden();
        $this->actingAs($assistant)->postJson("/api/v1/courriers/{$courrier->id}/imputer", ['imputations' => []])->assertForbidden();
        $this->assertSame(CourrierStatut::EN_ATTENTE_AVIS_DG, $courrier->fresh()->statut);
    }

    public function test_annulation_est_auditee_sans_suppression_et_interdite_a_assistant_ou_apres_retour(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $assistant = $this->agent(Poste::ASSISTANT_1, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $id = $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/missions", [
            'assistant_id' => $assistant->id, 'instruction' => 'Analyser.',
        ])->assertCreated()->json('data.id');

        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$id}/annuler", ['motif' => 'Non'])->assertForbidden();
        $this->actingAs($dg)->postJson("/api/v1/missions-documentaires/{$id}/annuler", ['motif' => 'Priorité modifiée.'])
            ->assertOk()->assertJsonPath('data.statut', MissionDocumentaireStatut::ANNULEE->value);
        $this->assertDatabaseHas('missions_documentaires', ['id' => $id, 'motif_annulation' => 'Priorité modifiée.']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mission_documentaire.annulee', 'auditable_id' => $id]);

        $idRetour = $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/missions", [
            'assistant_id' => $assistant->id, 'instruction' => 'Nouvelle analyse.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$idRetour}/prendre-en-charge")->assertOk();
        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$idRetour}/retourner", ['compte_rendu' => 'Fait.'])->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/missions-documentaires/{$idRetour}/annuler", ['motif' => 'Trop tard.'])
            ->assertUnprocessable();
        $this->actingAs($dg)->deleteJson("/api/v1/missions-documentaires/{$idRetour}")->assertNotFound();
        $this->assertDatabaseCount('missions_documentaires', 2);
    }

    public function test_confidentialite_et_acces_horizontal_sont_limites_a_mission_nominative(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $assistantAutorise = $this->agent(Poste::ASSISTANT_1, $direction);
        $autreAssistant = $this->agent(Poste::ASSISTANT_2, $direction);
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'niveau_confidentialite' => NiveauConfidentialite::SECRET,
        ]);
        $missionId = $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/missions", [
            'assistant_id' => $assistantAutorise->id,
            'instruction' => 'Analyse confidentielle.',
        ])->assertCreated()->json('data.id');

        $this->actingAs($assistantAutorise)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk();
        $this->actingAs($autreAssistant)->getJson("/api/v1/courriers/{$courrier->id}")->assertNotFound();
        $this->actingAs($autreAssistant)->postJson("/api/v1/missions-documentaires/{$missionId}/prendre-en-charge")->assertForbidden();

        $this->actingAs($assistantAutorise)->postJson("/api/v1/missions-documentaires/{$missionId}/prendre-en-charge")->assertOk();
        $this->actingAs($assistantAutorise)->postJson("/api/v1/missions-documentaires/{$missionId}/retourner", ['compte_rendu' => 'Terminé.'])->assertOk();
        $this->actingAs($assistantAutorise)->getJson("/api/v1/courriers/{$courrier->id}")->assertNotFound();
    }

    public function test_retour_de_mission_ne_peut_pas_muter_le_projet_institutionnel_du_courrier(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $destination = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $assistant = $this->agent(Poste::ASSISTANT_1, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $courrier->imputations()->create([
            'direction_id' => $destination->id,
            'mention' => 'pour_attribution',
            'est_principale' => true,
            'imputee_par_id' => $dg->id,
        ]);
        $this->marquerDecharge($courrier);
        $id = $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/missions", [
            'assistant_id' => $assistant->id, 'instruction' => 'Préparer un projet.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$id}/prendre-en-charge")->assertOk();
        $projet = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$id}/retourner", [
            'compte_rendu' => 'Projet préparé.',
            'projet_reponse_contenu' => $projet,
        ])->assertUnprocessable()->assertJsonValidationErrors('projet_reponse_contenu');

        $this->assertNull($courrier->fresh()->projet_reponse_contenu);
        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$id}/retourner", [
            'compte_rendu' => 'Analyse terminée.',
        ])->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [[
            'type' => 'direction', 'direction_id' => $destination->id, 'instruction' => 'Traiter après retour de mission.',
        ]]])
            ->assertOk()->assertJsonPath('data.statut', CourrierStatut::EN_DISPATCH->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mission_documentaire.creee', 'auditable_id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mission_documentaire.retournee', 'auditable_id' => $id]);
    }

    public function test_assistant_dga_ne_recoit_que_le_courrier_missionne_et_perd_lacces_a_la_cloture(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $dga = $this->agent(Poste::DGA, $direction);
        $assistant = $this->agent(Poste::ASSISTANT_DGA, $direction);
        $a = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'niveau_confidentialite' => NiveauConfidentialite::SECRET, 'objet' => 'A missionné']);
        $b = Courrier::factory()->create(['dossier_id' => $a->dossier_id, 'niveau_confidentialite' => NiveauConfidentialite::SECRET, 'objet' => 'B confidentiel']);
        $c = Courrier::factory()->create(['dossier_id' => $a->dossier_id, 'niveau_confidentialite' => NiveauConfidentialite::SECRET, 'objet' => 'C confidentiel']);
        $d = Courrier::factory()->create(['dossier_id' => $a->dossier_id, 'niveau_confidentialite' => NiveauConfidentialite::SECRET, 'objet' => 'D confidentiel']);
        $a->transitions()->create(['statut' => $a->statut, 'nouveau_statut' => $a->statut, 'destinataire_poste' => Poste::DGA, 'created_at' => now()]);
        $missionId = $this->actingAs($dga)->postJson("/api/v1/courriers/{$a->id}/missions", [
            'assistant_id' => $assistant->id, 'instruction' => 'Analyser uniquement A.',
        ])->assertCreated()->json('data.id');

        $this->actingAs($assistant)->getJson("/api/v1/courriers/{$a->id}")->assertOk();
        foreach ([$b, $c, $d] as $courrier) {
            $this->actingAs($assistant)->getJson("/api/v1/courriers/{$courrier->id}")->assertNotFound();
        }
        $this->actingAs($assistant)->getJson("/api/v1/courriers/{$d->id}/pdf")->assertNotFound();
        $this->actingAs($assistant)->getJson("/api/v1/courriers/{$b->id}/annotations")->assertNotFound();
        $this->actingAs($assistant)->getJson("/api/v1/courriers/{$a->id}/annotations")->assertForbidden();
        $this->actingAs($assistant)->getJson("/api/v1/dossiers/{$a->dossier_id}")
            ->assertOk()->assertJsonCount(1, 'data.documents')->assertJsonPath('data.documents.0.id', $a->id);
        $this->actingAs($assistant)->getJson('/api/v1/courriers?recherche=confidentiel')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$missionId}/prendre-en-charge")->assertOk();
        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$missionId}/retourner", ['compte_rendu' => 'A analysé.'])->assertOk();
        $this->actingAs($assistant)->getJson("/api/v1/courriers/{$a->id}")->assertNotFound();
        $this->actingAs($assistant)->getJson("/api/v1/dossiers/{$a->dossier_id}")->assertNotFound();
        $this->actingAs($assistant)->getJson('/api/v1/missions-documentaires/mes-missions')
            ->assertOk()->assertJsonMissingPath('data.0.instruction')->assertJsonMissingPath('data.0.dossier_id');
    }

    public function test_assistant_dga_ne_peut_pas_consulter_kpi_ni_registre_institutionnels(): void
    {
        $assistant = $this->agent(Poste::ASSISTANT_DGA, Direction::factory()->create());

        $this->actingAs($assistant)->getJson('/api/v1/courriers/statistiques')->assertForbidden();
        $this->actingAs($assistant)->getJson('/api/v1/courriers/registre?debut=2026-01-01&fin=2026-12-31')->assertForbidden();
    }

    public function test_delegation_dg_trace_acteur_reel_et_autorite_metier(): void
    {
        Notification::fake();
        $admin = User::factory()->administrateur()->create();
        $delegataire = User::factory()->responsableDirection()->create();
        DelegationPoste::query()->create([
            'poste' => Poste::DG,
            'delegataire_id' => $delegataire->id,
            'debut' => now()->subDay()->toDateString(),
            'fin' => now()->addDay()->toDateString(),
            'motif' => 'Délégation de décision',
            'cree_par_id' => $admin->id,
        ]);
        $assistant = $this->agent(Poste::ASSISTANT_1, Direction::factory()->create());
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        $this->actingAs($delegataire)->postJson("/api/v1/courriers/{$courrier->id}/missions", [
            'assistant_id' => $assistant->id,
            'instruction' => 'Analyser au nom de la DG.',
        ])->assertCreated()
            ->assertJsonPath('data.demandeur.id', $delegataire->id)
            ->assertJsonPath('data.demandeur_poste', null)
            ->assertJsonPath('data.autorite_poste', Poste::DG->value);
    }

    public function test_deux_requetes_parties_du_meme_etat_ne_creent_pas_deux_missions_actives(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $assistant1 = $this->agent(Poste::ASSISTANT_1, $direction);
        $assistant2 = $this->agent(Poste::ASSISTANT_2, $direction);
        $courrierPerime = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $service = app(MissionDocumentaireService::class);

        $service->creer($courrierPerime, $dg, $assistant1, 'Première mission.');

        $this->expectException(ValidationException::class);
        $service->creer($courrierPerime, $dg, $assistant2, 'Mission concurrente.');
    }
}
