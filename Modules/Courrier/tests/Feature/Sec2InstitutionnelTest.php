<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Mail\ReponseFinaleCourrierExterneMail;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\InstructionCourrierDg;
use Modules\Courrier\Notifications\DispatchCourrierNotification;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class Sec2InstitutionnelTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_classement_generique_refuse_reception_obligatoire_et_archive_immuable(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [['type' => 'classement', 'instruction' => 'Classer']]])->assertOk();
        $dispatch = $courrier->dispatchs()->sole();
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertUnprocessable();
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", ['emplacement' => 'Armoire'])->assertUnprocessable();
        $this->assertDatabaseCount('classements_documents', 0);
        $this->assertSame('en_attente', $dispatch->fresh()->statut->value);
        $this->receptionnerDispatchSec2($dispatch, $sec2);
        $reponse = $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", ['emplacement' => 'Armoire'])->assertOk();
        $this->assertDatabaseCount('classements_documents', 1);
        $this->assertSame('execute', $dispatch->fresh()->statut->value);
        $classementId = $reponse->json('data.id');
        $this->actingAs($sec2)->postJson("/api/v1/classements-documents/{$classementId}/archiver")->assertOk();
        foreach (['cote', 'emplacement', 'observation'] as $champ) {
            $this->actingAs($sec2)->postJson("/api/v1/classements-documents/{$classementId}/corriger", [$champ => 'Modification', 'motif' => 'Test'])->assertUnprocessable();
        }
    }

    public function test_sec2_ne_decide_pas_et_ne_modifie_pas_la_destination(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'created_by' => $sec2->id]);
        $destinations = [['type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Traiter']];
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => $destinations])->assertForbidden();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => $destinations])->assertOk();
        $dispatch = $courrier->dispatchs()->sole();
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertUnprocessable();
        $this->receptionnerDispatchSec2($dispatch, $sec2);
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer", [
            'type_destination' => 'exterieur', 'direction_id' => null, 'instruction' => 'Détournement', 'cycle' => 999,
        ])->assertOk();
        $dispatch->refresh();
        $this->assertSame('direction', $dispatch->type_destination->value);
        $this->assertSame($direction->id, $dispatch->direction_id);
        $this->assertSame('Traiter', $dispatch->instruction);
        $this->assertSame(1, $dispatch->cycle);
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => $destinations])->assertForbidden();
    }

    public function test_d_incomplet_ou_altere_ne_peut_pas_etre_envoye(): void
    {
        Storage::fake('local');
        Mail::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $cas = [
            ['signe_at' => null], ['signataire_id' => null], ['numero_depart' => null],
            ['pdf_chemin' => null], ['pdf_sha256' => null], ['pdf_sha256' => str_repeat('0', 64)],
            ['relecture_validee_at' => null],
            ['signataire_id' => $sec2->id], ['pdf_chemin' => 'courriers/absent.pdf'],
        ];
        foreach ($cas as $index => $alteration) {
            $chemin = "courriers/incomplet-{$index}.pdf";
            Storage::disk('local')->put($chemin, '%PDF document signé');
            $courrier = Courrier::factory()->create(array_merge([
                'statut' => CourrierStatut::SIGNE, 'sens' => SensCourrier::SORTANT,
                'created_by' => $sec2->id, 'signataire_id' => $dg->id, 'signe_at' => now(),
                'relecture_validee_at' => now(), 'numero_depart' => "SEC2-TEST-{$index}",
                'pdf_chemin' => $chemin, 'pdf_sha256' => hash('sha256', '%PDF document signé'),
                'destinataire_externe_nom' => 'Partenaire',
            ], $alteration));
            $this->marquerDecharge($courrier, Poste::SECRETARIAT_2);
            $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/envoyer", ['destinataire_externe_nom' => 'Partenaire', 'mode_expedition' => 'poste'])->assertUnprocessable();
            $this->assertSame(CourrierStatut::SIGNE, $courrier->fresh()->statut);
            $this->assertSame(0, $courrier->transitions()->where('statut', 'envoye')->count());
        }
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_pdf_altere_est_refuse_en_telechargement_authentifie(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::SIGNE, 'created_by' => $sec2->id,
            'signe_at' => now(), 'pdf_chemin' => 'courriers/altere.pdf',
            'pdf_sha256' => hash('sha256', 'original'),
        ]);
        Storage::disk('local')->put('courriers/altere.pdf', 'altéré');
        $this->actingAs($sec2)->getJson("/api/v1/courriers/{$courrier->id}/pdf")->assertUnprocessable();
    }

    public function test_sec2_ne_recoit_pas_les_donnees_de_preparation_et_ne_peut_pas_annoter(): void
    {
        $direction = Direction::factory()->create();
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::SIGNE, 'created_by' => $sec2->id,
            'projet_reponse_contenu' => ['secret' => 'interne'],
            'relecture_commentaire' => 'Relecture privée', 'projet_renvoi_observation' => 'Correction privée',
        ]);
        $this->actingAs($sec2)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk()
            ->assertJsonPath('data.projet_reponse_contenu', null)
            ->assertJsonPath('data.relecture_commentaire', null)
            ->assertJsonPath('data.projet_renvoi_observation', null);
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/annotations", ['contenu' => 'Avis'])->assertForbidden();
    }

    public function test_sec2_etranger_ne_voit_ni_courrier_dossier_annotations_ni_alerte_kpi(): void
    {
        $direction = Direction::factory()->create();
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $etranger = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_TRI, 'objet' => 'Secret étranger SEC2',
            'expediteur_externe_nom' => 'Émetteur étranger', 'numero_enregistrement' => '2026-SEC2-999',
            'reference_documentaire' => 'ONT/SEC2/SECRET/999', 'numero_depart' => 'DEP-SECRET-999',
        ]);
        foreach (["/courriers/{$etranger->id}", "/courriers/{$etranger->id}/annotations", "/courriers/{$etranger->id}/piece-jointe", "/dossiers/{$etranger->dossier_id}"] as $url) {
            $this->actingAs($sec2)->getJson('/api/v1'.$url)->assertNotFound();
        }
        $this->actingAs($sec2)->getJson('/api/v1/courriers')->assertOk()->assertJsonCount(0, 'data');
        foreach ([$etranger->objet, $etranger->expediteur_externe_nom, $etranger->numero_accuse_reception, $etranger->numero_enregistrement, $etranger->reference_documentaire, $etranger->numero_depart] as $recherche) {
            $this->actingAs($sec2)->getJson('/api/v1/courriers?'.http_build_query(['recherche' => $recherche]))->assertOk()->assertJsonCount(0, 'data');
        }
        $kpi = $this->actingAs($sec2)->getJson('/api/v1/courriers/statistiques')->assertOk();
        $this->assertStringNotContainsString($etranger->objet, $kpi->getContent());
        $kpi->assertJsonPath('en_cours_total', 0)->assertJsonPath('courriers_recus_periode', 0);
        $this->assertSame(0, DispatchCourrier::query()->count());
    }

    public function test_sec2_ne_peut_pas_etre_nouveau_relecteur_et_ne_voit_pas_instruction_dg(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $instruction = InstructionCourrierDg::query()->create(['donneur_id' => $dg->id, 'instruction' => 'Ordre DG privé']);
        $this->actingAs($sec2)->getJson("/api/v1/instructions-courrier-dg/{$instruction->id}")->assertNotFound();
        $this->actingAs($sec1)->postJson('/api/v1/courriers/initier-dg', [
            'instruction_courrier_dg_id' => $instruction->id,
            'direction_destination_id' => $direction->id, 'objet' => 'Note',
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => []], 'relecteur_id' => $sec2->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('relecteur_id');
        $this->assertNull($instruction->fresh()->consomme_at);
    }

    public function test_registres_arrivee_et_depart_restent_dans_le_perimetre_sec2(): void
    {
        $direction = Direction::factory()->create();
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $visible = Courrier::factory()->create([
            'created_by' => $sec2->id, 'statut' => CourrierStatut::ENVOYE, 'sens' => SensCourrier::SORTANT,
            'numero_depart' => 'REG-SEC2-1', 'date_envoi' => now(),
        ]);
        Courrier::factory()->create(['statut' => CourrierStatut::RECU]);
        Courrier::factory()->create(['statut' => CourrierStatut::ENVOYE, 'sens' => SensCourrier::SORTANT, 'numero_depart' => 'REG-ETRANGER-2', 'date_envoi' => now()]);
        $this->mock(PdfGenerationService::class)
            ->shouldReceive('genererDepuisVue')->twice()->andReturnUsing(function ($vue, $donnees) use ($visible) {
                $this->assertSame('courrier::registre', $vue);
                $this->assertSame([$visible->id], $donnees['courriers']->pluck('id')->all());

                return '%PDF registre';
            });
        foreach (['arrivee', 'depart'] as $type) {
            $this->actingAs($sec2)->get('/api/v1/courriers/registre?'.http_build_query([
                'type' => $type, 'debut' => now()->subDay()->toDateString(), 'fin' => now()->addDay()->toDateString(),
            ]))->assertOk();
        }
    }

    public function test_nouveau_sortant_conserve_le_destinataire_determine_avant_signature(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $directeur = User::factory()->directeurDirection($direction)->create();
        $relecteur = User::factory()->directeurDirection($direction)->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $id = $this->actingAs($directeur)->postJson('/api/v1/courriers/initier-sortant', [
            'objet' => 'Sortant institutionnel', 'relecteur_id' => $relecteur->id,
            'destinataire_externe_nom' => 'Destinataire signé', 'destinataire_externe_email' => 'signe@example.test',
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Officiel']]]]],
        ])->assertCreated()->json('data.id');
        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();
        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$id}/valider-relecture")->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$id}/signer")->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();
        foreach ([['destinataire_externe_nom' => 'Tiers', 'destinataire_externe_email' => 'signe@example.test'], ['destinataire_externe_nom' => 'Destinataire signé', 'destinataire_externe_email' => 'tiers@example.test']] as $destinataire) {
            $this->actingAs($sec2)->postJson("/api/v1/courriers/{$id}/envoyer", [...$destinataire, 'mode_expedition' => 'courriel'])->assertUnprocessable();
            $this->assertSame(CourrierStatut::SIGNE, Courrier::withoutGlobalScopes()->findOrFail($id)->statut);
        }
        $signe = Courrier::withoutGlobalScopes()->findOrFail($id);
        $pdfOriginal = Storage::disk('local')->get($signe->pdf_chemin);
        Storage::disk('local')->put($signe->pdf_chemin, '%PDF document altéré après signature');
        Mail::fake();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$id}/envoyer", [
            'destinataire_externe_nom' => 'Destinataire signé', 'destinataire_externe_email' => 'signe@example.test', 'mode_expedition' => 'courriel',
        ])->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $this->assertSame(CourrierStatut::SIGNE, $signe->fresh()->statut);
        Mail::assertNothingQueued();
        Storage::disk('local')->put($signe->pdf_chemin, $pdfOriginal);
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$id}/envoyer", [
            'destinataire_externe_nom' => 'Destinataire signé', 'destinataire_externe_email' => 'signe@example.test', 'mode_expedition' => 'courriel',
        ])->assertOk();
    }

    public function test_nouveau_cycle_ne_renotifie_pas_les_dispatchs_historiques(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [['type' => 'exterieur', 'destinataire_externe_nom' => 'Partenaire', 'instruction' => 'Transmettre']]])->assertOk();
        $premier = $courrier->dispatchs()->sole();
        $this->receptionnerDispatchSec2($premier, $sec2);
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$premier->id}/executer", ['reference_transmission' => 'BORD-1'])->assertOk();
        Notification::fake();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [['type' => 'classement', 'instruction' => 'Classer']]])->assertOk();
        $second = $courrier->dispatchs()->where('cycle', 2)->sole();
        Notification::assertSentTo($sec2, DispatchCourrierNotification::class, fn ($notification) => $notification->toArray($sec2)['dispatch_id'] === $second->id);
        Notification::assertSentToTimes($sec2, DispatchCourrierNotification::class, 1);
    }

    public function test_file_archivage_dossiers_est_paginee_et_ne_revele_pas_un_dossier_etranger(): void
    {
        $direction = Direction::factory()->create();
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        foreach (range(1, 21) as $numero) {
            $document = Courrier::factory()->create(['created_by' => $sec2->id]);
            $document->dossier->update(['statut_archivage' => 'a_archiver', 'archivage_decide_at' => now()]);
        }
        $etranger = Courrier::factory()->create();
        $etranger->dossier->update(['statut_archivage' => 'a_archiver', 'archivage_decide_at' => now()]);
        $this->actingAs($sec2)->getJson('/api/v1/dossiers/a-archiver')->assertOk()
            ->assertJsonCount(20, 'data')->assertJsonPath('total', 21)->assertJsonPath('last_page', 2);
        $pageDeux = $this->actingAs($sec2)->getJson('/api/v1/dossiers/a-archiver?page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotContains($etranger->dossier_id, collect($pageDeux->json('data'))->pluck('id')->all());
    }

    public function test_reponse_physique_notifie_uniquement_si_origine_possede_un_email(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        foreach (['physique@example.test', null] as $index => $email) {
            Mail::fake();
            $origine = Courrier::factory()->create([
                'mode_reception' => 'porteur', 'expediteur_externe_nom' => 'Partenaire physique',
                'expediteur_externe_email' => $email,
            ]);
            $chemin = "courriers/physique-{$index}.pdf";
            Storage::disk('local')->put($chemin, '%PDF réponse officielle');
            $reponse = Courrier::factory()->create([
                'statut' => CourrierStatut::SIGNE, 'sens' => SensCourrier::SORTANT,
                'created_by' => $sec2->id, 'signe_at' => now(), 'signataire_id' => $dg->id,
                'relecture_validee_at' => now(), 'numero_depart' => "PHYSIQUE-{$index}",
                'en_reponse_a_courrier_id' => $origine->id, 'dossier_id' => $origine->dossier_id,
                'destinataire_externe_nom' => $origine->expediteur_externe_nom,
                'destinataire_externe_email' => $email, 'pdf_chemin' => $chemin,
                'pdf_sha256' => hash('sha256', '%PDF réponse officielle'),
            ]);
            $this->marquerDecharge($reponse, Poste::SECRETARIAT_2);
            $this->actingAs($sec2)->postJson("/api/v1/courriers/{$reponse->id}/envoyer", [
                'destinataire_externe_nom' => $origine->expediteur_externe_nom,
                'destinataire_externe_email' => $email, 'mode_expedition' => 'poste',
            ])->assertOk();
            if ($email === null) {
                Mail::assertNothingQueued();
            } else {
                $url = null;
                Mail::assertQueued(ReponseFinaleCourrierExterneMail::class, function ($mail) use ($email, &$url) {
                    $url = $mail->urlTelechargement;

                    return $mail->hasTo($email);
                });
                Mail::assertQueued(ReponseFinaleCourrierExterneMail::class, 1);
                $this->get($url)->assertOk();
            }
        }
    }
}
