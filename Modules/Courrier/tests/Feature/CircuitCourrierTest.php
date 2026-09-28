<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;

class CircuitCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_le_circuit_progresse_etape_par_etape_jusqua_lenregistrement(): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();

        $reception = $this->agent(Poste::RECEPTION, $direction);
        $dga = $this->agent(Poste::DGA, $direction);
        $dg = $this->agent(Poste::DG, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $redacteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_2, $direction);

        $courrier = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Demande de partenariat',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire externe',
            'mode_reception' => 'porteur',
            'direction_destination_id' => $direction->id,
            'piece_jointe' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data');

        $this->assertSame(CourrierStatut::RECU->value, $courrier['statut']);
        $this->assertNotNull($courrier['numero_enregistrement']);
        $numeroEnregistrementReception = $courrier['numero_enregistrement'];
        $id = $courrier['id'];

        // Chaque transition exige désormais une décharge explicite du
        // destinataire avant de pouvoir transmettre à son tour — voir
        // BordereauTransmissionTest.php pour la vérification dédiée du
        // mécanisme lui-même.
        //
        // La Réception remet directement le dossier au Secrétariat 01,
        // sans ancien service Protocole.
        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$id}/transmettre-tri")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_TRI->value);

        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        // Corrigé : le Secrétariat 01 transmet directement à la DG pour
        // avis, sans étape DGA obligatoire (voir DgInterimTest pour le cas
        // d'intérim, où la DGA intervient explicitement). C'est ici que le
        // tri par degré d'urgence (Lot 2) est réellement effectué.
        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$id}/transmettre-avis-dg", ['degre_urgence' => 'urgent'])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_AVIS_DG->value)
            ->assertJsonPath('data.degre_urgence', 'urgent');

        // La DG (jamais la DGA) accuse réception du bordereau : la DGA
        // reste ensuite bloquée par la garde d'intérim ci-dessous, pas par
        // un défaut de décharge — la distinction entre les deux raisons de
        // blocage doit rester nette.
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        // La DGA ne peut pas rendre l'avis tant que la DG est disponible.
        $this->actingAs($dga)
            ->postJson("/api/v1/courriers/{$id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertNotFound();

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_AVIS_DG->value)
            ->assertJsonPath('data.avis_dg_rendu_par', $dg->name)
            ->assertJsonPath('data.avis_dg_rendu_en_interim', false);

        // Compatibilité historique : un dossier déjà engagé dans l'ancien
        // statut reste traitable, mais aucun nouveau dossier n'y entre via
        // rendreAvisDg(). Le nouveau circuit D est couvert séparément par
        // ProjetReponseDTest.
        Courrier::withoutGlobalScopes()->whereKey($id)->update([
            'statut' => CourrierStatut::PROJET_A_REDIGER,
            'created_by' => $redacteur->id,
        ]);

        $this->actingAs($redacteur)
            ->postJson("/api/v1/courriers/{$id}/soumettre-projet-reponse", [
                'projet_reponse_contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
                'relecteur_id' => $relecteur->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::PROJET_A_VALIDER->value);

        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        $this->actingAs($relecteur)
            ->postJson("/api/v1/courriers/{$id}/valider-relecture")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'courrier.relecture_validee',
            'auditable_id' => $id,
            'user_id' => $relecteur->id,
        ]);

        $traceRelecture = AuditLog::query()->where('action', 'courrier.relecture_validee')->sole();
        $this->assertNotNull($traceRelecture->created_at);
        $this->assertFalse($traceRelecture->meta['commentaire_fourni']);
        $this->assertArrayHasKey('relecture_validee_at', $traceRelecture->meta);

        // La décharge du bordereau en_relecture, donnée par le relecteur
        // ci-dessus, suffit aussi à débloquer la signature de la DG : ce
        // n'est pas un nouveau bordereau distinct (voir
        // CourrierCircuitService::tracerTransition()).
        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$id}/signer")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::SIGNE->value);

        $this->actingAs($secretariat2)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        // Créé par la Réception (mail physique externe, aucune direction
        // d'origine forcée) : classé externe automatiquement, voir
        // Courrier::classificationAttendue() et ConformiteCahierDesChargesTest.
        $response = $this->actingAs($secretariat2)
            ->postJson("/api/v1/courriers/{$id}/enregistrer", [
                'classification' => CourrierClassification::EXTERNE->value,
                'accuse_reception_partenaire' => 'AR-PARTENAIRE-001',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::ENREGISTRE->value);

        $this->assertSame($numeroEnregistrementReception, $response->json('data.numero_enregistrement'));
        $this->assertStringStartsWith((string) now()->year, $response->json('data.numero_enregistrement'));
    }

    public function test_impossible_de_sauter_une_etape_du_circuit(): void
    {
        $direction = Direction::factory()->create();
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU]);

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$courrier->id}/transmettre-avis-dg", ['degre_urgence' => 'normal'])
            ->assertStatus(422);

        $courrier->refresh();
        $this->assertSame(CourrierStatut::RECU, $courrier->statut);
    }

    public function test_un_poste_non_habilite_ne_peut_pas_faire_avancer_le_courrier(): void
    {
        $direction = Direction::factory()->create();
        // Depuis "recu", seul le Secrétariat 01 est habilité à ouvrir le tri.
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU]);

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/transmettre-tri")
            ->assertStatus(403);
    }

    public function test_la_signature_est_refusee_sans_relecture_validee(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $redacteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_2, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::PROJET_A_REDIGER]);
        $this->marquerDecharge($courrier, Poste::ASSISTANT_1);

        $this->actingAs($redacteur)->postJson("/api/v1/courriers/{$courrier->id}/soumettre-projet-reponse", [
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
            'relecteur_id' => $relecteur->id,
        ])->assertOk();

        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();

        // Tentative de signature sans validation préalable de la relecture.
        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/signer")
            ->assertStatus(422);

        $courrier->refresh();
        $this->assertSame(CourrierStatut::PROJET_A_VALIDER, $courrier->statut);
        $this->assertNull($courrier->signe_at);

        // Une fois la relecture validée, la signature devient possible.
        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$courrier->id}/valider-relecture")->assertOk();

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/signer")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::SIGNE->value);
    }

    public function test_seul_le_relecteur_designe_peut_valider_la_relecture(): void
    {
        $direction = Direction::factory()->create();
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $autreAssistant = $this->agent(Poste::ASSISTANT_2, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::PROJET_A_VALIDER,
            'relecteur_id' => $relecteur->id,
        ]);

        $this->actingAs($autreAssistant)
            ->postJson("/api/v1/courriers/{$courrier->id}/valider-relecture")
            ->assertNotFound();

        $this->assertNull($courrier->refresh()->relecture_validee_at);
    }

    /**
     * Lot assistants (voir docs/questions-ont.md) : seul le poste des
     * assistants rédige désormais le projet de réponse — le Secrétariat 01,
     * qui garde le tri, n'y est plus habilité.
     */
    public function test_le_secretariat_01_ne_peut_plus_rediger_le_projet_de_reponse(): void
    {
        $direction = Direction::factory()->create();
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::PROJET_A_REDIGER]);
        $this->marquerDecharge($courrier);

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$courrier->id}/soumettre-projet-reponse", [
                'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
                'relecteur_id' => $relecteur->id,
            ])
            ->assertNotFound();
    }

    public function test_les_trois_postes_assistants_actifs_peuvent_traiter_la_file_partagee_des_projets(): void
    {
        $direction = Direction::factory()->create();

        foreach ([Poste::ASSISTANT_1, Poste::ASSISTANT_2, Poste::ASSISTANT_DGA] as $poste) {
            $redacteur = $this->agent($poste, $direction);
            $relecteur = $this->agent($poste === Poste::ASSISTANT_1 ? Poste::ASSISTANT_2 : Poste::ASSISTANT_1, $direction);
            $courrier = Courrier::factory()->create(['statut' => CourrierStatut::PROJET_A_REDIGER]);
            $this->marquerDecharge($courrier, $poste);

            $this->actingAs($redacteur)
                ->postJson("/api/v1/courriers/{$courrier->id}/soumettre-projet-reponse", [
                    'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
                    'relecteur_id' => $relecteur->id,
                ])
                ->assertOk()
                ->assertJsonPath('data.statut', CourrierStatut::PROJET_A_VALIDER->value);
        }
    }

    public function test_lancien_assistant_du_protocole_ne_peut_plus_prendre_un_projet_a_rediger(): void
    {
        $direction = Direction::factory()->create();
        $ancienAssistant = $this->agent(Poste::ASSISTANT_PROTOCOLE, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::PROJET_A_REDIGER]);
        $this->marquerDecharge($courrier);

        $this->actingAs($ancienAssistant)
            ->postJson("/api/v1/courriers/{$courrier->id}/soumettre-projet-reponse", [
                'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
                'relecteur_id' => $relecteur->id,
            ])
            ->assertNotFound();

        $this->assertSame(CourrierStatut::PROJET_A_REDIGER, $courrier->fresh()->statut);
    }

    public function test_le_relecteur_peut_renvoyer_le_projet_pour_correction_avec_observation_obligatoire(): void
    {
        $direction = Direction::factory()->create();
        $redacteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_2, $direction);
        $autreAssistant = $this->agent(Poste::ASSISTANT_DGA, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::PROJET_A_VALIDER,
            'relecteur_id' => $relecteur->id,
        ]);
        $this->marquerDecharge($courrier);

        // Sans observation : refusé (obligatoire, voir
        // RenvoyerPourCorrectionRequest).
        $this->actingAs($relecteur)
            ->postJson("/api/v1/courriers/{$courrier->id}/renvoyer-pour-correction", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['observation']);

        // Un autre assistant, même éligible en général à la rédaction,
        // n'est pas LE relecteur désigné de ce dossier précis.
        $this->actingAs($autreAssistant)
            ->postJson("/api/v1/courriers/{$courrier->id}/renvoyer-pour-correction", ['observation' => 'Manque la référence du dossier.'])
            ->assertNotFound();

        $reponse = $this->actingAs($relecteur)
            ->postJson("/api/v1/courriers/{$courrier->id}/renvoyer-pour-correction", ['observation' => 'Manque la référence du dossier.'])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::PROJET_A_REDIGER->value)
            ->assertJsonPath('data.projet_renvoi_observation', 'Manque la référence du dossier.')
            ->assertJsonPath('data.projet_renvoye_par', $relecteur->name);

        $this->assertNotNull($reponse->json('data.projet_renvoye_at'));

        // Le rédacteur peut soumettre à nouveau depuis PROJET_A_REDIGER.
        $courrier->refresh();
        $this->marquerDecharge($courrier);
        $this->actingAs($redacteur)
            ->postJson("/api/v1/courriers/{$courrier->id}/soumettre-projet-reponse", [
                'projet_reponse_contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
                'relecteur_id' => $relecteur->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::PROJET_A_VALIDER->value);
    }
}
