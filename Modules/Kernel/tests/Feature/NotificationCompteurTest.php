<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierTransition;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Consultation;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;
use Tests\TestCase;

class NotificationCompteurTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_responsable_de_direction_voit_le_compteur_de_courriers_recus(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        // Lot 2 : la direction ne reçoit qu'après le dispatch exécuté (avant, le courrier est invisible pour elle).
        Courrier::factory()->count(2)->create(['direction_destination_id' => $direction->id, 'statut' => CourrierStatut::DISPATCH_EXECUTE]);

        $this->actingAs($responsable)
            ->getJson('/api/v1/notifications/compteurs')
            ->assertOk()
            ->assertJsonPath('courriers_recus.count', 2)
            ->assertJsonPath('courriers_recus.tone', 'warning')
            ->assertJsonMissingPath('demandes_stage');
    }

    public function test_le_ton_devient_danger_si_un_courrier_nest_pas_traite_depuis_plus_de_48h(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $ancien = Courrier::factory()->create(['direction_destination_id' => $direction->id, 'statut' => CourrierStatut::DISPATCH_EXECUTE]);
        CourrierTransition::query()->create(['courrier_id' => $ancien->id, 'statut' => CourrierStatut::DISPATCH_EXECUTE, 'created_at' => now()->subHours(72)]);

        $this->actingAs($responsable)
            ->getJson('/api/v1/notifications/compteurs')
            ->assertOk()
            ->assertJsonPath('courriers_recus.tone', 'danger');
    }

    public function test_un_courrier_cree_avant_la_consultation_mais_dispatche_apres_compte_comme_recu(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        // Créé il y a 3 jours à la Réception, remis à la direction (dispatch exécuté) il y a 1 heure.
        $courrier = Courrier::factory()->create(['direction_destination_id' => $direction->id, 'statut' => CourrierStatut::DISPATCH_EXECUTE, 'created_at' => now()->subDays(3)]);
        CourrierTransition::query()->create(['courrier_id' => $courrier->id, 'statut' => CourrierStatut::DISPATCH_EXECUTE, 'created_at' => now()->subHour()]);
        Consultation::query()->create(['user_id' => $responsable->id, 'cle' => 'courriers_recus', 'consulte_at' => now()->subDay()]);

        $this->actingAs($responsable)
            ->getJson('/api/v1/notifications/compteurs')
            ->assertOk()
            ->assertJsonPath('courriers_recus.count', 1);
    }

    public function test_marquer_consulte_ramene_le_compteur_a_zero_pour_les_elements_deja_vus(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        Courrier::factory()->create(['direction_destination_id' => $direction->id, 'statut' => CourrierStatut::DISPATCH_EXECUTE]);

        $this->actingAs($responsable)
            ->postJson('/api/v1/notifications/marquer-consulte', ['cle' => 'courriers_recus'])
            ->assertNoContent();

        $this->actingAs($responsable)
            ->getJson('/api/v1/notifications/compteurs')
            ->assertOk()
            ->assertJsonPath('courriers_recus.count', 0);

        // Avance l'horloge de test pour garantir un created_at strictement
        // postérieur à consulte_at (les deux peuvent sinon tomber dans la
        // même seconde lors d'une exécution rapide du test).
        $this->travel(1)->seconds();
        Courrier::factory()->create(['direction_destination_id' => $direction->id, 'statut' => CourrierStatut::DISPATCH_EXECUTE]);

        $this->actingAs($responsable)
            ->getJson('/api/v1/notifications/compteurs')
            ->assertOk()
            ->assertJsonPath('courriers_recus.count', 1);
    }

    public function test_seule_la_dfp_voit_le_compteur_de_demandes_de_stage(): void
    {
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create(['direction_id' => $direction->id]);

        Stagiaire::factory()->create(['statut' => StagiaireStatut::DOSSIER_RECU]);
        Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        Stagiaire::factory()->create(['statut' => StagiaireStatut::STAGE_EN_COURS]);

        $this->actingAs($dfp)
            ->getJson('/api/v1/notifications/compteurs')
            ->assertOk()
            ->assertJsonPath('demandes_stage.count', 2)
            ->assertJsonPath('demandes_stage.tone', 'warning');

        $responsable = User::factory()->responsableDirection($direction)->create();
        $this->actingAs($responsable)
            ->getJson('/api/v1/notifications/compteurs')
            ->assertOk()
            ->assertJsonMissingPath('demandes_stage');
    }

    public function test_un_utilisateur_sans_direction_ne_voit_aucun_compteur_de_courrier(): void
    {
        $agent = User::factory()->agentCircuitCourrier(Poste::RECEPTION)->create(['direction_id' => null]);

        $this->actingAs($agent)
            ->getJson('/api/v1/notifications/compteurs')
            ->assertOk()
            ->assertJsonMissingPath('courriers_recus');
    }
}
