<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Support\AssiduiteCalculateur;
use Tests\TestCase;

class AssiduiteCalculateurTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_aucune_suggestion_si_le_stage_na_pas_encore_commence(): void
    {
        $stagiaire = Stagiaire::factory()->create(['date_debut_stage' => null]);

        $this->assertNull(AssiduiteCalculateur::suggestion($stagiaire));
    }

    public function test_sans_aucune_presence_saisie_la_suggestion_reste_neutre_a_cinq_sur_cinq(): void
    {
        Carbon::setTestNow('2026-06-15');
        // Lundi 1er juin 2026 : jour ouvré unique, aucun pointage saisi.
        $stagiaire = Stagiaire::factory()->create(['date_debut_stage' => '2026-06-01', 'date_fin_stage' => '2026-06-01']);

        $suggestion = AssiduiteCalculateur::suggestion($stagiaire);

        $this->assertSame(5.0, $suggestion['ponctualite']);
        $this->assertSame(0.0, $suggestion['regularite']);
    }

    public function test_une_arrivee_a_lheure_chaque_jour_ouvre_donne_la_note_maximale(): void
    {
        Carbon::setTestNow('2026-06-05');
        // Lundi 1er au vendredi 5 juin 2026 : cinq jours ouvrés.
        $stagiaire = Stagiaire::factory()->create(['date_debut_stage' => '2026-06-01', 'date_fin_stage' => '2026-06-05']);

        foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05'] as $jour) {
            $stagiaire->presences()->create([
                'date' => $jour,
                'heure_arrivee' => '08:25:00',
                'heure_depart' => '15:35:00',
                'saisi_par_id' => User::factory()->agentDfp()->create()->id,
            ]);
        }

        $suggestion = AssiduiteCalculateur::suggestion($stagiaire->fresh());

        $this->assertSame(5.0, $suggestion['ponctualite']);
        $this->assertSame(5.0, $suggestion['regularite']);
        $this->assertStringContainsString('5 jour(s) pointé(s) sur 5 jour(s) ouvré(s)', $suggestion['detail']);
    }

    public function test_une_arrivee_tardive_degrade_la_ponctualite_mais_pas_la_regularite(): void
    {
        Carbon::setTestNow('2026-06-01');
        $stagiaire = Stagiaire::factory()->create(['date_debut_stage' => '2026-06-01', 'date_fin_stage' => '2026-06-01']);

        $stagiaire->presences()->create([
            'date' => '2026-06-01',
            'heure_arrivee' => '09:15:00',
            'heure_depart' => '15:35:00',
            'saisi_par_id' => User::factory()->agentDfp()->create()->id,
        ]);

        $suggestion = AssiduiteCalculateur::suggestion($stagiaire->fresh());

        $this->assertSame(0.0, $suggestion['ponctualite']);
        $this->assertSame(5.0, $suggestion['regularite']);
        $this->assertStringContainsString('écart(s) de ponctualité', $suggestion['detail']);
    }
}
