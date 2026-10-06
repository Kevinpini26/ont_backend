<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class UserInitialesReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_initiales_sont_optionnelles_et_normalisees_par_ladministration(): void
    {
        $admin = User::factory()->administrateur()->create();
        $sansInitiales = $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Agent sans signature',
            'email' => 'agent.sans.initiales@ont.test',
            'password' => 'Xk9mQprT4vLw#26',
            'role' => UserRole::AGENT_DFP->value,
        ])->assertCreated()->assertJsonPath('data.initiales_reference', null);

        $direction = Direction::factory()->create(['est_operationnelle' => true, 'actif' => true]);
        $directeur = $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Yombo Mukendi Julie',
            'email' => 'directeur.initiales@ont.test',
            'password' => 'Xk9mQprT4vLw#26',
            'role' => UserRole::DIRECTEUR_DIRECTION->value,
            'direction_id' => $direction->id,
            'initiales_reference' => '  yMj  ',
        ])->assertCreated()->assertJsonPath('data.initiales_reference', 'YMJ');

        $courrier = Courrier::factory()->create(['reference_documentaire' => '001/ONT/DMC/2026']);
        $this->actingAs($admin)->putJson('/api/v1/users/'.$directeur->json('data.id'), [
            'initiales_reference' => ' abc ',
        ])->assertOk()->assertJsonPath('data.initiales_reference', 'ABC');

        $this->assertNull(User::query()->findOrFail($sansInitiales->json('data.id'))->initiales_reference);
        $this->assertSame('ABC', User::query()->findOrFail($directeur->json('data.id'))->initiales_reference);
        $this->assertSame('001/ONT/DMC/2026', $courrier->fresh()->reference_documentaire);
    }

    public function test_initiales_de_forme_invalide_sont_refusees(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Directeur invalide',
            'email' => 'directeur.invalide@ont.test',
            'password' => 'Xk9mQprT4vLw#26',
            'role' => UserRole::AGENT_DFP->value,
            'initiales_reference' => 'YMJ/1',
        ])->assertUnprocessable()->assertJsonValidationErrors('initiales_reference');
    }

    public function test_un_non_administrateur_ne_peut_pas_modifier_les_initiales(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::AGENT_DFP,
            'initiales_reference' => 'ABC',
        ]);

        $this->actingAs($user)->putJson('/api/v1/users/'.$user->id, [
            'initiales_reference' => 'XYZ',
        ])->assertForbidden();

        $this->assertSame('ABC', $user->fresh()->initiales_reference);
    }
}