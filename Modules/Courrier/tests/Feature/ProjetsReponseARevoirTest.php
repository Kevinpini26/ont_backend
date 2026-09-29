<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Courrier\Notifications\ProjetReponseRelectureNotification;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class ProjetsReponseARevoirTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_bannette_nominative_relecture_et_cycle_de_correction_complet(): void
    {
        Notification::fake();
        [$a, $dg, $dg1, $dg2, $assistantDga, $sec1, $sec2, $missionId, $mission, $d] = $this->preparerProjet();

        $contenu = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
        $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse/soumettre", [
            'projet_reponse_contenu' => $contenu,
        ])->assertOk();

        $this->assertSame($dg2->id, $d->fresh()->relecteur_id);
        Notification::assertSentTo($dg2, ProjetReponseRelectureNotification::class, fn ($notification) => $notification->toArray($dg2)['evenement'] === 'soumis'
            && $notification->toArray($dg2)['lien'] === "/courriers/{$d->id}");

        $this->actingAs($dg2)->getJson('/api/v1/projets-reponse/a-relire')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $d->id)
            ->assertJsonPath('data.0.courrier_origine', null)
            ->assertJsonPath('data.0.createur', ['id' => $dg1->id, 'name' => $dg1->name])
            ->assertJsonMissingPath('data.0.createur.email')
            ->assertJsonMissingPath('data.0.relecteur');
        $this->actingAs($dg1)->getJson('/api/v1/projets-reponse/a-relire')->assertOk()->assertJsonCount(0, 'data');
        foreach ([$assistantDga, $sec1, $sec2] as $interdit) {
            $this->actingAs($interdit)->getJson('/api/v1/projets-reponse/a-relire')->assertForbidden();
        }
        $adminAvecPosteAssistant = User::factory()->administrateur()->create(['poste' => Poste::ASSISTANT_1]);
        $this->actingAs($adminAvecPosteAssistant)->getJson('/api/v1/projets-reponse/a-relire')->assertForbidden();

        $this->actingAs($dg2)->postJson("/api/v1/courriers/{$d->id}/accuser-reception")->assertOk();
        $this->actingAs($dg2)->postJson("/api/v1/courriers/{$d->id}/renvoyer-pour-correction", [
            'observation' => 'Préciser la date de prise d’effet.',
        ])->assertOk()->assertJsonPath('data.statut', CourrierStatut::PROJET_A_REDIGER->value);
        $this->actingAs($dg2)->getJson('/api/v1/projets-reponse/a-relire')->assertOk()->assertJsonCount(0, 'data');
        Notification::assertSentTo($dg1, ProjetReponseRelectureNotification::class, fn ($notification) => $notification->toArray($dg1)['evenement'] === 'retourne_pour_correction');

        $this->actingAs($dg1)->patchJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse", [
            'projet_reponse_contenu' => $contenu,
        ])->assertOk();
        $this->assertSame('en_cours', $mission->fresh()->statut->value);
        $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse/soumettre", [
            'projet_reponse_contenu' => $contenu,
        ])->assertOk();
        $this->assertSame($d->id, $mission->fresh()->projet_courrier_id);
        Notification::assertSentTo($dg2, ProjetReponseRelectureNotification::class, fn ($notification) => $notification->toArray($dg2)['evenement'] === 'resoumis');
        $this->actingAs($dg2)->getJson('/api/v1/projets-reponse/a-relire')->assertOk()->assertJsonPath('data.0.id', $d->id);

        $this->actingAs($dg2)->postJson("/api/v1/courriers/{$d->id}/accuser-reception")->assertOk();
        $this->actingAs($dg2)->postJson("/api/v1/courriers/{$d->id}/valider-relecture")->assertOk();
        $this->actingAs($dg2)->getJson('/api/v1/projets-reponse/a-relire')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('retournee', $mission->fresh()->statut->value);

        Notification::assertSentToTimes($dg2, ProjetReponseRelectureNotification::class, 2);
        Notification::assertSentToTimes($dg1, ProjetReponseRelectureNotification::class, 1);
        Notification::assertSentTo($dg2, ProjetReponseRelectureNotification::class, fn ($notification) => $notification->toArray($dg2)['evenement'] === 'soumis');
        Notification::assertSentTo($dg2, ProjetReponseRelectureNotification::class, fn ($notification) => $notification->toArray($dg2)['evenement'] === 'resoumis');
        Notification::assertSentTo($dg1, ProjetReponseRelectureNotification::class, fn ($notification) => $notification->toArray($dg1)['evenement'] === 'retourne_pour_correction');
        foreach ([$dg, $assistantDga, $sec1, $sec2] as $nonDestinataire) {
            Notification::assertNotSentTo($nonDestinataire, ProjetReponseRelectureNotification::class);
        }
    }

    public function test_assistant_dg2_redacteur_donne_assistant_dg1_comme_relecteur(): void
    {
        [$a, , $dg1, $dg2, , , , $missionId, , $d] = $this->preparerProjet(Poste::ASSISTANT_2);
        $contenu = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        $this->actingAs($dg2)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse/soumettre", [
            'projet_reponse_contenu' => $contenu,
        ])->assertOk();

        $this->assertSame($dg1->id, $d->fresh()->relecteur_id);
        $this->actingAs($dg2)->getJson('/api/v1/projets-reponse/a-relire')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($dg1)->getJson('/api/v1/projets-reponse/a-relire')->assertOk()->assertJsonPath('data.0.id', $d->id);
    }

    public function test_route_generique_impose_le_relecteur_canonique_pour_un_d_de_mission(): void
    {
        Notification::fake();
        [, , $dg1, $dg2, $assistantDga, , , , , $d] = $this->preparerProjet();
        $contenu = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        $this->actingAs($dg1)->postJson("/api/v1/courriers/{$d->id}/soumettre-projet-reponse", [
            'projet_reponse_contenu' => $contenu,
            'relecteur_id' => $assistantDga->id,
        ])->assertOk();

        $this->assertSame($dg2->id, $d->fresh()->relecteur_id);
        Notification::assertSentToTimes($dg2, ProjetReponseRelectureNotification::class, 1);
        Notification::assertNotSentTo($assistantDga, ProjetReponseRelectureNotification::class);
    }

    public function test_route_generique_ne_peut_pas_faire_soumettre_un_non_assigne_ni_sauto_designer(): void
    {
        [, , $dg1, $dg2, $assistantDga, , , , , $d] = $this->preparerProjet();
        $contenu = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        $this->actingAs($dg1)->postJson("/api/v1/courriers/{$d->id}/soumettre-projet-reponse", [
            'projet_reponse_contenu' => $contenu,
            'relecteur_id' => $dg1->id,
        ])->assertUnprocessable();

        $this->actingAs($dg2)->postJson("/api/v1/courriers/{$d->id}/soumettre-projet-reponse", [
            'projet_reponse_contenu' => $contenu,
            'relecteur_id' => $assistantDga->id,
        ])->assertNotFound();

        $this->assertSame(CourrierStatut::PROJET_A_REDIGER, $d->fresh()->statut);
    }

    public function test_id_sec2_fourni_a_la_route_generique_ne_devient_pas_relecteur_du_d_de_mission(): void
    {
        [, , $dg1, $dg2, , , $sec2, , , $d] = $this->preparerProjet();
        $contenu = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        $this->actingAs($dg1)->postJson("/api/v1/courriers/{$d->id}/soumettre-projet-reponse", [
            'projet_reponse_contenu' => $contenu,
            'relecteur_id' => $sec2->id,
        ])->assertOk();

        $this->assertSame($dg2->id, $d->fresh()->relecteur_id);
    }

    public function test_bannette_est_isolee_paginee_et_refuse_aux_postes_hors_assistants_dg(): void
    {
        [$a, , $dg1, $dg2, $assistantDga, $sec1, $sec2] = $this->acteurs();
        $projets = Courrier::factory()->count(21)->create([
            'dossier_id' => $a->dossier_id,
            'en_reponse_a_courrier_id' => $a->id,
            'sens' => 'sortant',
            'statut' => CourrierStatut::PROJET_A_VALIDER,
            'created_by' => $dg1->id,
            'relecteur_id' => $dg2->id,
            'relecture_validee_at' => null,
        ]);
        $autreProjet = Courrier::factory()->create([
            'dossier_id' => $a->dossier_id,
            'en_reponse_a_courrier_id' => $a->id,
            'sens' => 'sortant',
            'statut' => CourrierStatut::PROJET_A_VALIDER,
            'created_by' => $dg2->id,
            'relecteur_id' => $dg1->id,
            'relecture_validee_at' => null,
        ]);

        $page1 = $this->actingAs($dg2)->getJson('/api/v1/projets-reponse/a-relire?page=1')
            ->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 21)
            ->assertJsonMissingPath('data.0.createur.email')
            ->assertJsonMissingPath('data.0.relecteur');
        $page2 = $this->actingAs($dg2)->getJson('/api/v1/projets-reponse/a-relire?page=2')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);
        $idsPages = array_merge(
            collect($page1->json('data'))->pluck('id')->all(),
            collect($page2->json('data'))->pluck('id')->all(),
        );
        $this->assertCount(21, $idsPages);
        $this->assertCount(21, array_unique($idsPages));

        $this->actingAs($dg1)->getJson('/api/v1/projets-reponse/a-relire')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $autreProjet->id);
        $this->assertNotContains($autreProjet->id, collect($page1->json('data'))->pluck('id')->all());

        foreach ([$assistantDga, $sec1, $sec2] as $interdit) {
            $this->actingAs($interdit)->getJson('/api/v1/projets-reponse/a-relire')->assertForbidden();
        }
    }

    public function test_assistant_dga_ne_peut_pas_etre_redacteur_dune_mission_de_reponse_dg(): void
    {
        [$a, $dg, , , $assistantDga] = $this->acteurs();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/demander-preparation-reponse", [
            'assistant_id' => $assistantDga->id,
            'instruction' => 'Préparer une réponse officielle.',
        ])->assertUnprocessable();

        $administrateurAvecPosteAssistant = User::factory()->administrateur()->create(['poste' => Poste::ASSISTANT_1]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/demander-preparation-reponse", [
            'assistant_id' => $administrateurAvecPosteAssistant->id,
            'instruction' => 'Préparer une réponse officielle.',
        ])->assertUnprocessable();
    }

    /** @return array{Courrier, User, User, User, User, User, User} */
    private function acteurs(): array
    {
        $direction = Direction::factory()->create();
        $a = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        return [
            $a,
            $this->agent(Poste::DG, $direction),
            $this->agent(Poste::ASSISTANT_1, $direction),
            $this->agent(Poste::ASSISTANT_2, $direction),
            $this->agent(Poste::ASSISTANT_DGA, $direction),
            $this->agent(Poste::SECRETARIAT_1, $direction),
            $this->agent(Poste::SECRETARIAT_2, $direction),
        ];
    }

    /** @return array{Courrier, User, User, User, User, User, User, int, MissionDocumentaire, Courrier} */
    private function preparerProjet(Poste $posteRedacteur = Poste::ASSISTANT_1): array
    {
        [$a, $dg, $dg1, $dg2, $assistantDga, $sec1, $sec2] = $this->acteurs();
        $redacteur = $posteRedacteur === Poste::ASSISTANT_1 ? $dg1 : $dg2;
        $missionId = $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/demander-preparation-reponse", [
            'assistant_id' => $redacteur->id,
            'instruction' => 'Préparer une réponse officielle.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($redacteur)->postJson("/api/v1/missions-documentaires/{$missionId}/prendre-en-charge")->assertOk();
        $this->actingAs($redacteur)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse", [
            'objet' => 'Réponse officielle',
            'destinataire_externe_nom' => 'Demandeur',
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
        ])->assertCreated();

        $mission = MissionDocumentaire::findOrFail($missionId);
        $d = Courrier::withoutGlobalScopes()->findOrFail($mission->projet_courrier_id);

        return [$a, $dg, $dg1, $dg2, $assistantDga, $sec1, $sec2, $missionId, $mission, $d];
    }
}
