<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Services\DirecteurDirectionResolver;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class DirecteurDirectionResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_directeur_desactive_est_ignore_apres_remplacement(): void
    {
        $direction = Direction::factory()->create();
        User::factory()->directeurDirection($direction)->create(['actif' => false]);
        $nouveau = User::factory()->directeurDirection($direction)->create();

        $this->assertTrue(app(DirecteurDirectionResolver::class)->resolve($direction->id)->is($nouveau));
    }

    public function test_un_responsable_desactive_ne_compte_pas_comme_directeur(): void
    {
        $direction = Direction::factory()->create();
        User::factory()->responsableDirection($direction)->create(['actif' => false]);

        $this->expectException(ValidationException::class);

        app(DirecteurDirectionResolver::class)->resolve($direction->id);
    }
}
