<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Console\VerifierEcheancesStageCommand;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Mail\StageEcheanceProcheMail;
use Modules\Stagiaires\Mail\StagiaireAffecteMail;
use Modules\Stagiaires\Models\Stagiaire;

class NotificationsEmailTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_laffectation_met_lenvoi_de_lemail_en_file_dattente(): void
    {
        Mail::fake();

        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create(['actif' => true]);
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $this->affecterViaTableau($stagiaire, $direction, $dfp)->assertOk();

        Mail::assertQueued(StagiaireAffecteMail::class, fn ($mail) => $mail->hasTo($responsable->email));
    }

    public function test_lalerte_decheance_met_lenvoi_de_lemail_en_file_dattente(): void
    {
        Mail::fake();

        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        Stagiaire::factory()->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'direction_id' => $direction->id,
            'date_fin_stage' => now()->addDays(10),
        ]);

        $this->artisan(VerifierEcheancesStageCommand::class)->assertSuccessful();

        Mail::assertQueued(StageEcheanceProcheMail::class, fn ($mail) => $mail->hasTo($dfp->email));
        Mail::assertQueued(StageEcheanceProcheMail::class, fn ($mail) => $mail->hasTo($responsable->email));
    }
}
