<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallationDocTest extends TestCase
{
    public function test_la_generation_de_cle_ne_depend_pas_de_vendor_dans_le_guide_dinstallation(): void
    {
        $guide = file_get_contents(base_path('INSTALLATION.md'));

        $this->assertStringNotContainsString('php artisan key:generate --show', $guide, 'artisan exige vendor, absent d\'un clone neuf');
        $this->assertStringContainsString('random_bytes(32)', $guide);
    }
}
