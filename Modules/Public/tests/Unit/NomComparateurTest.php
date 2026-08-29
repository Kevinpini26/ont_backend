<?php

namespace Modules\Public\Tests\Unit;

use Modules\Public\Support\NomComparateur;
use PHPUnit\Framework\TestCase;

class NomComparateurTest extends TestCase
{
    public function test_une_saisie_vide_ne_correspond_jamais(): void
    {
        $this->assertFalse(NomComparateur::correspond('', 'Kabasele Jean'));
    }

    public function test_un_nom_stocke_vide_ou_nul_ne_correspond_jamais(): void
    {
        $this->assertFalse(NomComparateur::correspond('Kabasele', null));
        $this->assertFalse(NomComparateur::correspond('Kabasele', ''));
    }

    public function test_une_correspondance_exacte_est_acceptee(): void
    {
        $this->assertTrue(NomComparateur::correspond('Kabasele Jean', 'Kabasele Jean'));
    }

    public function test_un_seul_mot_significatif_du_nom_complet_suffit(): void
    {
        $this->assertTrue(NomComparateur::correspond('Kabasele', 'KABASELE Jean Pierre'));
    }

    public function test_lordre_des_mots_na_pas_dimportance(): void
    {
        $this->assertTrue(NomComparateur::correspond('Jean Kabasele', 'Kabasele Jean'));
    }

    public function test_la_comparaison_ignore_la_casse(): void
    {
        $this->assertTrue(NomComparateur::correspond('kabasele', 'KABASELE Jean'));
    }

    public function test_la_comparaison_ignore_les_accents(): void
    {
        $this->assertTrue(NomComparateur::correspond('Kabasele Rene', 'Kabasélé René'));
    }

    public function test_les_traits_dunion_et_apostrophes_sont_traites_comme_des_separateurs(): void
    {
        $this->assertTrue(NomComparateur::correspond("Jean-Pierre O'Malanga", 'Jean Pierre O Malanga'));
    }

    public function test_les_espaces_superflus_sont_ignores(): void
    {
        $this->assertTrue(NomComparateur::correspond('  Kabasele   Jean  ', 'Kabasele Jean'));
    }

    public function test_un_mot_trop_court_seul_ne_suffit_pas(): void
    {
        // "Jo" fait moins de 3 caractères : aucun mot significatif, rejeté
        // même s'il correspond littéralement à un des mots stockés.
        $this->assertFalse(NomComparateur::correspond('Jo', 'Jo Kabasele'));
    }

    public function test_un_mot_qui_ne_figure_pas_dans_le_nom_stocke_est_rejete(): void
    {
        $this->assertFalse(NomComparateur::correspond('Kabasele Paul', 'Kabasele Jean'));
    }

    public function test_un_nom_totalement_different_est_rejete(): void
    {
        $this->assertFalse(NomComparateur::correspond('Mukendi Alphonse', 'Kabasele Jean'));
    }
}
