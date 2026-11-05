<?php

namespace Tests\Feature;

use Tests\TestCase;

class IntegrationContinueTest extends TestCase
{
    public function test_la_ci_utilise_la_base_exigee_par_les_tests(): void
    {
        $ci = file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertStringContainsString('DB_DATABASE: ont_testing', $ci);
        $this->assertStringContainsString('POSTGRES_DB: ont_testing', $ci);
        $this->assertStringNotContainsString('ont_ci', str_replace('ont_ci_password', '', $ci));
    }

    public function test_la_ci_se_lance_sur_toutes_les_branches_et_les_pull_requests(): void
    {
        $ci = file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertMatchesRegularExpression('/\n  push:\n(?!    branches)/', $ci);
        $this->assertStringContainsString("\n  pull_request:", $ci);
    }
}
