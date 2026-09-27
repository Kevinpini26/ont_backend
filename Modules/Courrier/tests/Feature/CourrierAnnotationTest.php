<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class CourrierAnnotationTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_un_utilisateur_qui_voit_le_courrier_peut_lannoter_et_laction_est_tracee(): void
    {
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create([
            'direction_origine_id' => $direction->id,
            'direction_destination_id' => null,
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson("/api/v1/courriers/{$courrier->id}/annotations", ['contenu' => 'À vérifier'])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'courrier.annotation_creee',
            'auditable_id' => $courrier->id,
            'user_id' => $agent->id,
        ]);

        $trace = AuditLog::query()->where('action', 'courrier.annotation_creee')->sole();
        $this->assertNotNull($trace->created_at);
        $this->assertSame(mb_strlen('À vérifier'), $trace->meta['contenu_longueur']);
        $this->assertArrayNotHasKey('contenu', $trace->meta);
    }

    public function test_une_direction_sans_acces_ne_peut_pas_annoter_un_courrier(): void
    {
        $directionAutorisee = Direction::factory()->create();
        $autreDirection = Direction::factory()->create();
        $agent = User::factory()->responsableDirection($autreDirection)->create();
        $courrier = Courrier::factory()->create([
            'direction_origine_id' => $directionAutorisee->id,
            'direction_destination_id' => null,
        ]);

        $this->actingAs($agent)
            ->postJson("/api/v1/courriers/{$courrier->id}/annotations", ['contenu' => 'Intrusion'])
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'courrier.annotation_creee',
            'auditable_id' => $courrier->id,
        ]);
    }
}
