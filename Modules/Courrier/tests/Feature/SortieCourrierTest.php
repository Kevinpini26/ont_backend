<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Enums\ModeSortie;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Mail\ReponseFinaleCourrierExterneMail;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class SortieCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_selection_et_email_exigent_decharge_pdf_final_intact_et_adresse_officielle(): void
    {
        Storage::fake('local');
        Mail::fake();
        [$courrier, $sec2, $dg, , $contenuPdf] = $this->courrierSigne();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::COURRIEL->value,
        ])->assertForbidden();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/envoyer-par-courriel")->assertUnprocessable();
        $this->assertNull($courrier->fresh()->courriel_envoye_at);
        Mail::assertNothingQueued();

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::COURRIEL->value,
        ])->assertOk()->assertJsonPath('data.mode_sortie', ModeSortie::COURRIEL->value);
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::RETRAIT_PHYSIQUE->value,
        ])->assertUnprocessable();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/envoyer", [
            'destinataire_externe_nom' => 'Destinataire officiel',
            'destinataire_externe_email' => 'destinataire@example.test',
            'mode_expedition' => 'courriel',
        ])->assertUnprocessable();
        Mail::assertNothingQueued();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/envoyer-par-courriel")
            ->assertOk()->assertJsonPath('data.statut', CourrierStatut::ENVOYE->value);

        $courrier->refresh();
        $this->assertNotNull($courrier->courriel_envoye_at);
        $this->assertSame($sec2->id, $courrier->courriel_envoye_par_id);
        $this->assertSame('destinataire@example.test', $courrier->courriel_destinataire);
        $this->assertSame(CourrierStatut::ENVOYE, $courrier->statut);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'courrier.courriel_envoye',
            'auditable_id' => $courrier->id,
            'user_id' => $sec2->id,
        ]);
        Mail::assertQueued(ReponseFinaleCourrierExterneMail::class, 1);
        $url = null;
        Mail::assertQueued(ReponseFinaleCourrierExterneMail::class, function ($mail) use (&$url): bool {
            $url = $mail->urlTelechargement;

            return $mail->hasTo('destinataire@example.test');
        });
        $this->get($url)->assertOk()->assertStreamedContent($contenuPdf);
        $this->assertSame(hash('sha256', $contenuPdf), $courrier->pdf_sha256);

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/envoyer-par-courriel")->assertUnprocessable();
        Mail::assertQueued(ReponseFinaleCourrierExterneMail::class, 1);
    }

    public function test_selection_email_refuse_adresse_absente_pdf_absent_ou_hash_altere(): void
    {
        Storage::fake('local');
        Mail::fake();
        [$sansAdresse, $sec2] = $this->courrierSigne(email: null);
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$sansAdresse->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::COURRIEL->value,
        ])->assertUnprocessable();

        [$sansPdf, $sec2SansPdf] = $this->courrierSigne(pdf: null);
        $this->actingAs($sec2SansPdf)->postJson("/api/v1/courriers/{$sansPdf->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::COURRIEL->value,
        ])->assertUnprocessable();

        [$corrompu, $sec2Corrompu] = $this->courrierSigne();
        Storage::disk('local')->put($corrompu->pdf_chemin, 'PDF altéré');
        $this->actingAs($sec2Corrompu)->postJson("/api/v1/courriers/{$corrompu->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::COURRIEL->value,
        ])->assertUnprocessable();
        $this->assertNull($corrompu->fresh()->mode_sortie);
        Mail::assertNothingQueued();
    }

    public function test_hash_altere_apres_choix_bloque_lenvoi_et_ne_declenche_aucun_email(): void
    {
        Storage::fake('local');
        Mail::fake();
        [$courrier, $sec2] = $this->courrierSigne();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::COURRIEL->value,
        ])->assertOk();
        Storage::disk('local')->put($courrier->pdf_chemin, 'PDF final altéré après le choix');

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/envoyer-par-courriel")->assertUnprocessable();
        $courrier->refresh();
        $this->assertSame(CourrierStatut::SIGNE, $courrier->statut);
        $this->assertNull($courrier->courriel_envoye_at);
        $this->assertSame(0, $courrier->transitions()->where('statut', CourrierStatut::ENVOYE)->count());
        Mail::assertNothingQueued();
    }

    public function test_retrait_seul_est_disponible_avant_remise_et_ne_declenche_aucun_email(): void
    {
        Storage::fake('local');
        Mail::fake();
        [$courrier, $sec2, , , $contenuPdf] = $this->courrierSigne(
            origineMode: ModeReception::DEPOT_EN_LIGNE,
            origineEmail: 'portail@example.test',
        );
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::RETRAIT_PHYSIQUE->value,
        ])->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/rendre-disponible-retrait", [
            'observation' => 'Guichet SEC2',
        ])->assertOk()->assertJsonPath('data.statut', CourrierStatut::DISPONIBLE_RETRAIT->value);

        $courrier->refresh();
        $this->assertNotNull($courrier->retrait_disponible_at);
        $this->assertSame($sec2->id, $courrier->retrait_disponible_par_id);
        $this->assertNull($courrier->remis_le);
        $this->assertNull($courrier->remis_a);
        $this->assertNull($courrier->courriel_envoye_at);
        $this->assertNull($courrier->date_envoi);
        $this->assertSame($contenuPdf, Storage::disk('local')->get($courrier->pdf_chemin));
        Mail::assertNothingQueued();

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/rendre-disponible-retrait")->assertUnprocessable();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/confirmer-remise-physique", [
            'remis_a' => 'Marie Tshibangu',
            'observation' => 'Pièce contrôlée',
            'decharge_remise' => UploadedFile::fake()->create('decharge.pdf', 10, 'application/pdf'),
        ])->assertOk()->assertJsonPath('data.statut', CourrierStatut::REMIS->value);

        $courrier->refresh();
        $this->assertNotNull($courrier->remis_le);
        $this->assertSame('Marie Tshibangu', $courrier->remis_a);
        $this->assertSame($sec2->id, $courrier->retrait_effectue_par_id);
        $this->assertSame('Pièce contrôlée', $courrier->retrait_observation);
        $this->assertNotNull($courrier->decharge_remise_chemin);
        $this->assertSame(CourrierStatut::REMIS, $courrier->statut);
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/confirmer-remise-physique", [
            'remis_a' => 'Autre personne',
        ])->assertUnprocessable();
        $this->assertSame('Marie Tshibangu', $courrier->fresh()->remis_a);
        Mail::assertNothingQueued();
    }

    public function test_courriel_et_retrait_evoluent_independamment_dans_les_deux_ordres(): void
    {
        Storage::fake('local');
        Mail::fake();
        [$courrier, $sec2] = $this->courrierSigne(origineMode: ModeReception::PORTEUR, origineEmail: null);
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::COURRIEL_ET_RETRAIT->value,
        ])->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/rendre-disponible-retrait")->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/envoyer-par-courriel")
            ->assertOk()->assertJsonPath('data.statut', CourrierStatut::ENVOYE->value);

        $courrier->refresh();
        $this->assertNotNull($courrier->courriel_envoye_at);
        $this->assertNotNull($courrier->retrait_disponible_at);
        $this->assertNull($courrier->remis_le);
        $this->assertSame(CourrierStatut::ENVOYE, $courrier->statut);
        $this->assertSame(1, $courrier->transitions()->where('statut', CourrierStatut::ENVOYE)->count());
        $this->actingAs($sec2)->getJson('/api/v1/courriers?file_sorties_sec2=1')->assertJsonFragment(['id' => $courrier->id]);

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/confirmer-remise-physique", [
            'remis_a' => 'Jean Ilunga',
        ])->assertOk()->assertJsonPath('data.statut', CourrierStatut::REMIS->value);
        $courrier->refresh();
        $this->assertNotNull($courrier->courriel_envoye_at);
        $this->assertSame('destinataire@example.test', $courrier->courriel_destinataire);
        $this->assertNotNull($courrier->remis_le);
        $this->assertSame('Jean Ilunga', $courrier->remis_a);
        $this->assertSame(CourrierStatut::REMIS, $courrier->statut);
        $this->assertSame(1, $courrier->transitions()->where('statut', CourrierStatut::REMIS)->count());
        $this->actingAs($sec2)->getJson('/api/v1/courriers?file_sorties_sec2=1')->assertJsonMissing(['id' => $courrier->id]);
        Mail::assertQueued(ReponseFinaleCourrierExterneMail::class, 1);
    }

    public function test_email_en_premier_puis_retrait_garde_le_canal_physique_independant(): void
    {
        Storage::fake('local');
        Mail::fake();
        [$courrier, $sec2] = $this->courrierSigne(origineMode: ModeReception::PORTEUR, origineEmail: null);
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::COURRIEL_ET_RETRAIT->value,
        ])->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/envoyer-par-courriel")->assertOk();
        $this->assertNull($courrier->fresh()->retrait_disponible_at);

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/rendre-disponible-retrait")->assertOk();
        $courrier->refresh();
        $this->assertSame(CourrierStatut::ENVOYE, $courrier->statut);
        $this->assertNotNull($courrier->courriel_envoye_at);
        $this->assertNotNull($courrier->retrait_disponible_at);
        $this->assertNull($courrier->remis_le);

        $url = URL::temporarySignedRoute('api.public.reponses.telecharger', now()->addMinute(), ['courrier' => $courrier->id]);
        $this->get($url)->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/enregistrer-remise", [
            'remis_a' => 'Jean Ilunga',
            'mode_remise' => 'porteur_avec_decharge',
        ])->assertUnprocessable();
        $this->assertNull($courrier->fresh()->remis_le);

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/confirmer-remise-physique", [
            'remis_a' => 'Jean Ilunga',
        ])->assertOk()->assertJsonPath('data.statut', CourrierStatut::REMIS->value);
        $courrier->refresh();
        $this->assertNotNull($courrier->courriel_envoye_at);
        $this->assertSame('destinataire@example.test', $courrier->courriel_destinataire);
        $this->assertNotNull($courrier->remis_le);
        $this->assertSame('Jean Ilunga', $courrier->remis_a);
        Mail::assertQueued(ReponseFinaleCourrierExterneMail::class, 1);
    }

    public function test_canal_email_seul_pour_courrier_physique_est_explicite_et_non_substituable(): void
    {
        Storage::fake('local');
        Mail::fake();
        [$courrier, $sec2] = $this->courrierSigne(
            origineMode: ModeReception::PORTEUR,
            origineEmail: null,
            email: 'nouveau.destinataire@example.test',
        );
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::COURRIEL->value,
        ])->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/envoyer-par-courriel")->assertOk();

        $url = null;
        Mail::assertQueued(ReponseFinaleCourrierExterneMail::class, function ($mail) use (&$url): bool {
            $url = $mail->urlTelechargement;

            return $mail->hasTo('nouveau.destinataire@example.test');
        });
        $this->get($url)->assertOk();
        $this->assertNotNull($courrier->fresh()->courriel_envoye_at);
        $this->assertSame('nouveau.destinataire@example.test', $courrier->fresh()->courriel_destinataire);
    }

    public function test_roles_non_sec2_ne_peuvent_ni_choisir_ni_executer_les_sorties(): void
    {
        Storage::fake('local');
        [$courrier, , $dg, $direction] = $this->courrierSigne();
        $admin = User::factory()->administrateur()->create();
        foreach ([$dg, $admin, $this->agent(Poste::SECRETARIAT_1, $direction)] as $acteur) {
            $reponse = $this->actingAs($acteur)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
                'mode_sortie' => ModeSortie::RETRAIT_PHYSIQUE->value,
            ]);
            $this->assertContains($reponse->status(), [403, 404]);
        }
    }

    public function test_un_lien_public_nest_pas_ouvert_avant_canal_email_et_le_mode_retrait_seul_ne_le_debloque_pas(): void
    {
        Storage::fake('local');
        [$courrier, $sec2] = $this->courrierSigne(
            origineMode: ModeReception::DEPOT_EN_LIGNE,
            origineEmail: 'portail@example.test',
        );
        $url = URL::temporarySignedRoute('api.public.reponses.telecharger', now()->addMinute(), ['courrier' => $courrier->id]);
        $this->get($url)->assertNotFound();

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::RETRAIT_PHYSIQUE->value,
        ])->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/rendre-disponible-retrait")->assertOk();
        $this->get($url)->assertNotFound();
    }

    private function courrierSigne(
        ?string $email = 'destinataire@example.test',
        ?string $pdf = "%PDF-1.7\nScan final valide\n%%EOF",
        ModeReception $origineMode = ModeReception::DEPOT_EN_LIGNE,
        ?string $origineEmail = 'destinataire@example.test',
        bool $decharge = true,
    ): array {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $source = Courrier::factory()->create([
            'sens' => SensCourrier::ENTRANT,
            'mode_reception' => $origineMode,
            'expediteur_externe_nom' => 'Partenaire source',
            'expediteur_externe_email' => $origineEmail,
        ]);
        $cheminPdf = $pdf === null ? null : 'courriers-signes/sortie-phase3.pdf';
        if ($cheminPdf !== null) {
            Storage::disk('local')->put($cheminPdf, $pdf);
        }
        $courrier = Courrier::factory()->create([
            'dossier_id' => $source->dossier_id,
            'sens' => SensCourrier::SORTANT,
            'statut' => CourrierStatut::SIGNE,
            'en_reponse_a_courrier_id' => $source->id,
            'destinataire_externe_nom' => 'Destinataire officiel',
            'destinataire_externe_email' => $email,
            'numero_depart' => '2026-D'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'signataire_id' => $dg->id,
            'signe_at' => now()->subMinute(),
            'relecture_validee_at' => now()->subMinutes(2),
            'pdf_chemin' => $cheminPdf,
            'pdf_sha256' => $pdf === null ? null : hash('sha256', $pdf),
        ]);
        $courrier->transitions()->create([
            'statut' => CourrierStatut::SIGNE,
            'ancien_statut' => CourrierStatut::EN_RELECTURE,
            'nouveau_statut' => CourrierStatut::SIGNE,
            'changed_by_id' => $dg->id,
            'destinataire_poste' => Poste::SECRETARIAT_2->value,
            'accuse_reception_at' => $decharge ? now() : null,
            'created_at' => now(),
        ]);

        return [$courrier, $sec2, $dg, $direction, $pdf];
    }
}
