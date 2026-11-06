<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Un fichier suivi par Git ne porte aucun mot de passe : les tests le reçoivent par l'environnement
 * (variables de la CI, ou .env.testing en local, voir .env.testing.example).
 */
class AucunMotDePasseDansPhpunitTest extends TestCase
{
    public function test_phpunit_xml_ne_contient_aucun_mot_de_passe_de_base(): void
    {
        $this->assertStringNotContainsString('name="DB_PASSWORD"', (string) file_get_contents(__DIR__.'/../../phpunit.xml'));
    }
}
