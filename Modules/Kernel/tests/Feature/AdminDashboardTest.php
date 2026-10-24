<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Enums\StatutNotificationEmail;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\NotificationEmail;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_non_administrateur_ne_peut_pas_consulter_le_tableau_de_bord(): void
    {
        $responsable = User::factory()->responsableDirection(Direction::factory()->create())->create();

        $this->actingAs($responsable)->getJson('/api/v1/admin/tableau-de-bord')->assertStatus(403);
    }

    public function test_le_tableau_de_bord_compte_comptes_actifs_verrouilles_et_echecs_du_jour(): void
    {
        $admin = User::factory()->administrateur()->create();
        User::factory()->agentDfp()->create(['actif' => true]);
        User::factory()->agentDfp()->create(['actif' => false]);
        User::factory()->agentDfp()->create(['verrouille_jusqu_a' => now()->addMinutes(10)]);
        NotificationEmail::query()->create([
            'type' => 'test', 'destinataire' => 'x@example.test', 'statut' => StatutNotificationEmail::ECHEC,
        ]);
        // updated_at est géré automatiquement par Eloquent à la création
        // (pas de $timestamps = false sur ce modèle, contrairement à
        // AuditLog) : la valeur passée directement à create() serait
        // écrasée par l'horodatage courant, d'où la mise à jour en deux
        // temps ci-dessous pour obtenir une entrée "d'il y a 3 jours".
        $ancienne = NotificationEmail::query()->create([
            'type' => 'test', 'destinataire' => 'y@example.test', 'statut' => StatutNotificationEmail::ECHEC,
        ]);
        $ancienne->timestamps = false;
        $ancienne->updated_at = now()->subDays(3);
        $ancienne->save();

        $reponse = $this->actingAs($admin)->getJson('/api/v1/admin/tableau-de-bord')->assertOk();

        // admin + le compte actif créé ci-dessus + le compte verrouillé (lui aussi actif par défaut).
        $this->assertSame(3, $reponse->json('comptes_actifs'));
        $this->assertSame(1, $reponse->json('comptes_verrouilles'));
        $this->assertSame(1, $reponse->json('emails_en_echec_aujourdhui'));
        $reponse->assertJsonStructure(['jobs_en_echec', 'derniere_sauvegarde', 'dernieres_actions_sensibles']);
    }
}
