<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Mail\AccuseReceptionCourrierExterneMail;
use Modules\Courrier\Mail\ReponseFinaleCourrierExterneMail;
use Modules\Courrier\Models\ClassementDocument;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Courrier\Notifications\CourrierDgNotification;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class WorkflowTransversalCyclesTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_parcours_a_b_c_classement_documents_et_archivage_dossier(): void
    {
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        $centrale = Direction::query()->where('code', 'DG')->firstOrFail();
        $dmc = Direction::query()->where('code', 'DMC')->firstOrFail();
        $dep = Direction::query()->where('code', 'DEP')->firstOrFail();
        $reception = $this->agent(Poste::RECEPTION, $centrale);
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $centrale);
        $dg = $this->agent(Poste::DG, $centrale);
        $assistant = $this->agent(Poste::ASSISTANT_1, $centrale);
        $assistant2 = $this->agent(Poste::ASSISTANT_2, $centrale);
        $assistantDga = $this->agent(Poste::ASSISTANT_DGA, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $secretariatDmc = User::factory()->secretariatDirection($dmc)->create();
        $directeurDmc = User::factory()->directeurDirection($dmc)->create();
        $secretariatDep = User::factory()->secretariatDirection($dep)->create();
        $directeurDep = User::factory()->directeurDirection($dep)->create();

        $numeroAccuseA = $this->post('/api/v1/public/courriers-externes', [
            'objet' => 'Demande de partenariat pour la promotion d’une destination touristique en République Démocratique du Congo',
            'expediteur_externe_nom' => 'Organisation Partenaire Tourisme RDC',
            'expediteur_externe_email' => 'partenaire@example.test',
            'piece_jointe' => UploadedFile::fake()->create('a.pdf', 20, 'application/pdf'),
        ])->assertCreated()->json('numero_accuse_reception');
        $a = Courrier::withoutGlobalScopes()->where('numero_accuse_reception', $numeroAccuseA)->firstOrFail();
        Mail::assertQueued(AccuseReceptionCourrierExterneMail::class, fn ($mail) => $mail->hasTo('partenaire@example.test'));
        Mail::assertNotQueued(ReponseFinaleCourrierExterneMail::class);
        $this->assertSame(CourrierType::CORRESPONDANCE_GENERALE, $a->type);
        $this->assertSame('Organisation Partenaire Tourisme RDC', $a->expediteur_externe_nom);
        $this->assertSame('depot_en_ligne', $a->mode_reception->value);
        $this->assertNull($a->numero_enregistrement);
        Storage::disk('local')->assertExists($a->piece_jointe_chemin);
        $dossierId = $a->dossier_id;
        $this->actingAs($reception)->postJson("/api/v1/courriers/{$a->id}/transmettre-sec1")->assertUnprocessable();
        $this->actingAs($reception)->postJson("/api/v1/courriers/{$a->id}/enregistrer", [
            'classification' => 'externe',
            'accuse_reception_partenaire' => 'Dépôt public reçu',
        ])->assertOk();
        $a->refresh();
        $numeroA = $a->numero_enregistrement;
        $this->assertNotNull($numeroA);
        $this->assertAuditActeur('courrier.numero_enregistrement_attribue', $a->id, $reception->id);
        $this->actingAs($reception)->postJson("/api/v1/courriers/{$a->id}/transmettre-sec1")->assertOk();
        $this->assertAuditActeur('courrier.transmis_sec1', $a->id, $reception->id);
        $this->presenterADg($a, $sec1, $dg);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/annotations", [
            'contenu' => 'Instruction DG : instruire le dossier de partenariat avant décision.',
        ])->assertCreated();
        $this->assertDatabaseHas('courrier_annotations', [
            'courrier_id' => $a->id,
            'auteur_id' => $dg->id,
            'contenu' => 'Instruction DG : instruire le dossier de partenariat avant décision.',
        ]);
        $this->assertAuditActeur('courrier.annotation_creee', $a->id, $dg->id);

        $missionId = $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/missions", [
            'assistant_id' => $assistant->id,
            'instruction' => 'Analyser cette demande de partenariat et proposer une orientation pour la Direction Marketing et Communication.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($assistant2)->getJson("/api/v1/courriers/{$a->id}")->assertNotFound();
        $this->actingAs($assistantDga)->getJson("/api/v1/courriers/{$a->id}")->assertNotFound();
        $this->actingAs($assistant)->getJson("/api/v1/courriers/{$a->id}")->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$a->id}/dispatchs", ['destinations' => [['type' => 'direction', 'direction_id' => $dmc->id]]])->assertNotFound();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/dispatchs", ['destinations' => [['type' => 'direction', 'direction_id' => $dmc->id, 'instruction' => 'Interdit pendant mission.']]])->assertUnprocessable();
        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$missionId}/prendre-en-charge")->assertOk();
        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$missionId}/retourner", [
            'compte_rendu' => 'Après analyse, il est proposé de transmettre le dossier à la Direction Marketing et Communication pour traitement et préparation d’une réponse.',
        ])->assertOk();
        $this->actingAs($assistant)->getJson("/api/v1/courriers/{$a->id}")->assertNotFound();

        $b = $this->traiterEtProduire($a, $dmc, $dg, $sec2, $secretariatDmc, $directeurDmc, $directeurDep, 'Réponse à la demande de partenariat pour la promotion touristique');
        $this->assertSame($dossierId, $b->dossier_id);
        $this->recevoirEtPresenterADg($b, $reception, $sec1, $dg);
        $referenceB = $b->fresh()->reference_documentaire;
        $numeroB = $b->fresh()->numero_enregistrement;

        $c = $this->traiterEtProduire($b, $dep, $dg, $sec2, $secretariatDep, $directeurDep, $directeurDmc, 'Note complémentaire relative au partenariat touristique');
        $this->assertSame($dossierId, $c->dossier_id);
        $this->recevoirEtPresenterADg($c, $reception, $sec1, $dg);
        $referenceC = $c->fresh()->reference_documentaire;
        $numeroC = $c->fresh()->numero_enregistrement;

        $contenuD = [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Réponse officielle de l’ONT.']]]],
        ];
        $missionReponseId = $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/demander-preparation-reponse", [
            'assistant_id' => $assistant->id,
            'instruction' => 'Préparer la réponse officielle sur base des avis DMC et DEP.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$missionReponseId}/prendre-en-charge")->assertOk();
        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$missionReponseId}/projet-reponse", [
            'objet' => 'Réponse officielle à la demande de partenariat touristique',
            'destinataire_externe_nom' => 'Organisation Partenaire Tourisme RDC',
            'destinataire_externe_email' => 'partenaire@example.test',
            'projet_reponse_contenu' => $contenuD,
        ])->assertCreated();
        $dId = MissionDocumentaire::query()->findOrFail($missionReponseId)->projet_courrier_id;
        $this->actingAs($assistant)->postJson("/api/v1/missions-documentaires/{$missionReponseId}/projet-reponse/soumettre", [
            'projet_reponse_contenu' => $contenuD,
        ])->assertOk();
        $d = Courrier::withoutGlobalScopes()->findOrFail($dId);
        $this->assertSame('sortant', $d->sens->value);
        $this->assertSame($a->id, $d->en_reponse_a_courrier_id);
        $this->assertSame($dossierId, $d->dossier_id);
        $this->assertDatabaseHas('document_relations', [
            'document_source_id' => $d->id,
            'document_cible_id' => $a->id,
            'type_relation' => 'reponse_a',
        ]);
        Mail::assertNotQueued(ReponseFinaleCourrierExterneMail::class);
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$d->id}/envoyer", [
            'destinataire_externe_nom' => 'Organisation Partenaire Tourisme RDC',
            'destinataire_externe_email' => 'partenaire@example.test',
            'mode_expedition' => 'courriel',
        ])->assertNotFound();
        $this->actingAs($assistant2)->postJson("/api/v1/courriers/{$d->id}/accuser-reception")->assertOk();
        $this->assertSame(0, Notification::sent($dg, CourrierDgNotification::class)
            ->filter(fn ($notification) => $notification->toArray($dg)['courrier_id'] === $d->id)->count());
        $this->actingAs($assistant2)->postJson("/api/v1/courriers/{$d->id}/valider-relecture")->assertOk();
        $this->assertSame('retournee', MissionDocumentaire::query()->findOrFail($missionReponseId)->statut->value);
        $this->assertSame(1, Notification::sent($dg, CourrierDgNotification::class)
            ->filter(fn ($notification) => $notification->toArray($dg)['courrier_id'] === $d->id
                && $notification->toArray($dg)['evenement'] === 'pret_a_signer')->count());
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$d->id}/signer")->assertOk();
        $d->refresh();
        $this->assertNotNull($d->signataire_id);
        $this->assertNotNull($d->signe_at);
        $this->assertNotNull($d->numero_depart);
        $this->assertNotNull($d->pdf_chemin);
        $this->assertNotNull($d->pdf_sha256);
        Storage::disk('local')->assertExists($d->pdf_chemin);
        $hashSigne = $d->pdf_sha256;
        Mail::assertNotQueued(ReponseFinaleCourrierExterneMail::class);
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$d->id}/accuser-reception")->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$d->id}/envoyer", [
            'destinataire_externe_nom' => 'Nom arbitraire ignoré',
            'destinataire_externe_email' => 'detournement@example.test',
            'mode_expedition' => 'courriel',
        ])->assertUnprocessable()->assertJsonValidationErrors('destinataire_externe_email');
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$d->id}/envoyer", [
            'destinataire_externe_nom' => 'Nom arbitraire ignoré',
            'destinataire_externe_email' => 'partenaire@example.test',
            'mode_expedition' => 'courriel',
        ])->assertOk();
        $urlFinale = null;
        Mail::assertQueued(ReponseFinaleCourrierExterneMail::class, function ($mail) use ($a, $d, &$urlFinale) {
            $urlFinale = $mail->urlTelechargement;

            return $mail->hasTo('partenaire@example.test')
            && $mail->courrierOrigine->is($a)
            && $mail->reponse->is($d);
        });
        Mail::assertQueued(ReponseFinaleCourrierExterneMail::class, 1);
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$d->id}/envoyer", [
            'destinataire_externe_nom' => 'Organisation Partenaire Tourisme RDC',
            'destinataire_externe_email' => 'partenaire@example.test',
            'mode_expedition' => 'courriel',
        ])->assertForbidden();
        Mail::assertQueued(ReponseFinaleCourrierExterneMail::class, 1);
        $this->assertSame($hashSigne, $d->fresh()->pdf_sha256);
        $this->assertAuditActeur('courrier.reponse_finale_notifiee', $d->id, $sec2->id);
        $telechargement = $this->get($urlFinale)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame($hashSigne, hash('sha256', $telechargement->streamedContent()));

        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$dossierId}/decision-archivage")->assertUnprocessable();

        foreach ([$a, $b, $c, $d] as $index => $document) {
            $cyclePrecedent = (int) $document->dispatchs()->max('cycle');
            $this->actingAs($dg)->postJson("/api/v1/courriers/{$document->id}/dispatchs", ['destinations' => [[
                'type' => 'classement', 'instruction' => 'Classer le document.',
            ]]])->assertOk();
            $dispatchClassement = $document->dispatchs()->where('type_destination', 'classement')->firstOrFail();
            $this->assertSame($cyclePrecedent + 1, $dispatchClassement->cycle);
            $this->receptionnerDispatchSec2($dispatchClassement, $sec2);
            $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatchClassement->id}/classer", [
                'cote' => 'DOSSIER-X-'.($index + 1), 'emplacement' => 'Archives / X',
            ])->assertOk();
            $classement = ClassementDocument::query()->where('courrier_id', $document->id)->firstOrFail();
            $this->actingAs($sec2)->postJson("/api/v1/classements-documents/{$classement->id}/archiver")->assertOk();
            if ($index === 1) {
                $this->actingAs($dg)->postJson("/api/v1/dossiers/{$dossierId}/decision-archivage")->assertUnprocessable();
                $this->assertDatabaseHas('dossiers', ['id' => $dossierId, 'statut_archivage' => 'actif']);
            }
        }

        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$dossierId}/decision-archivage")->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/dossiers/{$dossierId}/archiver")->assertOk()->assertJsonPath('data.statut_archivage', 'archive');
        $this->assertSame($hashSigne, $d->fresh()->pdf_sha256);
        $this->get($urlFinale)->assertOk();

        $this->assertDatabaseCount('courriers', 4);
        $this->assertDatabaseCount('dossiers', 1);
        $this->assertDatabaseHas('document_relations', ['document_source_id' => $b->id, 'document_cible_id' => $a->id, 'type_relation' => 'produit_a_partir_de']);
        $this->assertDatabaseHas('document_relations', ['document_source_id' => $c->id, 'document_cible_id' => $b->id, 'type_relation' => 'produit_a_partir_de']);
        $this->assertNotSame($a->id, $b->id);
        $this->assertNotSame($b->id, $c->id);
        $this->assertNotSame($numeroA, $numeroB);
        $this->assertNotSame($numeroB, $numeroC);
        $this->assertNotSame($referenceB, $referenceC);
        $this->assertSame($numeroA, $a->fresh()->numero_enregistrement);
        $this->assertSame($referenceB, $b->fresh()->reference_documentaire);
        $this->assertSame($referenceC, $c->fresh()->reference_documentaire);
        $this->assertDatabaseCount('classements_documents', 4);
        $this->assertDatabaseCount('document_relations', 3);
        $this->assertNotNull($a->fresh()->piece_jointe_chemin);
        $this->assertDatabaseHas('dossiers', ['id' => $dossierId, 'statut_archivage' => 'archive']);

        $this->actingAs($reception)->get("/api/v1/courriers/{$a->id}/piece-jointe")->assertOk();
        Storage::disk('local')->assertExists($a->fresh()->piece_jointe_chemin);

        $this->assertAuditActeur('mission_documentaire.creee', $missionId, $dg->id);
        $this->assertAuditActeur('mission_documentaire.prise_en_charge', $missionId, $assistant->id);
        $this->assertAuditActeur('mission_documentaire.retournee', $missionId, $assistant->id);
        $this->assertSame($dg->id, AuditLog::query()->where('action', 'dispatch.decision_prise')->where('auditable_id', $a->id)->oldest('id')->value('user_id'));
        $this->assertSame($sec2->id, AuditLog::query()->where('action', 'dispatch.execute')->oldest('id')->value('user_id'));
        $this->assertSame($dg->id, AuditLog::query()->where('action', 'dossier.archivage_decide')->where('auditable_id', $dossierId)->value('user_id'));
        $this->assertSame($sec2->id, AuditLog::query()->where('action', 'dossier.archive')->where('auditable_id', $dossierId)->value('user_id'));

        $this->assertSame([1, 2], $a->dispatchs()->orderBy('cycle')->pluck('cycle')->unique()->values()->all());
        $this->assertSame([1, 2], $b->dispatchs()->orderBy('cycle')->pluck('cycle')->unique()->values()->all());
        $this->assertSame([1], $c->dispatchs()->orderBy('cycle')->pluck('cycle')->unique()->values()->all());
        $this->assertSame([1], $d->dispatchs()->orderBy('cycle')->pluck('cycle')->unique()->values()->all());

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/dispatchs", ['destinations' => [['type' => 'classement', 'instruction' => 'Interdit après archive.']]])->assertUnprocessable();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/missions", ['assistant_id' => $assistant->id, 'instruction' => 'Interdit après archive.'])->assertUnprocessable();
    }

    public function test_parcours_physique_complet_de_la_reception_a_lenvoi_officiel(): void
    {
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        $centrale = Direction::query()->where('code', 'DG')->firstOrFail();
        $reception = $this->agent(Poste::RECEPTION, $centrale);
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $centrale);
        $dg = $this->agent(Poste::DG, $centrale);
        $dg1 = $this->agent(Poste::ASSISTANT_1, $centrale);
        $dg2 = $this->agent(Poste::ASSISTANT_2, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);

        $idA = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'TEST E2E physique — demande de partenariat institutionnel',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire physique TEST',
            'expediteur_externe_email' => 'physique-e2e@example.test',
            'mode_reception' => 'porteur',
            'date_courrier' => '2026-09-28',
            'reference_expediteur' => 'TEST-PHYSIQUE-E2E-001',
            'piece_jointe' => UploadedFile::fake()->create('test-physique.pdf', 20, 'application/pdf'),
        ])->assertCreated()->json('data.id');
        $a = Courrier::withoutGlobalScopes()->with(['numerisations', 'transitions'])->findOrFail($idA);
        $identiteA = [$a->id, $a->dossier_id, $a->numero_enregistrement];
        $this->assertSame('entrant', $a->sens->value);
        $this->assertSame('porteur', $a->mode_reception->value);
        $this->assertSame($reception->id, $a->created_by);
        $this->assertNotNull($a->enregistre_at);
        $this->assertNotNull($a->numero_enregistrement);
        $this->assertNull($a->numero_accuse_reception);
        $this->assertCount(1, $a->numerisations);
        $this->assertNotNull($a->numerisations->sole()->sha256);
        Storage::disk('local')->assertExists($a->numerisations->sole()->chemin);
        $this->assertSame(Poste::SECRETARIAT_1->value, $a->transitions->sole()->destinataire_poste);
        Mail::assertNotQueued(AccuseReceptionCourrierExterneMail::class);

        $this->presenterADg($a, $sec1, $dg);
        $this->assertAuditActeur('courrier.accuse_reception', $a->id, $sec1->id);
        $this->assertAuditActeur('courrier.transmis_tri', $a->id, $sec1->id);
        $this->assertAuditActeur('courrier.tri_sec1', $a->id, $sec1->id);
        $this->assertAuditActeur('courrier.transmis_dg', $a->id, $sec1->id);
        $this->assertAuditActeur('courrier.accuse_reception', $a->id, $dg->id);

        $annotation = 'Instruction DG TEST : préparer une réponse officielle après analyse du courrier physique.';
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/annotations", ['contenu' => $annotation])->assertCreated();
        $this->assertDatabaseHas('courrier_annotations', ['courrier_id' => $a->id, 'auteur_id' => $dg->id, 'contenu' => $annotation]);
        $this->assertAuditActeur('courrier.annotation_creee', $a->id, $dg->id);
        $this->assertSame($identiteA, [$a->fresh()->id, $a->fresh()->dossier_id, $a->fresh()->numero_enregistrement]);

        $missionId = $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/demander-preparation-reponse", [
            'assistant_id' => $dg1->id,
            'instruction' => 'Préparer la réponse officielle au courrier physique TEST.',
        ])->assertCreated()->assertJsonPath('data.type', 'preparation_reponse')->json('data.id');
        $this->assertDatabaseHas('missions_documentaires', [
            'id' => $missionId,
            'courrier_id' => $a->id,
            'assistant_id' => $dg1->id,
            'demandeur_id' => $dg->id,
            'type' => 'preparation_reponse',
        ]);
        $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/prendre-en-charge")->assertOk();
        $contenu = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Réponse officielle au courrier physique TEST.']]]]];
        $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse", [
            'objet' => 'Réponse ONT au courrier physique TEST',
            'destinataire_externe_nom' => 'Partenaire physique TEST',
            'destinataire_externe_email' => 'physique-e2e@example.test',
            'projet_reponse_contenu' => $contenu,
        ])->assertCreated();
        $mission = MissionDocumentaire::query()->findOrFail($missionId);
        $d = Courrier::withoutGlobalScopes()->findOrFail($mission->projet_courrier_id);
        $idD = $d->id;
        $this->assertNotSame($a->id, $idD);
        $this->assertSame('sortant', $d->sens->value);
        $this->assertSame($a->dossier_id, $d->dossier_id);
        $this->assertSame($a->id, $d->en_reponse_a_courrier_id);
        $this->assertNull($d->numero_depart);
        $this->assertNull($d->pdf_chemin);
        $this->assertNull($d->pdf_sha256);
        $this->assertDatabaseHas('document_relations', ['document_source_id' => $idD, 'document_cible_id' => $a->id, 'type_relation' => 'reponse_a']);

        $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse/soumettre", ['projet_reponse_contenu' => $contenu])
            ->assertOk()->assertJsonPath('data.relecteur.id', $dg2->id);
        $this->actingAs($dg2)->postJson("/api/v1/courriers/{$idD}/accuser-reception")->assertOk();
        $this->actingAs($dg2)->postJson("/api/v1/courriers/{$idD}/renvoyer-pour-correction", ['observation' => 'Ajouter la référence au courrier physique.'])
            ->assertOk();
        $corrige = [...$contenu, 'version_test' => 2];
        $this->actingAs($dg1)->patchJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse", ['projet_reponse_contenu' => $corrige])->assertOk();
        $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse/soumettre", ['projet_reponse_contenu' => $corrige])
            ->assertOk()->assertJsonPath('data.id', $idD);
        $this->assertSame($idD, MissionDocumentaire::query()->findOrFail($missionId)->projet_courrier_id);
        $this->assertSame(1, Courrier::withoutGlobalScopes()->where('en_reponse_a_courrier_id', $a->id)->count());
        $this->actingAs($dg2)->postJson("/api/v1/courriers/{$idD}/accuser-reception")->assertOk();
        $this->actingAs($dg2)->postJson("/api/v1/courriers/{$idD}/valider-relecture")->assertOk();
        $this->assertSame('retournee', $mission->fresh()->statut->value);

        Mail::assertNotQueued(ReponseFinaleCourrierExterneMail::class);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$idD}/signer")->assertOk()->assertJsonPath('data.statut', 'signe');
        $d->refresh();
        $this->assertSame($idD, $d->id);
        $this->assertSame($dg->id, $d->signataire_id);
        $this->assertNotNull($d->signe_at);
        $this->assertNotNull($d->numero_depart);
        $this->assertNotNull($d->pdf_chemin);
        $this->assertNotNull($d->pdf_sha256);
        Storage::disk('local')->assertExists($d->pdf_chemin);
        $hashSigne = $d->pdf_sha256;
        $this->assertAuditActeur('courrier.signature', $idD, $dg->id);

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$idD}/accuser-reception")->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$idD}/envoyer", [
            'destinataire_externe_nom' => 'Partenaire physique TEST',
            'destinataire_externe_email' => 'physique-e2e@example.test',
            'mode_expedition' => 'courriel',
        ])->assertOk()->assertJsonPath('data.statut', 'envoye');
        $urlFinale = null;
        Mail::assertQueued(ReponseFinaleCourrierExterneMail::class, function ($mail) use ($a, $d, &$urlFinale): bool {
            $urlFinale = $mail->urlTelechargement;

            return $mail->hasTo('physique-e2e@example.test') && $mail->courrierOrigine->is($a) && $mail->reponse->is($d);
        });
        Mail::assertQueued(ReponseFinaleCourrierExterneMail::class, 1);
        $this->assertAuditActeur('courrier.envoye', $idD, $sec2->id);
        $this->assertAuditActeur('courrier.reponse_finale_notifiee', $idD, $sec2->id);
        $this->assertSame($hashSigne, $d->fresh()->pdf_sha256);
        $telechargement = $this->get($urlFinale)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame($hashSigne, hash('sha256', $telechargement->streamedContent()));
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$idD}/envoyer", [
            'destinataire_externe_nom' => 'Partenaire physique TEST',
            'destinataire_externe_email' => 'physique-e2e@example.test',
            'mode_expedition' => 'courriel',
        ])->assertForbidden();
        Mail::assertQueued(ReponseFinaleCourrierExterneMail::class, 1);
        $this->assertSame($identiteA, [$a->fresh()->id, $a->fresh()->dossier_id, $a->fresh()->numero_enregistrement]);
    }

    private function presenterADg(Courrier $courrier, User $sec1, User $dg): void
    {
        $this->actingAs($sec1)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();
        $this->actingAs($sec1)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-tri")->assertOk();
        $this->actingAs($sec1)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();
        $this->actingAs($sec1)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-avis-dg", ['degre_urgence' => 'urgent'])->assertOk();
        $evenement = DocumentProduitDirection::query()->where('courrier_id', $courrier->id)->exists()
            ? 'retour_direction_a_decider' : 'pret_a_traiter';
        $this->assertSame(1, Notification::sent($dg, CourrierDgNotification::class)
            ->filter(fn ($notification) => $notification->toArray($dg)['courrier_id'] === $courrier->id
                && $notification->toArray($dg)['evenement'] === $evenement)->count());
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();
    }

    private function traiterEtProduire(Courrier $source, Direction $direction, User $dg, User $sec2, User $secretariat, User $directeur, User $directeurInterdit, string $objet): Courrier
    {
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$source->id}/dispatchs", ['destinations' => [[
            'type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Préparer un retour.',
        ]]])->assertOk();
        $dispatch = DispatchCourrier::query()->where('courrier_id', $source->id)->latest('id')->firstOrFail();
        $this->assertSame(1, $dispatch->cycle);
        $this->assertSame($dg->id, $dispatch->decisionnaire_id);
        $this->actingAs($dg)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertForbidden();
        $this->receptionnerDispatchSec2($dispatch, $sec2);
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertOk();
        $this->actingAs($secretariat)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();
        $traitement = TraitementDirection::query()->where('dispatch_courrier_id', $dispatch->id)->firstOrFail();
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/transmettre-directeur")->assertOk();
        $this->actingAs($directeurInterdit)->postJson("/api/v1/traitements-direction/{$traitement->id}/prendre-en-charge")->assertForbidden();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/prendre-en-charge")->assertOk();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/decision", ['decision' => 'retour_a_preparer'])->assertOk();
        $processusId = $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/document-produit", [
            'objet' => $objet, 'contenu' => ['type' => 'doc', 'content' => []],
        ])->assertCreated()->json('data.id');
        $this->actingAs($secretariat)->postJson("/api/v1/documents-produits-direction/{$processusId}/soumettre")->assertOk();
        $this->actingAs($secretariat)->postJson("/api/v1/documents-produits-direction/{$processusId}/valider")->assertForbidden();
        $this->actingAs($directeur)->postJson("/api/v1/documents-produits-direction/{$processusId}/valider")->assertOk();
        $this->actingAs($secretariat)->postJson("/api/v1/documents-produits-direction/{$processusId}/transmettre-reception")->assertOk();

        return DocumentProduitDirection::query()->findOrFail($processusId)->courrier;
    }

    private function recevoirEtPresenterADg(Courrier $courrier, User $reception, User $sec1, User $dg): void
    {
        $processus = DocumentProduitDirection::query()->where('courrier_id', $courrier->id)->firstOrFail();
        $this->actingAs($reception)->postJson("/api/v1/documents-produits-direction/{$processus->id}/recevoir")->assertOk();
        $this->presenterADg($courrier, $sec1, $dg);
    }

    private function assertAuditActeur(string $action, int $auditableId, int $acteurId): void
    {
        $this->assertDatabaseHas('audit_logs', [
            'action' => $action,
            'auditable_id' => $auditableId,
            'user_id' => $acteurId,
        ]);
    }
}
