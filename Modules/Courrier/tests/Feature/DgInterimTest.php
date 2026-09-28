<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\BordereauLot;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DelegationPoste;
use Modules\Kernel\Models\DgInterim;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DgAuthorityResolver;

/**
 * Correction du circuit DG/DGA : la DGA n'intervient que lorsque la DG est
 * explicitement marquée indisponible (intérim), jamais par défaut. Vérifie
 * aussi que la correction s'applique à tout type de courrier exigeant un
 * avis DG, pas seulement aux demandes de stage.
 */
class DgInterimTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_disponibilite_par_defaut(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $this->actingAs($dg)
            ->getJson('/api/v1/dg-disponibilite')
            ->assertOk()
            ->assertJsonPath('disponible', true);
    }

    public function test_la_dg_peut_se_marquer_indisponible(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $dga = $this->agent(Poste::DGA, $direction);

        $this->actingAs($dg)
            ->postJson('/api/v1/dg-disponibilite', ['disponible' => false, 'dga_interimaire_id' => $dga->id, 'motif' => 'Absence officielle.'])
            ->assertOk()
            ->assertJsonPath('disponible', false);

        $this->actingAs($dg)
            ->getJson('/api/v1/dg-disponibilite')
            ->assertJsonPath('disponible', false);
    }

    public function test_ladministrateur_peut_basculer_la_disponibilite(): void
    {
        $direction = Direction::factory()->create();
        $this->agent(Poste::DG, $direction);
        $dga = $this->agent(Poste::DGA, $direction);
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/dg-disponibilite', ['disponible' => false, 'dga_interimaire_id' => $dga->id, 'motif' => 'Absence officielle.'])
            ->assertOk()
            ->assertJsonPath('disponible', false);
    }

    public function test_ouverture_et_fin_sont_historiques_et_exigent_un_motif_et_un_dga_reels(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $dga = $this->agent(Poste::DGA, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);

        $this->actingAs($dg)->postJson('/api/v1/dg-disponibilite', [
            'disponible' => false, 'dga_interimaire_id' => $dga->id, 'motif' => '   ',
        ])->assertUnprocessable();
        $this->actingAs($dg)->postJson('/api/v1/dg-disponibilite', [
            'disponible' => false, 'dga_interimaire_id' => $sec2->id, 'motif' => 'Absence',
        ])->assertUnprocessable();
        $this->actingAs($dga)->postJson('/api/v1/dg-disponibilite', [
            'disponible' => false, 'dga_interimaire_id' => $dga->id, 'motif' => 'Absence',
        ])->assertForbidden();

        $this->actingAs($dg)->postJson('/api/v1/dg-disponibilite', [
            'disponible' => false, 'dga_interimaire_id' => $dga->id, 'motif' => 'Mission officielle',
            'started_by_id' => $sec2->id, 'started_at' => '2000-01-01',
        ])->assertOk()->assertJsonPath('interim.dga_interimaire_id', $dga->id);
        $interim = DgInterim::query()->sole();
        $this->assertSame($dg->id, $interim->dg_titulaire_id);
        $this->assertSame($dg->id, $interim->started_by_id);
        $this->assertSame('Mission officielle', $interim->motif);
        $this->assertNotSame('2000-01-01', $interim->started_at->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'dg.interim_ouvert', 'user_id' => $dg->id]);

        $this->actingAs($dg)->postJson('/api/v1/dg-disponibilite', [
            'disponible' => false, 'dga_interimaire_id' => $dga->id, 'motif' => 'Deuxième',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('dg_interims', 1);
        $this->actingAs($dga)->postJson('/api/v1/dg-disponibilite', ['disponible' => true])->assertForbidden();
        $this->actingAs($dg)->postJson('/api/v1/dg-disponibilite', [
            'disponible' => true, 'ended_by_id' => $dga->id, 'ended_at' => '2000-01-01',
        ])->assertOk();
        $interim->refresh();
        $this->assertSame($dg->id, $interim->ended_by_id);
        $this->assertNotNull($interim->ended_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'dg.interim_termine', 'user_id' => $dg->id]);
        $this->actingAs($dg)->postJson('/api/v1/dg-disponibilite', ['disponible' => true])->assertUnprocessable();
        $this->assertSame($dg->id, $interim->fresh()->ended_by_id);
        $this->actingAs($dg)->getJson('/api/v1/dg-interims')->assertOk()->assertJsonPath('data.0.motif', 'Mission officielle');
    }

    public function test_booleen_historique_seul_ne_donne_aucun_pouvoir_et_delegation_est_suspendue_puis_reprise(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $dga = $this->agent(Poste::DGA, $direction);
        $admin = User::factory()->administrateur()->create();
        $delegataire = User::factory()->responsableDirection($direction)->create();
        $delegation = DelegationPoste::query()->create([
            'poste' => Poste::DG, 'delegataire_id' => $delegataire->id,
            'debut' => now()->subDay(), 'fin' => now()->addDay(),
            'motif' => 'Délégation active', 'cree_par_id' => $admin->id,
        ]);
        $autorite = app(DgAuthorityResolver::class);
        $dg->update(['dg_disponible' => false]);
        $this->assertNull($autorite->source($dga));
        $this->assertSame('delegation', $autorite->source($delegataire));

        $this->actingAs($admin)->postJson('/api/v1/dg-disponibilite', [
            'disponible' => false, 'dga_interimaire_id' => $dga->id, 'motif' => 'Relais DGA',
        ])->assertOk();
        $this->assertSame('interim_dga', $autorite->source($dga));
        $this->assertNull($autorite->source($delegataire));
        $this->assertNull($autorite->source($dg));

        $this->actingAs($admin)->postJson('/api/v1/dg-disponibilite', ['disponible' => true])->assertOk();
        $this->assertNull($autorite->source($dga));
        $this->assertSame('delegation', $autorite->source($delegataire));
        $delegation->update(['revoquee_at' => now(), 'revoquee_par_id' => $admin->id, 'motif_revocation' => 'Fin de mandat']);
        $this->assertNull($autorite->source($delegataire));
    }

    public function test_dga_interimaire_peut_preparer_d_signer_instruire_et_acquitter_un_lot_dg(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $dga = $this->agent(Poste::DGA, $direction);
        $assistantDg = $this->agent(Poste::ASSISTANT_1, $direction);
        $this->agent(Poste::ASSISTANT_DGA, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->marquerDecharge($courrier);
        $lot = BordereauLot::query()->create([
            'numero' => 'BL-DGA-001', 'emetteur_id' => $dg->id, 'poste_destinataire' => Poste::DG,
        ]);

        $this->actingAs($dga)->getJson("/api/v1/courriers/{$courrier->id}")->assertNotFound();
        $this->actingAs($dga)->postJson("/api/v1/bordereaux-lot/{$lot->id}/accuser-reception")->assertForbidden();
        $this->actingAs($dg)->postJson('/api/v1/dg-disponibilite', [
            'disponible' => false, 'dga_interimaire_id' => $dga->id, 'motif' => 'Absence officielle',
        ])->assertOk();

        $this->actingAs($dga)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk();
        $this->actingAs($dga)->postJson("/api/v1/bordereaux-lot/{$lot->id}/accuser-reception")
            ->assertOk()->assertJsonPath('data.acquitte', true);
        $this->assertSame($dga->id, $lot->fresh()->accuse_reception_par_id);
        $this->actingAs($dga)->postJson('/api/v1/instructions-courrier-dg', [
            'instruction' => 'Préparer une correspondance officielle.', 'donneur_id' => $dg->id,
        ])->assertCreated()->assertJsonPath('data.donneur_id', $dga->id);

        $this->actingAs($dga)->postJson("/api/v1/courriers/{$courrier->id}/demander-preparation-reponse", [
            'assistant_id' => $assistantDg->id, 'instruction' => 'Rédiger D.',
        ])->assertCreated()->assertJsonPath('data.autorite_poste', Poste::DG->value);

        $d = Courrier::factory()->create([
            'sens' => 'sortant', 'statut' => CourrierStatut::PROJET_A_VALIDER,
            'destinataire_externe_nom' => 'Destinataire officiel',
            'relecture_validee_at' => now(),
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
        ]);
        $this->marquerDecharge($d);
        $this->actingAs($dga)->postJson("/api/v1/courriers/{$d->id}/signer", [
            'signataire_id' => $dg->id, 'numero_depart' => 'FORGE-0001',
        ])->assertOk()->assertJsonPath('data.signataire.id', $dga->id);
        $this->assertSame($dga->id, $d->fresh()->signataire_id);
        $this->assertNotSame('FORGE-0001', $d->fresh()->numero_depart);
        $this->assertDatabaseHas('audit_logs', ['action' => 'courrier.signature', 'user_id' => $dga->id]);

        $this->actingAs($dg)->postJson('/api/v1/dg-disponibilite', ['disponible' => true])->assertOk();
        $this->actingAs($dga)->postJson('/api/v1/instructions-courrier-dg', [
            'instruction' => 'Interdit après fin.',
        ])->assertForbidden();
    }

    public function test_un_responsable_de_direction_ne_peut_pas_basculer_la_disponibilite(): void
    {
        $direction = Direction::factory()->create();
        $this->agent(Poste::DG, $direction);
        $responsable = User::factory()->responsableDirection($direction)->create();

        $this->actingAs($responsable)
            ->postJson('/api/v1/dg-disponibilite', ['disponible' => false])
            ->assertStatus(403);
    }

    public function test_la_dga_peut_rendre_lavis_une_fois_la_dg_indisponible(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $dga = $this->agent(Poste::DGA, $direction);

        $this->actingAs($dg)->postJson('/api/v1/dg-disponibilite', ['disponible' => false, 'dga_interimaire_id' => $dga->id, 'motif' => 'Absence officielle.'])->assertOk();

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->marquerDecharge($courrier);

        $response = $this->actingAs($dga)
            ->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertOk()
            ->assertJsonPath('data.avis_dg_rendu_en_interim', true)
            ->assertJsonPath('data.avis_dg_rendu_par', $dga->name);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'courrier.avis_dg_rendu',
            'auditable_id' => $courrier->id,
        ]);

        $response->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_AVIS_DG->value);
    }

    public function test_le_dg_titulaire_ne_decide_pas_pendant_un_interim_ouvert(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $dga = $this->agent(Poste::DGA, $direction);

        $this->actingAs($dg)->postJson('/api/v1/dg-disponibilite', ['disponible' => false, 'dga_interimaire_id' => $dga->id, 'motif' => 'Absence officielle.'])->assertOk();

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->marquerDecharge($courrier);

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertForbidden();
    }

    public function test_un_ancien_dossier_au_protocole_reste_lisible_mais_na_plus_daction_operationnelle(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->create([
            'type' => CourrierType::CORRESPONDANCE_GENERALE,
            'statut' => CourrierStatut::AU_PROTOCOLE,
            'necessite_avis_dg' => true,
        ]);
        $this->actingAs($dg)
            ->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::AU_PROTOCOLE->value);

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/transmettre-au-tri-depuis-protocole")
            ->assertNotFound();
    }
}
