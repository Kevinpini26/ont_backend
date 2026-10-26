<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Notifications\CourrierDgNotification;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Schéma ONT : DG ↔ DGA (+ assistant DGA). Le DG confie un dossier au DGA
 * « pour instruction » ; le DGA l'instruit puis le retourne au DG avec une
 * recommandation. Le DG reste le décideur.
 */
class ConfiageDgaTest extends CourrierTestCase
{
    use RefreshDatabase;

    /** @return array{User, User, User, User, Courrier} */
    private function contexte(): array
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $dga = $this->agent(Poste::DGA, $direction);
        $assistantDga = $this->agent(Poste::ASSISTANT_DGA, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        return [$dg, $dga, $assistantDga, $this->agent(Poste::SECRETARIAT_1, $direction), $courrier];
    }

    public function test_le_dg_confie_au_dga_qui_instruit_avec_son_assistant_puis_retourne_au_dg(): void
    {
        [$dg, $dga, $assistantDga, , $courrier] = $this->contexte();

        $this->actingAs($dga)->getJson("/api/v1/courriers/{$courrier->id}")->assertNotFound();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/confier-au-dga", ['consigne' => 'Analyser ce partenariat.'])
            ->assertOk()
            ->assertJsonPath('data.confiage_dga.statut', 'en_cours')
            ->assertJsonPath('data.confiage_dga.consigne', 'Analyser ce partenariat.')
            ->assertJsonPath('data.peut_confier_au_dga', false)
            ->assertJsonPath('data.peut_reprendre_du_dga', true);
        Notification::assertSentTo($dga, CourrierDgNotification::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'courrier.confie_au_dga', 'auditable_id' => $courrier->id, 'user_id' => $dg->id]);

        $this->actingAs($dga)->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertOk()
            ->assertJsonPath('data.peut_retourner_au_dg', true);

        $this->actingAs($dga)->postJson("/api/v1/courriers/{$courrier->id}/missions", [
            'assistant_id' => $assistantDga->id,
            'instruction' => 'Préparer une note d’analyse.',
        ])->assertCreated()->assertJsonPath('data.autorite_poste', Poste::DGA->value);

        $this->actingAs($dga)->postJson("/api/v1/courriers/{$courrier->id}/retourner-au-dg", ['recommandation' => 'Avis favorable, à signer.'])
            ->assertOk()
            ->assertJsonPath('data.confiage_dga.statut', 'retourne')
            ->assertJsonPath('data.confiage_dga.recommandation', 'Avis favorable, à signer.')
            ->assertJsonPath('data.peut_retourner_au_dg', false);
        Notification::assertSentTo($dg, CourrierDgNotification::class, fn ($n) => $n->toArray($dg)['evenement'] === 'retour_dga');

        $this->actingAs($dg)->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertOk()
            ->assertJsonPath('data.confiage_dga.recommandation', 'Avis favorable, à signer.')
            ->assertJsonPath('data.peut_confier_au_dga', true);
        $this->assertSame(CourrierStatut::EN_ATTENTE_AVIS_DG, $courrier->fresh()->statut);
    }

    public function test_le_dga_ne_peut_pas_missionner_un_dossier_que_la_dg_ne_lui_a_pas_confie(): void
    {
        [, $dga, $assistantDga, , $courrier] = $this->contexte();
        $courrier->transitions()->create([
            'statut' => $courrier->statut,
            'nouveau_statut' => $courrier->statut,
            'destinataire_poste' => Poste::DGA,
            'created_at' => now(),
        ]);

        $this->actingAs($dga)->postJson("/api/v1/courriers/{$courrier->id}/missions", [
            'assistant_id' => $assistantDga->id,
            'instruction' => 'Sans confiage.',
        ])->assertUnprocessable();
    }

    public function test_seuls_les_bons_postes_peuvent_confier_retourner_ou_reprendre(): void
    {
        [$dg, $dga, , $sec1, $courrier] = $this->contexte();

        // Un poste sans rôle sur ce dossier reçoit 403 (visible) ou 404 (invisible) : dans les deux cas, refusé.
        $refuse = fn (User $u): bool => in_array($this->actingAs($u)->postJson("/api/v1/courriers/{$courrier->id}/confier-au-dga", ['consigne' => 'x'])->status(), [403, 404], true);
        $this->assertTrue($refuse($sec1));
        $this->assertTrue($refuse($dga));
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/retourner-au-dg", ['recommandation' => 'x'])->assertForbidden();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/confier-au-dga", ['consigne' => ''])->assertUnprocessable();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/confier-au-dga", ['consigne' => 'Instruire.'])->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/confier-au-dga", ['consigne' => 'Encore.'])->assertForbidden();
        // SEC1 n'a plus aucun rôle sur ce dossier : refusé (403) ou invisible (404).
        $this->assertContains($this->actingAs($sec1)->postJson("/api/v1/courriers/{$courrier->id}/reprendre-du-dga")->status(), [403, 404]);
    }

    public function test_le_dg_peut_reprendre_un_dossier_confie_et_le_dga_ne_peut_plus_le_retourner(): void
    {
        [$dg, $dga, , , $courrier] = $this->contexte();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/confier-au-dga", ['consigne' => 'Instruire.'])->assertOk();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/reprendre-du-dga")
            ->assertOk()
            ->assertJsonPath('data.confiage_dga.statut', 'repris');

        $this->actingAs($dga)->postJson("/api/v1/courriers/{$courrier->id}/retourner-au-dg", ['recommandation' => 'Trop tard.'])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'courrier.repris_du_dga', 'auditable_id' => $courrier->id]);
    }

    public function test_on_ne_peut_confier_que_un_dossier_en_attente_davis_dg(): void
    {
        [$dg, , , , $courrier] = $this->contexte();
        $courrier->update(['statut' => CourrierStatut::EN_ATTENTE_TRI]);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/confier-au-dga", ['consigne' => 'Instruire.'])->assertForbidden();
    }
}
