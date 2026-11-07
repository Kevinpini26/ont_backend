<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DegreUrgence;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Notifications\ClasseurEnRetardNotification;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Lot 6, point 5 : un courrier « normal » ne reste plus au classeur sans limite. Au-delà du délai
 * (14 heures ouvrées, provisoire) : alerte à SEC1, « En retard » dans sa file, compteur dans l'Espace DG
 * et une seule relance de la DG. Le courrier ne part jamais seul à la DG.
 */
class ClasseurAlerteTest extends CourrierTestCase
{
    use RefreshDatabase;

    private User $sec1;

    private User $dg;

    protected function setUp(): void
    {
        parent::setUp();
        $direction = Direction::factory()->create();
        $this->sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $this->dg = $this->agent(Poste::DG, $direction);
    }

    private function heureKinshasa(string $quand): Carbon
    {
        return Carbon::parse($quand, 'Africa/Kinshasa')->utc();
    }

    private function auClasseurDepuis(Carbon $depuis, string $objet = 'Courrier normal'): Courrier
    {
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_CLASSEUR, 'degre_urgence' => DegreUrgence::NORMAL, 'objet' => $objet]);
        $courrier->transitions()->create([
            'statut' => CourrierStatut::EN_ATTENTE_CLASSEUR, 'destinataire_poste' => Poste::SECRETARIAT_1->value,
            'created_at' => $depuis, 'accuse_reception_at' => $depuis,
        ]);

        return $courrier->fresh();
    }

    public function test_au_dela_du_delai_sec1_et_la_dg_sont_alertes_une_seule_fois_sans_envoi_automatique(): void
    {
        $courrier = $this->auClasseurDepuis($this->heureKinshasa('2026-11-09 09:00')); // lundi

        $this->travelTo($this->heureKinshasa('2026-11-10 15:00')); // 13 h ouvrées : pas encore
        $this->artisan('courrier:alerter-classeur')->assertSuccessful();
        $this->assertCount(0, $this->sec1->fresh()->notifications);

        $this->travelTo($this->heureKinshasa('2026-11-11 09:30')); // 14 h 30 ouvrées (lundi 6 h 30, mardi 7 h, mercredi 1 h)
        $this->artisan('courrier:alerter-classeur')->assertSuccessful();
        $this->artisan('courrier:alerter-classeur')->assertSuccessful();

        $this->assertSame(1, $this->sec1->fresh()->notifications()->where('type', ClasseurEnRetardNotification::class)->count());
        $this->assertSame(1, $this->dg->fresh()->notifications()->where('type', ClasseurEnRetardNotification::class)->count());
        $this->assertSame(CourrierStatut::EN_ATTENTE_CLASSEUR, $courrier->fresh()->statut, 'Le courrier ne part jamais seul à la DG.');
        $this->assertNotNull($courrier->fresh()->classeur_alerte_envoyee_at);
    }

    public function test_un_courrier_mis_au_classeur_le_vendredi_soir_nest_pas_en_retard_le_lundi(): void
    {
        $this->auClasseurDepuis($this->heureKinshasa('2026-11-06 17:00'));

        $this->travelTo($this->heureKinshasa('2026-11-09 15:00'));
        $this->artisan('courrier:alerter-classeur')->assertSuccessful();

        $this->assertCount(0, $this->sec1->fresh()->notifications);
    }

    public function test_sec1_voit_en_retard_et_lespace_dg_compte_les_courriers_au_classeur_en_retard(): void
    {
        $this->auClasseurDepuis($this->heureKinshasa('2026-11-02 09:00'), 'Ancien au classeur');
        $this->auClasseurDepuis($this->heureKinshasa('2026-11-11 08:45'), 'Récent au classeur');
        $this->travelTo($this->heureKinshasa('2026-11-11 10:00'));

        $actions = collect($this->actingAs($this->sec1)->getJson('/api/v1/mes-actions')->assertOk()->json('data'))->keyBy('objet');
        $this->assertTrue($actions['Ancien au classeur']['en_retard']);
        $this->assertFalse($actions['Récent au classeur']['en_retard']);

        $this->actingAs($this->dg)->getJson('/api/v1/courriers/statistiques-dg')
            ->assertOk()->assertJsonPath('classeur_en_retard', 1)->assertJsonPath('classeur_delai_heures', 14);
    }

    public function test_le_delai_du_classeur_se_regle_dans_les_parametres(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->putJson('/api/v1/parametres/courrier.classeur_delai_heures', ['valeur' => 7])->assertOk();
        $this->auClasseurDepuis($this->heureKinshasa('2026-11-10 08:30'));
        $this->travelTo($this->heureKinshasa('2026-11-11 08:40'));
        $this->artisan('courrier:alerter-classeur')->assertSuccessful();

        $this->assertSame(1, $this->sec1->fresh()->notifications()->count());
    }
}
