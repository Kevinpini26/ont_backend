<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DelegationPoste;
use Modules\Kernel\Models\DgInterim;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/** Matrice d'accès validée au lot 2 (propositions A à I). */
class MatriceAccesTest extends CourrierTestCase
{
    use RefreshDatabase;

    private Direction $direction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->direction = Direction::factory()->create();
    }

    private function poste(Poste $poste): User
    {
        return $this->agent($poste, $this->direction);
    }

    private function periode(): string
    {
        return '?debut='.now()->subDay()->toDateString().'&fin='.now()->addDay()->toDateString();
    }

    private function capturerPdf(): object
    {
        $capture = new class implements PdfGenerationService
        {
            public string $html = '';

            public function genererDepuisVue(string $vue, array $donnees = []): string
            {
                $this->html .= view($vue, $donnees)->render();

                return '%PDF';
            }
        };
        $this->app->instance(PdfGenerationService::class, $capture);

        return $capture;
    }

    private function confidentiel(string $objet): Courrier
    {
        return Courrier::factory()->create([
            'objet' => $objet,
            'niveau_confidentialite' => 'confidentiel',
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'direction_destination_id' => $this->direction->id,
            'numero_enregistrement' => now()->year.'-0042',
            'enregistre_at' => now(),
        ]);
    }

    // A : assistants 1 et 2 sans statistiques, registre ni originaux.
    public function test_a_les_assistants_nont_ni_statistiques_ni_registre_ni_originaux(): void
    {
        foreach ([Poste::ASSISTANT_1, Poste::ASSISTANT_2] as $poste) {
            $user = $this->poste($poste);
            $this->actingAs($user)->getJson('/api/v1/courriers/statistiques')->assertForbidden();
            $this->actingAs($user)->getJson('/api/v1/courriers/registre'.$this->periode())->assertForbidden();
            $this->actingAs($user)->getJson('/api/v1/courriers/originaux-empruntes')->assertForbidden();
        }
    }

    public function test_a_les_postes_qui_gerent_le_circuit_gardent_statistiques_et_registre(): void
    {
        $this->capturerPdf();
        foreach ([Poste::RECEPTION, Poste::SECRETARIAT_1, Poste::SECRETARIAT_2, Poste::DG] as $poste) {
            $user = $this->poste($poste);
            $this->actingAs($user)->getJson('/api/v1/courriers/statistiques')->assertOk();
            $this->actingAs($user)->get('/api/v1/courriers/registre'.$this->periode())->assertOk();
        }
    }

    // B : DGA seulement en intérim.
    public function test_b_le_dga_na_registre_et_statistiques_quen_interim(): void
    {
        $this->capturerPdf();
        $dga = $this->poste(Poste::DGA);
        $dg = $this->poste(Poste::DG);

        $this->actingAs($dga)->getJson('/api/v1/courriers/statistiques')->assertForbidden();
        $this->actingAs($dga)->getJson('/api/v1/courriers/registre'.$this->periode())->assertForbidden();

        DgInterim::query()->create(['dg_titulaire_id' => $dg->id, 'dga_interimaire_id' => $dga->id, 'motif' => 'Mission', 'started_at' => now(), 'started_by_id' => $dg->id]);

        $this->actingAs($dga)->getJson('/api/v1/courriers/statistiques')->assertOk();
        $this->actingAs($dga)->get('/api/v1/courriers/registre'.$this->periode())->assertOk();
    }

    // C : l'administrateur n'a plus les vues métier.
    public function test_c_ladministrateur_na_ni_statistiques_ni_registre_ni_tableau_dg_ni_justesse(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->getJson('/api/v1/courriers/statistiques')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/courriers/statistiques-dg')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/courriers/registre'.$this->periode())->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/courriers/justesse-tri')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/courriers/originaux-empruntes')->assertForbidden();
        // Ses outils techniques restent ouverts.
        $this->actingAs($admin)->getJson('/api/v1/users')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/audit-logs')->assertOk();
    }

    // D : objet confidentiel masqué pour l'administrateur, liste, fiche et recherche.
    public function test_d_ladministrateur_ne_voit_jamais_lobjet_dun_courrier_confidentiel(): void
    {
        $admin = User::factory()->administrateur()->create();
        $courrier = $this->confidentiel('OBJET-SECRET-ADMIN');

        $this->assertStringNotContainsString('OBJET-SECRET-ADMIN', $this->actingAs($admin)->getJson('/api/v1/courriers')->getContent());
        $this->actingAs($admin)->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertOk()->assertJsonPath('data.objet', 'Objet confidentiel');
        $recherche = $this->actingAs($admin)->getJson('/api/v1/courriers?recherche=OBJET-SECRET-ADMIN');
        $this->assertStringNotContainsString('OBJET-SECRET-ADMIN', $recherche->getContent());
        $this->assertSame([], $recherche->json('data'));
    }

    public function test_d_la_dg_voit_toujours_lobjet_confidentiel(): void
    {
        $courrier = $this->confidentiel('OBJET-POUR-LA-DG');

        $this->actingAs($this->poste(Poste::DG))->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertOk()->assertJsonPath('data.objet', 'OBJET-POUR-LA-DG');
    }

    // E : registre, objet confidentiel masqué sauf DG et SEC2.
    public function test_e_le_registre_masque_lobjet_confidentiel_sauf_pour_la_dg_et_sec2(): void
    {
        $this->confidentiel('OBJET-AU-REGISTRE');

        $capture = $this->capturerPdf();
        foreach ([Poste::RECEPTION, Poste::SECRETARIAT_1] as $poste) {
            $capture->html = '';
            $this->actingAs($this->poste($poste))->get('/api/v1/courriers/registre'.$this->periode())->assertOk();
            $this->assertStringNotContainsString('OBJET-AU-REGISTRE', $capture->html, $poste->value);
            $this->assertStringContainsString('Objet confidentiel', $capture->html);
        }

        $capture->html = '';
        $this->actingAs($this->poste(Poste::DG))->get('/api/v1/courriers/registre'.$this->periode())->assertOk();
        $this->assertStringContainsString('OBJET-AU-REGISTRE', $capture->html);
    }

    // F : DFP, archive annuelle sans les registres du courrier.
    public function test_f_larchive_annuelle_de_la_dfp_ne_contient_que_le_rapport_des_stages(): void
    {
        $this->confidentiel('OBJET-ARCHIVE');
        $capture = $this->capturerPdf();

        $reponse = $this->actingAs(User::factory()->agentDfp()->create())->get('/api/v1/archives/annuelle?annee='.now()->year)->assertOk();

        $zip = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($zip, $reponse->getContent());
        $archive = new \ZipArchive;
        $archive->open($zip);
        $noms = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $noms[] = $archive->getNameIndex($i);
        }
        $archive->close();
        unlink($zip);

        $this->assertSame(['rapport-annuel-stagiaires-'.now()->year.'.pdf'], $noms);
        $this->assertStringNotContainsString('OBJET-ARCHIVE', $capture->html);
    }

    // G : justesse du tri, taux global pour SEC1, détail par agent pour la DG.
    public function test_g_sec1_ne_voit_que_le_taux_global_et_la_dg_le_detail(): void
    {
        $sec1 = $this->poste(Poste::SECRETARIAT_1);
        $courrier = Courrier::factory()->create();
        $courrier->transitions()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'changed_by_id' => $sec1->id]);

        $this->actingAs($sec1)->getJson('/api/v1/courriers/justesse-tri')
            ->assertOk()->assertJsonPath('par_agent', [])->assertJsonStructure(['global' => ['taux_justesse']]);

        $detail = $this->actingAs($this->poste(Poste::DG))->getJson('/api/v1/courriers/justesse-tri')->assertOk();
        $this->assertNotEmpty($detail->json('par_agent'));
    }

    // H : liste des agents réservée aux postes qui désignent un relecteur ou un assistant.
    public function test_h_la_liste_des_agents_est_fermee_aux_postes_qui_ne_designent_personne(): void
    {
        foreach ([Poste::RECEPTION, Poste::SECRETARIAT_2] as $poste) {
            $this->actingAs($this->poste($poste))->getJson('/api/v1/agents-circuit-courrier')->assertForbidden();
        }
        $this->actingAs(User::factory()->agentDfp()->create())->getJson('/api/v1/agents-circuit-courrier')->assertForbidden();
        $this->actingAs(User::factory()->secretariatDirection($this->direction)->create())->getJson('/api/v1/agents-circuit-courrier')->assertForbidden();

        foreach ([Poste::DG, Poste::SECRETARIAT_1, Poste::ASSISTANT_1, Poste::ASSISTANT_2] as $poste) {
            $this->actingAs($this->poste($poste))->getJson('/api/v1/agents-circuit-courrier')->assertOk();
        }
        $this->actingAs(User::factory()->directeurDirection($this->direction)->create())->getJson('/api/v1/agents-circuit-courrier')->assertOk();
        $this->actingAs(User::factory()->administrateur()->create())->getJson('/api/v1/agents-circuit-courrier')->assertOk();
    }

    // I : export, objet confidentiel masqué hors DG et SEC2, et jamais pour l'administrateur.
    public function test_i_lexport_masque_lobjet_confidentiel(): void
    {
        $this->confidentiel('OBJET-EXPORTE');

        $admin = $this->actingAs(User::factory()->administrateur()->create())->get('/api/v1/courriers/export')->assertOk();
        $this->assertStringNotContainsString('OBJET-EXPORTE', $admin->streamedContent());

        $dg = $this->actingAs($this->poste(Poste::DG))->get('/api/v1/courriers/export')->assertOk();
        $this->assertStringContainsString('OBJET-EXPORTE', $dg->streamedContent());
    }

    // B (suite) : l'accès ouvert par l'intérim se referme à sa fin.
    public function test_b_lacces_du_dga_se_referme_a_la_fin_de_linterim(): void
    {
        $dga = $this->poste(Poste::DGA);
        $dg = $this->poste(Poste::DG);
        $interim = DgInterim::query()->create(['dg_titulaire_id' => $dg->id, 'dga_interimaire_id' => $dga->id, 'motif' => 'Mission', 'started_at' => now(), 'started_by_id' => $dg->id]);

        $this->actingAs($dga)->getJson('/api/v1/courriers/statistiques-dg')->assertOk();
        $this->actingAs($dga)->getJson('/api/v1/courriers/justesse-tri')->assertOk();

        $interim->forceFill(['ended_at' => now(), 'ended_by_id' => $dg->id])->save();

        $this->actingAs($dga)->getJson('/api/v1/courriers/statistiques')->assertForbidden();
        $this->actingAs($dga)->getJson('/api/v1/courriers/statistiques-dg')->assertForbidden();
        $this->actingAs($dga)->getJson('/api/v1/courriers/justesse-tri')->assertForbidden();
    }

    // Délégation officielle : ouvre l'accès du poste délégué, seulement pendant sa période et tant qu'elle n'est pas révoquée.
    public function test_une_delegation_de_sec1_ouvre_puis_referme_le_registre(): void
    {
        $this->capturerPdf();
        $admin = User::factory()->administrateur()->create();
        $assistant = $this->poste(Poste::ASSISTANT_1);
        $delegation = DelegationPoste::query()->create([
            'poste' => Poste::SECRETARIAT_1, 'delegataire_id' => $assistant->id,
            'debut' => now()->subDay()->toDateString(), 'fin' => now()->addDay()->toDateString(),
            'motif' => 'Congé', 'cree_par_id' => $admin->id,
        ]);

        $this->actingAs($assistant)->get('/api/v1/courriers/registre'.$this->periode())->assertOk();
        $this->actingAs($assistant)->getJson('/api/v1/courriers/justesse-tri')->assertOk()->assertJsonPath('par_agent', []);

        $delegation->forceFill(['revoquee_at' => now(), 'revoquee_par_id' => $admin->id])->save();

        $this->actingAs($assistant)->getJson('/api/v1/courriers/registre'.$this->periode())->assertForbidden();
        $this->actingAs($assistant)->getJson('/api/v1/courriers/justesse-tri')->assertForbidden();
    }

    public function test_une_delegation_expiree_ne_donne_aucun_acces(): void
    {
        $admin = User::factory()->administrateur()->create();
        $assistant = $this->poste(Poste::ASSISTANT_2);
        DelegationPoste::query()->create([
            'poste' => Poste::SECRETARIAT_2, 'delegataire_id' => $assistant->id,
            'debut' => now()->subDays(10)->toDateString(), 'fin' => now()->subDays(2)->toDateString(),
            'motif' => 'Ancienne absence', 'cree_par_id' => $admin->id,
        ]);

        $this->actingAs($assistant)->getJson('/api/v1/courriers/statistiques')->assertForbidden();
        $this->actingAs($assistant)->getJson('/api/v1/courriers/registre'.$this->periode())->assertForbidden();
    }
}
