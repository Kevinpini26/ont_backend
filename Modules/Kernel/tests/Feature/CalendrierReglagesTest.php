<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\CalendrierOuvre;
use Tests\TestCase;

/** Lot 6 : le calendrier de travail se règle dans « Paramètres de l'organisation », avec trace d'audit. */
class CalendrierReglagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_ladministrateur_voit_le_calendrier_par_defaut(): void
    {
        $admin = User::factory()->administrateur()->create();

        $parametres = collect($this->actingAs($admin)->getJson('/api/v1/parametres')->assertOk()->json('data'))->keyBy('cle');

        $this->assertSame([1, 2, 3, 4, 5], $parametres['calendrier.jours_ouvres']['valeur']);
        $this->assertSame('08:30', $parametres['calendrier.heure_debut']['valeur']);
        $this->assertSame('15:30', $parametres['calendrier.heure_fin']['valeur']);
        $this->assertContains('06-30', array_column($parametres['calendrier.jours_feries']['valeur'], 'date'));
    }

    public function test_les_jours_feries_et_les_jours_ouvres_se_reglent_et_sont_audites(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->putJson('/api/v1/parametres/calendrier.jours_feries', ['valeur' => [
            ['date' => '06-30', 'libelle' => "Fête de l'indépendance"],
            ['date' => '2026-11-09', 'libelle' => 'Pont exceptionnel'],
        ]])->assertOk();
        $this->actingAs($admin)->putJson('/api/v1/parametres/calendrier.jours_ouvres', ['valeur' => [1, 2, 3, 4, 5, 6]])->assertOk();

        $calendrier = CalendrierOuvre::depuisReglages();
        $this->assertFalse($calendrier->estJourOuvre(now()->setTimezone('Africa/Kinshasa')->setDate(2026, 11, 9)->setTime(10, 0)));
        $this->assertTrue($calendrier->estJourOuvre(now()->setTimezone('Africa/Kinshasa')->setDate(2026, 11, 7)->setTime(10, 0)));
        $this->assertSame(2, AuditLog::query()->where('action', 'parametre.modifie')->count());
    }

    public function test_un_reglage_incoherent_est_refuse(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->putJson('/api/v1/parametres/calendrier.jours_ouvres', ['valeur' => [8]])->assertUnprocessable();
        $this->actingAs($admin)->putJson('/api/v1/parametres/calendrier.heure_fin', ['valeur' => '07:00'])->assertUnprocessable();
        $this->actingAs($admin)->putJson('/api/v1/parametres/calendrier.jours_feries', ['valeur' => [['date' => '13-45', 'libelle' => 'x']]])->assertUnprocessable();
        $this->actingAs($admin)->putJson('/api/v1/parametres/calendrier.fuseau', ['valeur' => 'Mars/Olympus'])->assertUnprocessable();
    }

    public function test_seul_ladministrateur_regle_le_calendrier(): void
    {
        $dfp = User::factory()->agentDfp()->create();

        $this->actingAs($dfp)->putJson('/api/v1/parametres/calendrier.jours_ouvres', ['valeur' => [1, 2, 3]])->assertForbidden();
    }
}
