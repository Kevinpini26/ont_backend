<?php

namespace Modules\Courrier\Tests\Unit;

use Illuminate\Support\Facades\Date;
use Modules\Courrier\Contracts\SequenceGenerator;
use Modules\Courrier\Support\DefaultNumeroGenerator;
use Tests\TestCase;

/**
 * Étend Tests\TestCase (pas PHPUnit\Framework\TestCase) uniquement parce
 * que DefaultNumeroGenerator dépend de la façade Date — SequenceGenerator
 * est doublé par un stub maison ci-dessous, aucune base de données
 * impliquée dans ce test.
 */
class DefaultNumeroGeneratorTest extends TestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    private function generateur(int $prochaineSequence): DefaultNumeroGenerator
    {
        $sequences = new class($prochaineSequence) implements SequenceGenerator
        {
            public array $appels = [];

            public function __construct(private readonly int $valeur) {}

            public function suivant(string $cle, int $annee): int
            {
                $this->appels[] = [$cle, $annee];

                return $this->valeur;
            }
        };

        return new DefaultNumeroGenerator($sequences);
    }

    public function test_le_numero_daccuse_de_reception_suit_le_format_ar_annee_sequence(): void
    {
        Date::setTestNow('2026-03-15');

        $numero = $this->generateur(42)->genererAccuseReception();

        $this->assertSame('AR-2026-000042', $numero);
    }

    public function test_le_numero_daccuse_de_reception_complete_toujours_sur_six_chiffres(): void
    {
        Date::setTestNow('2026-01-01');

        $this->assertSame('AR-2026-000001', $this->generateur(1)->genererAccuseReception());
        $this->assertSame('AR-2026-999999', $this->generateur(999999)->genererAccuseReception());
        // Au-delà de 6 chiffres, sprintf n'écrête rien : le numéro s'allonge
        // plutôt que de perdre en unicité.
        $this->assertSame('AR-2026-1000000', $this->generateur(1000000)->genererAccuseReception());
    }

    public function test_le_numero_denregistrement_suit_le_format_annee_sequence_sur_quatre_chiffres(): void
    {
        Date::setTestNow('2026-11-01');

        $this->assertSame('2026-0007', $this->generateur(7)->genererNumeroEnregistrement());
    }

    public function test_lannee_utilisee_est_celle_de_la_date_courante(): void
    {
        Date::setTestNow('2031-12-31');

        $this->assertSame('AR-2031-000001', $this->generateur(1)->genererAccuseReception());
    }

    public function test_la_sequence_est_demandee_pour_la_bonne_cle_et_la_bonne_annee(): void
    {
        Date::setTestNow('2026-06-01');
        $sequences = new class implements SequenceGenerator
        {
            public array $appels = [];

            public function suivant(string $cle, int $annee): int
            {
                $this->appels[] = [$cle, $annee];

                return 1;
            }
        };

        (new DefaultNumeroGenerator($sequences))->genererAccuseReception();
        (new DefaultNumeroGenerator($sequences))->genererNumeroEnregistrement();

        $this->assertSame([['accuse_reception', 2026], ['enregistrement', 2026]], $sequences->appels);
    }
}
