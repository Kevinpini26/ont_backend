<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class OrganisationPhase1Test extends TestCase
{
    use RefreshDatabase;

    public function test_un_directeur_et_un_secretariat_sont_rattaches_a_une_direction_operationnelle(): void
    {
        $admin = User::factory()->administrateur()->create();
        $direction = Direction::factory()->create(['est_operationnelle' => true, 'actif' => true]);

        foreach ([UserRole::DIRECTEUR_DIRECTION, UserRole::SECRETARIAT_DIRECTION] as $role) {
            $this->actingAs($admin)->postJson('/api/v1/users', [
                'name' => $role->label(),
                'email' => $role->value.'@ont.test',
                'password' => 'Xk9mQprT4vLw#26',
                'role' => $role->value,
                'direction_id' => $direction->id,
            ])->assertCreated();
        }
    }

    public function test_une_fonction_directionnelle_ne_peut_pas_etre_rattachee_a_la_direction_generale(): void
    {
        $admin = User::factory()->administrateur()->create();
        $directionGenerale = Direction::query()->where('code', 'DG')->firstOrFail();

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Directeur institutionnel invalide',
            'email' => 'directeur.dg@ont.test',
            'password' => 'Xk9mQprT4vLw#26',
            'role' => UserRole::DIRECTEUR_DIRECTION->value,
            'direction_id' => $directionGenerale->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('direction_id');
    }

    public function test_un_ancien_compte_responsable_reste_reconnu_comme_directeur(): void
    {
        $ancienCompte = User::factory()->create(['role' => UserRole::RESPONSABLE_DIRECTION]);

        $this->assertTrue($ancienCompte->role->estDirecteurDirection());
    }

    public function test_lancienne_valeur_api_est_convertie_vers_le_nouveau_role(): void
    {
        $admin = User::factory()->administrateur()->create();
        $direction = Direction::factory()->create(['est_operationnelle' => true]);

        $reponse = $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Directeur compatible',
            'email' => 'directeur.compatible@ont.test',
            'password' => 'Xk9mQprT4vLw#26',
            'role' => UserRole::RESPONSABLE_DIRECTION->value,
            'direction_id' => $direction->id,
        ])->assertCreated();

        $this->assertSame(
            UserRole::DIRECTEUR_DIRECTION,
            User::query()->findOrFail($reponse->json('data.id'))->role,
        );
    }
}
