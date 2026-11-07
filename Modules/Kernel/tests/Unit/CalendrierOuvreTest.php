<?php

namespace Modules\Kernel\Tests\Unit;

use Carbon\CarbonImmutable;
use Modules\Kernel\Support\CalendrierOuvre;
use PHPUnit\Framework\TestCase;

/**
 * Heures ouvrées : lundi–vendredi, 8 h 30–15 h 30, heure de Kinshasa (UTC+1), jours fériés exclus.
 * Les instants sont donnés en heure de Kinshasa pour la lisibilité.
 */
class CalendrierOuvreTest extends TestCase
{
    private function calendrier(array $feries = [['date' => '06-30']]): CalendrierOuvre
    {
        return new CalendrierOuvre([
            'jours_ouvres' => [1, 2, 3, 4, 5],
            'heure_debut' => '08:30',
            'heure_fin' => '15:30',
            'fuseau' => 'Africa/Kinshasa',
            'jours_feries' => $feries,
        ]);
    }

    private function a(string $quand): CarbonImmutable
    {
        return CarbonImmutable::parse($quand, 'Africa/Kinshasa');
    }

    public function test_un_depot_du_vendredi_soir_nest_pas_en_retard_le_samedi_ni_le_dimanche(): void
    {
        $depot = $this->a('2026-11-06 18:00'); // vendredi

        $this->assertSame(0.0, $this->calendrier()->heuresOuvreesEntre($depot, $this->a('2026-11-08 20:00')));
        $this->assertFalse($this->calendrier()->estEnRetard($depot, 1, $this->a('2026-11-08 20:00')));
        // Lundi 10 h 30 : deux heures ouvrées seulement.
        $this->assertSame(2.0, $this->calendrier()->heuresOuvreesEntre($depot, $this->a('2026-11-09 10:30')));
    }

    public function test_un_jour_ferie_ne_compte_pas(): void
    {
        // Lundi 29 juin 15 h 30 → mercredi 1er juillet 10 h 30 : le 30 juin est férié.
        $this->assertSame(2.0, $this->calendrier()->heuresOuvreesEntre($this->a('2026-06-29 15:30'), $this->a('2026-07-01 10:30')));
        $this->assertFalse($this->calendrier()->estJourOuvre($this->a('2026-06-30 10:00')));
        $this->assertTrue($this->calendrier([])->estJourOuvre($this->a('2026-06-30 10:00')));
    }

    public function test_un_depot_hors_horaires_compte_a_partir_de_louverture_suivante(): void
    {
        $depot = $this->a('2026-11-04 20:00'); // mercredi soir

        $this->assertSame(0.0, $this->calendrier()->heuresOuvreesEntre($depot, $this->a('2026-11-05 08:00')));
        $this->assertSame(1.5, $this->calendrier()->heuresOuvreesEntre($depot, $this->a('2026-11-05 10:00')));
    }

    public function test_quatorze_heures_ouvrees_font_deux_journees_de_travail(): void
    {
        $calendrier = $this->calendrier();
        $depot = $this->a('2026-11-02 08:30'); // lundi à l'ouverture

        $this->assertFalse($calendrier->estEnRetard($depot, 14, $this->a('2026-11-03 15:30')));
        $this->assertTrue($calendrier->estEnRetard($depot, 14, $this->a('2026-11-04 08:31')));
        // Le seuil de « 14 h ouvrées avant mercredi 9 h 30 » tombe lundi 9 h 30 (heure de Kinshasa).
        $this->assertSame('2026-11-02 08:30:00', $calendrier->seuil(14, $this->a('2026-11-04 09:30'))->format('Y-m-d H:i:s'));
    }

    public function test_une_echeance_un_samedi_nest_depassee_quapres_la_fermeture_du_lundi(): void
    {
        $echeance = $this->a('2026-11-07'); // samedi

        $this->assertFalse($this->calendrier()->echeanceDepassee($echeance, $this->a('2026-11-09 15:00')));
        $this->assertTrue($this->calendrier()->echeanceDepassee($echeance, $this->a('2026-11-09 15:31')));
    }

    public function test_un_calendrier_sans_jour_ouvre_ne_met_jamais_en_retard(): void
    {
        $vide = new CalendrierOuvre(['jours_ouvres' => [], 'heure_debut' => '08:30', 'heure_fin' => '15:30', 'fuseau' => 'Africa/Kinshasa', 'jours_feries' => []]);

        $this->assertFalse($vide->estEnRetard($this->a('2020-01-01 09:00'), 14, $this->a('2026-11-06 12:00')));
    }
}
