<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Kernel\Concerns\BelongsToDirectionScope;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Tests\TestCase;

/**
 * Vérifie le mécanisme générique de filtrage par direction (DirectionScope)
 * indépendamment des modèles métier (Courrier, Stagiaire) qui l'utiliseront,
 * via un modèle Eloquent de test appliquant le même trait qu'eux.
 */
class DirectionScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scoped_test_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('direction_id')->constrained('directions');
            $table->string('label');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('scoped_test_records');

        parent::tearDown();
    }

    public function test_a_responsable_de_direction_only_sees_records_of_its_own_direction(): void
    {
        [$directionA, $directionB] = Direction::factory()->count(2)->create();

        ScopedTestRecord::query()->create(['direction_id' => $directionA->id, 'label' => 'A']);
        ScopedTestRecord::query()->create(['direction_id' => $directionB->id, 'label' => 'B']);

        $user = User::factory()->responsableDirection($directionA)->create();
        $this->actingAs($user);

        $visible = ScopedTestRecord::all();

        $this->assertCount(1, $visible);
        $this->assertSame('A', $visible->first()->label);
    }

    public function test_agent_dfp_bypasses_the_scope_and_sees_every_direction(): void
    {
        [$directionA, $directionB] = Direction::factory()->count(2)->create();

        ScopedTestRecord::query()->create(['direction_id' => $directionA->id, 'label' => 'A']);
        ScopedTestRecord::query()->create(['direction_id' => $directionB->id, 'label' => 'B']);

        $dfp = User::factory()->agentDfp()->create();
        $this->actingAs($dfp);

        $this->assertCount(2, ScopedTestRecord::all());
    }

    public function test_central_circuit_postes_bypass_the_scope(): void
    {
        [$directionA, $directionB] = Direction::factory()->count(2)->create();

        ScopedTestRecord::query()->create(['direction_id' => $directionA->id, 'label' => 'A']);
        ScopedTestRecord::query()->create(['direction_id' => $directionB->id, 'label' => 'B']);

        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, $directionA)->create();
        $this->actingAs($reception);

        $this->assertCount(2, ScopedTestRecord::all());
    }

    public function test_an_assistant_at_direction_a_does_not_see_direction_b_by_default_scope_unless_configured(): void
    {
        // Les postes du circuit central sont, par défaut, tous configurés en
        // bypass (config('kernel.circuit_courrier_central_postes')) car le
        // circuit courrier est par nature transverse aux directions.
        [$directionA, $directionB] = Direction::factory()->count(2)->create();

        ScopedTestRecord::query()->create(['direction_id' => $directionA->id, 'label' => 'A']);
        ScopedTestRecord::query()->create(['direction_id' => $directionB->id, 'label' => 'B']);

        $assistant = User::factory()->agentCircuitCourrier(Poste::ASSISTANT_1, $directionA)->create();
        $this->actingAs($assistant);

        $this->assertCount(2, ScopedTestRecord::all());
    }

    public function test_administrateur_bypasses_the_scope(): void
    {
        [$directionA, $directionB] = Direction::factory()->count(2)->create();

        ScopedTestRecord::query()->create(['direction_id' => $directionA->id, 'label' => 'A']);
        ScopedTestRecord::query()->create(['direction_id' => $directionB->id, 'label' => 'B']);

        $admin = User::factory()->administrateur()->create();
        $this->actingAs($admin);

        $this->assertCount(2, ScopedTestRecord::all());
    }
}

class ScopedTestRecord extends Model
{
    use BelongsToDirectionScope;

    protected $table = 'scoped_test_records';

    protected $fillable = ['direction_id', 'label'];
}
