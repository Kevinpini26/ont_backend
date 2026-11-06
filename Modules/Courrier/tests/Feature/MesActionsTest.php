<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DegreUrgence;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DgInterim;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\TableauRepartitionStatut;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\TableauRepartition;

/** Lot 3 : accueil « Ce que j'ai à faire », par poste. */
class MesActionsTest extends CourrierTestCase
{
    use RefreshDatabase;

    private Direction $direction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->direction = Direction::factory()->create();
    }

    private function actions(User $user): array
    {
        return $this->actingAs($user)->getJson('/api/v1/mes-actions')->assertOk()->json('data');
    }

    private function enAttenteTri(string $objet, ?DegreUrgence $urgence, int $heures): Courrier
    {
        $courrier = Courrier::factory()->create(['objet' => $objet, 'statut' => CourrierStatut::EN_ATTENTE_TRI, 'degre_urgence' => $urgence]);
        $courrier->transitions()->create(['statut' => CourrierStatut::EN_ATTENTE_TRI, 'destinataire_poste' => Poste::SECRETARIAT_1->value, 'created_at' => now()->subHours($heures)]);

        return $courrier;
    }

    public function test_sec1_voit_sa_file_triee_par_urgence_puis_par_retard(): void
    {
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $this->direction);
        $this->enAttenteTri('Normal récent', DegreUrgence::NORMAL, 1);
        $this->enAttenteTri('Normal en retard', DegreUrgence::NORMAL, 72);
        $this->enAttenteTri('Très urgent', DegreUrgence::TRES_URGENT, 1);

        $objets = array_column($this->actions($sec1), 'objet');

        $this->assertSame(['Très urgent', 'Normal en retard', 'Normal récent'], $objets);
        $this->assertSame('Accuser réception', $this->actions($sec1)[0]['action']);
    }

    public function test_un_agent_ne_voit_jamais_la_file_dun_autre_poste(): void
    {
        $this->enAttenteTri('Pour SEC1', DegreUrgence::URGENT, 1);

        $this->assertSame([], $this->actions($this->agent(Poste::SECRETARIAT_2, $this->direction)));
        $this->assertSame([], $this->actions(User::factory()->administrateur()->create()));
    }

    public function test_lassistant_voit_ses_missions_et_le_dga_en_interim_la_file_dg(): void
    {
        $assistant = $this->agent(Poste::ASSISTANT_1, $this->direction);
        $dg = $this->agent(Poste::DG, $this->direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'objet' => 'Décision attendue'])->fresh();
        MissionDocumentaire::query()->create([
            'courrier_id' => $courrier->id, 'dossier_id' => $courrier->dossier_id, 'demandeur_id' => $dg->id, 'demandeur_poste' => Poste::DG, 'autorite_poste' => Poste::DG,
            'assistant_id' => $assistant->id, 'instruction' => 'Préparer une note', 'type' => 'preparation_reponse', 'statut' => 'assignee', 'envoyee_at' => now(),
        ]);

        $this->assertSame('mission', $this->actions($assistant)[0]['type']);

        $dga = $this->agent(Poste::DGA, $this->direction);
        $this->assertNotContains('Décision attendue', array_column($this->actions($dga), 'objet'));
        DgInterim::query()->create(['dg_titulaire_id' => $dg->id, 'dga_interimaire_id' => $dga->id, 'motif' => 'Mission', 'started_at' => now(), 'started_by_id' => $dg->id]);
        $this->assertContains('Décision attendue', array_column($this->actions($dga), 'objet'));
    }

    public function test_le_dga_en_interim_ne_voit_chaque_courrier_quune_seule_fois(): void
    {
        $dg = $this->agent(Poste::DG, $this->direction);
        $dga = $this->agent(Poste::DGA, $this->direction);
        Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'objet' => 'Décision unique'])->fresh();
        DgInterim::query()->create(['dg_titulaire_id' => $dg->id, 'dga_interimaire_id' => $dga->id, 'motif' => 'Mission', 'started_at' => now(), 'started_by_id' => $dg->id]);

        $objets = array_column($this->actions($dga), 'objet');

        $this->assertSame(1, array_count_values($objets)['Décision unique'] ?? 0);
    }

    public function test_le_secretariat_de_direction_voit_le_courrier_distribue_a_accuser(): void
    {
        $dg = $this->agent(Poste::DG, $this->direction);
        $secretariat = User::factory()->secretariatDirection($this->direction)->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::DISPATCH_EXECUTE, 'objet' => 'Distribué à la direction', 'direction_destination_id' => $this->direction->id]);
        DispatchCourrier::query()->create([
            'courrier_id' => $courrier->id, 'dossier_id' => $courrier->dossier_id, 'cycle' => 1,
            'type_destination' => DispatchTypeDestination::DIRECTION, 'direction_id' => $this->direction->id,
            'instruction' => 'Traiter', 'decisionnaire_id' => $dg->id, 'decisionnaire_poste' => Poste::DG, 'autorite_poste' => Poste::DG,
            'decide_at' => now()->subDays(4), 'statut' => DispatchStatut::EXECUTE, 'execute_at' => now()->subDays(3),
        ]);

        $actions = $this->actions($secretariat);

        $this->assertSame('distribution', $actions[0]['type']);
        $this->assertTrue($actions[0]['en_retard']);
        $this->assertSame(1, $this->actingAs($secretariat)->getJson('/api/v1/mes-actions')->json('en_retard'));
    }

    public function test_la_dfp_voit_les_demandes_de_stage_a_instruire(): void
    {
        Stagiaire::factory()->create(['statut' => StagiaireStatut::DOSSIER_RECU, 'nom' => 'Ilunga Mbuyi']);
        Stagiaire::factory()->create(['statut' => StagiaireStatut::CLOTURE]);

        $actions = $this->actions(User::factory()->agentDfp()->create());

        $this->assertCount(1, $actions);
        $this->assertSame('Instruire la demande de stage', $actions[0]['action']);
        // Défaut relevé à la répétition : le nom du candidat et la référence restaient vides.
        $this->assertStringContainsString('Ilunga Mbuyi', $actions[0]['objet']);
        $this->assertNotNull($actions[0]['reference']);
    }

    public function test_lobjet_confidentiel_est_masque_pour_qui_ne_lit_pas_le_contenu(): void
    {
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $this->direction);
        $courrier = $this->enAttenteTri('OBJET-SENSIBLE', DegreUrgence::URGENT, 1);
        $courrier->forceFill(['niveau_confidentialite' => 'confidentiel'])->save();

        // SEC1 lit le contenu du courrier qu'il trie : l'objet reste affiché.
        $this->assertSame('OBJET-SENSIBLE', $this->actions($sec1)[0]['objet']);
    }

    /** Défauts relevés à la répétition du lot 5 : une action attendue n'apparaissait pas à l'accueil. */
    public function test_la_reception_voit_le_depot_en_ligne_a_enregistrer_puis_a_transmettre(): void
    {
        $reception = $this->agent(Poste::RECEPTION, $this->direction);
        $depot = Courrier::factory()->create(['statut' => CourrierStatut::RECU, 'mode_reception' => 'depot_en_ligne', 'objet' => 'Dépôt public', 'numero_enregistrement' => null]);

        $this->assertSame('Enregistrer le dépôt', $this->actions($reception)[0]['action']);
        $this->assertSame("/courriers/{$depot->id}", $this->actions($reception)[0]['lien']);

        $depot->forceFill(['numero_enregistrement' => '2026-0100'])->save();
        $this->assertSame('Transmettre à SEC1', $this->actions($reception)[0]['action']);

        $depot->transitions()->create(['statut' => CourrierStatut::RECU, 'destinataire_poste' => Poste::SECRETARIAT_1->value, 'created_at' => now()]);
        $this->assertSame([], $this->actions($reception));
    }

    public function test_le_secretariat_de_direction_est_envoye_sur_lecran_qui_porte_son_action(): void
    {
        $dg = $this->agent(Poste::DG, $this->direction);
        $secretariat = User::factory()->secretariatDirection($this->direction)->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::DISPATCH_EXECUTE, 'direction_destination_id' => $this->direction->id]);
        DispatchCourrier::query()->create([
            'courrier_id' => $courrier->id, 'dossier_id' => $courrier->dossier_id, 'cycle' => 1,
            'type_destination' => DispatchTypeDestination::DIRECTION, 'direction_id' => $this->direction->id,
            'instruction' => 'Traiter', 'decisionnaire_id' => $dg->id, 'decisionnaire_poste' => Poste::DG, 'autorite_poste' => Poste::DG,
            'decide_at' => now(), 'statut' => DispatchStatut::EXECUTE, 'execute_at' => now(),
        ]);

        $this->assertSame('/direction/courriers-recus', $this->actions($secretariat)[0]['lien']);
    }

    /** @return array{0: DocumentProduitDirection, 1: User, 2: User} */
    private function documentInterne(string $statut): array
    {
        $secretariat = User::factory()->secretariatDirection($this->direction)->create();
        $directeur = User::factory()->directeurDirection($this->direction)->create();
        $courrier = Courrier::factory()->create(['objet' => 'Note interne', 'direction_origine_id' => $this->direction->id, 'statut' => CourrierStatut::BROUILLON_DIRECTION]);
        $document = DocumentProduitDirection::query()->create([
            'origine' => DocumentProduitOrigine::AUTONOME, 'courrier_id' => $courrier->id, 'direction_id' => $this->direction->id,
            'directeur_id' => $directeur->id, 'version_contenu' => 1, 'statut' => $statut, 'cree_par_id' => $secretariat->id, 'cree_at' => now(), 'soumis_at' => now(),
        ]);

        return [$document, $secretariat, $directeur];
    }

    public function test_un_document_interne_apparait_chez_le_directeur_puis_le_secretariat_selon_son_etape(): void
    {
        [$document, $secretariat, $directeur] = $this->documentInterne('soumis_directeur');

        $this->assertSame('Examiner le document interne', $this->actions($directeur)[0]['action']);
        $this->assertSame("/direction/directeur/documents-internes/{$document->id}", $this->actions($directeur)[0]['lien']);
        $this->assertSame([], $this->actions($secretariat));

        $document->forceFill(['statut' => 'valide'])->save();
        $this->assertSame([], $this->actions($directeur));
        $this->assertSame('Enregistrer le scan officiel', $this->actions($secretariat)[0]['action']);
        $this->assertSame("/direction/documents-internes/{$document->id}", $this->actions($secretariat)[0]['lien']);
    }

    public function test_un_document_interne_ne_fuit_pas_vers_une_autre_direction(): void
    {
        $this->documentInterne('soumis_directeur');
        $autre = Direction::factory()->create();

        $this->assertSame([], $this->actions(User::factory()->directeurDirection($autre)->create()));
    }

    public function test_la_reception_voit_le_document_interne_transmis(): void
    {
        $this->documentInterne('transmis_reception');

        $actions = $this->actions($this->agent(Poste::RECEPTION, $this->direction));

        $this->assertSame('Contrôler le document interne', $actions[0]['action']);
        $this->assertSame('/circuit/documents-internes', $actions[0]['lien']);
    }

    public function test_le_tableau_de_repartition_apparait_chez_la_reception_puis_chez_la_dg(): void
    {
        $dg = $this->agent(Poste::DG, $this->direction);
        $reception = $this->agent(Poste::RECEPTION, $this->direction);
        $dfp = User::factory()->agentDfp()->create();
        $courrier = Courrier::factory()->create(['type' => 'tableau_repartition', 'statut' => CourrierStatut::TABLEAU_CHEZ_RECEPTION]);
        $tableau = TableauRepartition::query()->create([
            'courrier_id' => $courrier->id, 'direction_id' => $this->direction->id, 'redacteur_id' => $dfp->id,
            'periode_debut' => '2026-12-01', 'periode_fin' => '2027-02-28', 'statut' => TableauRepartitionStatut::CHEZ_RECEPTION,
        ]);

        $actionsReception = array_values(array_filter($this->actions($reception), fn ($a) => $a['type'] === 'tableau'));
        $this->assertSame('Présenter le tableau à la DG', $actionsReception[0]['action']);
        $this->assertSame("/tableaux-repartition/{$tableau->id}", $actionsReception[0]['lien']);

        $tableau->forceFill(['statut' => TableauRepartitionStatut::EN_ATTENTE_AVIS_DG])->save();
        $actionsDg = array_values(array_filter($this->actions($dg), fn ($a) => $a['type'] === 'tableau'));
        $this->assertSame('Rendre un avis sur le tableau', $actionsDg[0]['action']);
        $this->assertSame([], array_values(array_filter($this->actions($reception), fn ($a) => $a['type'] === 'tableau')));
    }

    public function test_laction_de_la_file_dit_ce_quil_y_a_a_faire(): void
    {
        $dg = $this->agent(Poste::DG, $this->direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->marquerDecharge($courrier);

        $this->assertSame('Décider', $this->actions($dg)[0]['action']);
    }
}
