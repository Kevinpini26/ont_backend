<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

/** Lot 6 : tous les délais du circuit se comptent en heures ouvrées (lundi–vendredi, 8 h 30–15 h 30, Kinshasa). */
class DelaisHeuresOuvreesTest extends CourrierTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function heureKinshasa(string $quand): Carbon
    {
        return Carbon::parse($quand, 'Africa/Kinshasa')->utc();
    }

    private function enAttenteAvisDgDepuis(Carbon $depuis): Courrier
    {
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'objet' => 'Avis attendu']);
        $courrier->transitions()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'destinataire_poste' => Poste::DG, 'created_at' => $depuis]);

        return $courrier->fresh();
    }

    public function test_un_courrier_arrive_vendredi_soir_nest_pas_en_retard_le_lundi_matin(): void
    {
        $dg = $this->agent(Poste::DG, Direction::factory()->create());
        $this->enAttenteAvisDgDepuis($this->heureKinshasa('2026-11-06 18:00')); // vendredi soir

        Carbon::setTestNow($this->heureKinshasa('2026-11-09 11:00')); // lundi : 2 h 30 ouvrées seulement
        $ligne = collect($this->actingAs($dg)->getJson('/api/v1/mes-actions')->assertOk()->json('data'))->firstWhere('objet', 'Avis attendu');

        $this->assertNotNull($ligne);
        $this->assertFalse($ligne['en_retard']);

        Carbon::setTestNow($this->heureKinshasa('2026-11-11 08:40')); // mercredi : plus de 14 h ouvrées
        $ligne = collect($this->actingAs($dg)->getJson('/api/v1/mes-actions')->assertOk()->json('data'))->firstWhere('objet', 'Avis attendu');
        $this->assertTrue($ligne['en_retard']);
    }

    public function test_la_dg_nest_pas_relancee_pendant_le_week_end_pour_un_courrier_du_vendredi(): void
    {
        Mail::fake();
        $this->agent(Poste::DG, Direction::factory()->create());
        $courrier = $this->enAttenteAvisDgDepuis($this->heureKinshasa('2026-11-06 14:00'));

        Carbon::setTestNow($this->heureKinshasa('2026-11-08 20:00')); // dimanche
        $this->artisan('courrier:relancer-avis-dg-en-attente')->assertSuccessful();
        $this->assertNull($courrier->fresh()->relance_avis_dg_envoyee_at);

        Carbon::setTestNow($this->heureKinshasa('2026-11-10 15:00')); // mardi : plus de 14 h ouvrées
        $this->artisan('courrier:relancer-avis-dg-en-attente')->assertSuccessful();
        $this->assertNotNull($courrier->fresh()->relance_avis_dg_envoyee_at);
    }

    public function test_une_echeance_tombant_un_jour_ferie_nest_depassee_quapres_le_jour_ouvre_suivant(): void
    {
        $dg = $this->agent(Poste::DG, Direction::factory()->create());
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::DISPATCH_EXECUTE]);
        $dispatch = DispatchCourrier::query()->create([
            'courrier_id' => $courrier->id, 'dossier_id' => $courrier->dossier_id, 'cycle' => 1,
            'type_destination' => DispatchTypeDestination::DIRECTION, 'direction_id' => Direction::factory()->create()->id,
            'instruction' => 'Traiter', 'decisionnaire_id' => $dg->id, 'decisionnaire_poste' => Poste::DG, 'autorite_poste' => Poste::DG,
            'decide_at' => '2026-06-20', 'statut' => DispatchStatut::EXECUTE, 'execute_at' => '2026-06-21',
            'echeance_at' => '2026-06-30', // fête de l'indépendance (mardi)
        ]);

        Carbon::setTestNow($this->heureKinshasa('2026-07-01 12:00'));
        $this->assertFalse($dispatch->fresh()->estEnRetard());

        Carbon::setTestNow($this->heureKinshasa('2026-07-01 16:00'));
        $this->assertTrue($dispatch->fresh()->estEnRetard());
    }
}
