<?php

namespace Tests\Feature;

use Tests\TestCase;

class LimitesEnvoiCoherentesTest extends TestCase
{
    private function enOctets(string $valeur): int
    {
        $n = (int) $valeur;

        return match (strtoupper(substr(trim($valeur), -1))) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    }

    public function test_nginx_php_et_laravel_acceptent_la_meme_taille_maximale(): void
    {
        $ini = parse_ini_file(base_path('docker/php/ont-limites.ini'));
        $nginx = file_get_contents(base_path('docker/nginx.conf'));
        preg_match('/client_max_body_size\s+(\d+[KMG]?);/i', $nginx, $m);

        $plusGrosFichierLaravel = max(
            (int) config('courrier.signature_physique.taille_max_scan_ko', 10240),
            10240,
        ) * 1024;

        $this->assertNotEmpty($m, 'client_max_body_size absent de docker/nginx.conf');
        $this->assertGreaterThanOrEqual($this->enOctets($ini['post_max_size']), $this->enOctets($m[1]));
        $this->assertGreaterThanOrEqual($this->enOctets($ini['upload_max_filesize']), $this->enOctets($ini['post_max_size']));
        $this->assertGreaterThanOrEqual($plusGrosFichierLaravel, $this->enOctets($ini['upload_max_filesize']));
        $this->assertGreaterThanOrEqual(256, (int) $ini['memory_limit']);
    }

    public function test_le_dockerfile_de_production_installe_les_limites_php(): void
    {
        $this->assertStringContainsString('docker/php/ont-limites.ini', file_get_contents(base_path('Dockerfile.production')));
    }
}
