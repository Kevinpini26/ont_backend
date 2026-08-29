<?php

namespace Modules\Kernel\Tests\Unit;

use Illuminate\Support\Carbon;
use Modules\Kernel\Support\PeriodeStatistique;
use PHPUnit\Framework\TestCase;

class PeriodeStatistiqueTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_une_cle_inconnue_retombe_sur_30_jours(): void
    {
        $periode = new PeriodeStatistique('bidon');

        $this->assertSame('30j', $periode->cle);
    }

    public function test_la_periode_7_jours_couvre_bien_sept_jours_pleins(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $periode = new PeriodeStatistique('7j');

        $this->assertSame('2026-06-09', $periode->debut->toDateString());
        $this->assertSame('2026-06-15', $periode->fin->toDateString());
    }

    public function test_la_periode_annee_en_cours_traverse_correctement_une_annee_bissextile(): void
    {
        // 2028 est bissextile (29 février existe) : la période précédente
        // (2027, non bissextile) ne doit provoquer aucune erreur de date
        // ni décalage d'un jour sur les bornes.
        Carbon::setTestNow('2028-02-29 12:00:00');

        $periode = new PeriodeStatistique('annee');

        $this->assertSame('2028-01-01', $periode->debut->toDateString());
        $this->assertSame('2027-01-01', $periode->debutPrecedente->toDateString());
        $this->assertSame('2027-12-31', $periode->finPrecedente->toDateString());
        $this->assertSame('month', $periode->granulariteSql());
    }

    public function test_la_granularite_est_journaliere_hors_periode_annee(): void
    {
        $periode = new PeriodeStatistique('30j');

        $this->assertSame('day', $periode->granulariteSql());
    }

    public function test_variation_pourcentage_cas_normal(): void
    {
        $this->assertSame(50.0, PeriodeStatistique::variationPourcentage(15, 10));
    }

    public function test_variation_pourcentage_negative(): void
    {
        $this->assertSame(-50.0, PeriodeStatistique::variationPourcentage(5, 10));
    }

    public function test_variation_pourcentage_sans_precedent_et_sans_actuel_est_indeterminee(): void
    {
        $this->assertNull(PeriodeStatistique::variationPourcentage(0, 0));
    }

    public function test_variation_pourcentage_sans_precedent_mais_avec_un_actuel_positif_est_cent_pourcent(): void
    {
        $this->assertSame(100.0, PeriodeStatistique::variationPourcentage(5, 0));
    }

    public function test_variation_pourcentage_est_arrondie_a_une_decimale(): void
    {
        $this->assertSame(33.3, PeriodeStatistique::variationPourcentage(4, 3));
    }
}
