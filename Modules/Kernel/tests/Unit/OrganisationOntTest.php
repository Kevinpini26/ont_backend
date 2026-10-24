<?php

namespace Modules\Kernel\Tests\Unit;

use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class OrganisationOntTest extends TestCase
{
    public function test_le_nouveau_role_et_la_valeur_historique_identifient_un_directeur(): void
    {
        $this->assertTrue(UserRole::DIRECTEUR_DIRECTION->estDirecteurDirection());
        $this->assertTrue(UserRole::RESPONSABLE_DIRECTION->estDirecteurDirection());
        $this->assertFalse(UserRole::SECRETARIAT_DIRECTION->estDirecteurDirection());
    }

    public function test_les_seuls_postes_non_operationnels_sont_les_postes_historiques(): void
    {
        $historiques = array_values(array_map(
            fn (Poste $poste) => $poste->value,
            array_filter(Poste::cases(), fn (Poste $poste) => $poste->estHistorique()),
        ));

        $this->assertEqualsCanonicalizing(['protocole', 'assistant_protocole'], $historiques);
        $this->assertEqualsCanonicalizing(
            ['reception', 'secretariat_1', 'secretariat_2', 'dg', 'assistant_1', 'assistant_2', 'dga', 'assistant_dga'],
            array_values(array_map(
                fn (Poste $poste) => $poste->value,
                array_filter(Poste::cases(), fn (Poste $poste) => ! $poste->estHistorique()),
            )),
        );
    }

    public function test_seuls_dg_dga_secretariat_1_secretariat_2_et_reception_sont_des_postes_uniques(): void
    {
        $uniques = array_values(array_map(
            fn (Poste $poste) => $poste->value,
            array_filter(Poste::cases(), fn (Poste $poste) => $poste->estUnique()),
        ));

        $this->assertEqualsCanonicalizing(['dg', 'dga', 'secretariat_1', 'secretariat_2', 'reception'], $uniques);
    }
}
