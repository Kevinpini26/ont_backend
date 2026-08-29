<?php

namespace Modules\Public\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;
use Tests\TestCase;

class VerificationDossierPublicTest extends TestCase
{
    use RefreshDatabase;

    private function verifier(string $numero, string $nom)
    {
        return $this->postJson('/api/v1/public/dossiers/verifier', ['numero' => $numero, 'nom' => $nom]);
    }

    /**
     * Comme pour une demande de stage, le statut interne du circuit (huit
     * étapes : Protocole, avis DG, projet de réponse, relecture...) ne doit
     * jamais fuiter vers l'expéditeur d'un courrier externe — seul un
     * statut simplifié à deux valeurs ("En cours de traitement" / "Traité")
     * est exposé.
     */
    public function test_un_candidat_peut_consulter_le_statut_dune_correspondance_generale_sans_authentification(): void
    {
        Courrier::factory()->create([
            'numero_accuse_reception' => 'AR-2026-000042',
            'statut' => CourrierStatut::AU_PROTOCOLE,
            'expediteur_externe_nom' => 'Agence Voyage Congo SARL',
        ]);

        $response = $this->verifier('AR-2026-000042', 'Agence Voyage Congo SARL');

        $response->assertOk()
            ->assertJsonPath('data.numero_accuse_reception', 'AR-2026-000042')
            ->assertJsonPath('data.statut_simplifie', 'En cours de traitement')
            ->assertJsonMissing(['statut'])
            ->assertJsonMissing(['statut_label'])
            ->assertJsonMissing(['avis_dg_commentaire'])
            ->assertJsonMissing(['note_technique']);
    }

    public function test_une_correspondance_generale_enregistree_expose_le_statut_simplifie_traite(): void
    {
        Courrier::factory()->create([
            'numero_accuse_reception' => 'AR-2026-000043',
            'statut' => CourrierStatut::ENREGISTRE,
            'expediteur_externe_nom' => 'Partenaire Externe SA',
        ]);

        $this->verifier('AR-2026-000043', 'Partenaire Externe SA')
            ->assertOk()
            ->assertJsonPath('data.statut_simplifie', 'Traité');
    }

    /**
     * Pour une demande de stage, le statut interne du circuit (huit
     * étapes) ne doit jamais fuiter vers le candidat : seul un statut
     * simplifié à trois valeurs est exposé.
     */
    public function test_une_demande_de_stage_expose_un_statut_simplifie_en_cours_dexamen(): void
    {
        Courrier::factory()->demandeStage()->create([
            'numero_accuse_reception' => 'AR-2026-000050',
            'statut' => CourrierStatut::EN_CIRCUIT_HIERARCHIQUE,
            'candidat_nom' => 'Kabasele Jean Pierre',
        ]);

        $this->verifier('AR-2026-000050', 'Kabasele')
            ->assertOk()
            ->assertJsonPath('data.statut_simplifie', "En cours d'examen")
            ->assertJsonMissing(['statut'])
            ->assertJsonMissing(['statut_label']);
    }

    public function test_une_demande_de_stage_avec_avis_favorable_expose_le_bon_statut_simplifie(): void
    {
        Courrier::factory()->demandeStage()->create([
            'numero_accuse_reception' => 'AR-2026-000051',
            'statut' => CourrierStatut::PROJET_REPONSE_EN_COURS,
            'avis_dg' => AvisDg::FAVORABLE,
            'candidat_nom' => 'Kabasele Jean Pierre',
        ]);

        $this->verifier('AR-2026-000051', 'Kabasele Jean Pierre')
            ->assertOk()
            ->assertJsonPath('data.statut_simplifie', 'Favorable, transmis au service des stages');
    }

    public function test_une_demande_de_stage_avec_avis_defavorable_expose_le_bon_statut_simplifie(): void
    {
        Courrier::factory()->demandeStage()->create([
            'numero_accuse_reception' => 'AR-2026-000052',
            'statut' => CourrierStatut::PROJET_REPONSE_EN_COURS,
            'avis_dg' => AvisDg::DEFAVORABLE,
            'candidat_nom' => 'Kabasele Jean Pierre',
        ]);

        $this->verifier('AR-2026-000052', 'Kabasele Jean Pierre')
            ->assertOk()
            ->assertJsonPath('data.statut_simplifie', 'Non retenu');
    }

    /**
     * Même une fois la fiche stagiaire créée (stage en cours, etc.), le
     * détail interne n'est pas exposé par ce canal : le statut simplifié
     * "favorable" suffit, le déroulement du stage n'a pas à y être suivi.
     */
    public function test_le_detail_de_la_fiche_stagiaire_nest_jamais_expose_publiquement(): void
    {
        $courrier = Courrier::factory()->demandeStage()->create([
            'numero_accuse_reception' => 'AR-2026-000099',
            'avis_dg' => AvisDg::FAVORABLE,
            'candidat_nom' => 'Kabasele Jean Pierre',
        ]);

        Stagiaire::factory()->create([
            'courrier_id' => $courrier->id,
            'statut' => StagiaireStatut::STAGE_EN_COURS,
        ]);

        $this->verifier('AR-2026-000099', 'Kabasele Jean Pierre')
            ->assertOk()
            ->assertJsonPath('data.stagiaire', null)
            ->assertJsonMissing(['statut' => StagiaireStatut::STAGE_EN_COURS->value]);
    }

    public function test_un_numero_inconnu_renvoie_404(): void
    {
        $this->verifier('AR-2026-999999', 'Peu importe')->assertStatus(404);
    }

    public function test_lendpoint_ne_requiert_aucune_authentification(): void
    {
        Courrier::factory()->create([
            'numero_accuse_reception' => 'AR-2026-000001',
            'expediteur_externe_nom' => 'Jean Mukendi',
        ]);

        // Aucun actingAs / token : l'appel doit tout de même aboutir.
        $this->verifier('AR-2026-000001', 'Jean Mukendi')->assertOk();
    }

    public function test_un_numero_correct_avec_un_nom_errone_renvoie_le_meme_404_generique(): void
    {
        Courrier::factory()->demandeStage()->create([
            'numero_accuse_reception' => 'AR-2026-000060',
            'candidat_nom' => 'Kabasele Jean Pierre',
        ]);

        $reponseNumeroInconnu = $this->verifier('AR-2026-999998', 'Peu importe');
        $reponseNomErrone = $this->verifier('AR-2026-000060', 'Un Nom Totalement Different');

        $reponseNumeroInconnu->assertStatus(404);
        $reponseNomErrone->assertStatus(404);
        $this->assertSame($reponseNumeroInconnu->json('message'), $reponseNomErrone->json('message'));
    }

    /**
     * "kabasele" et "jean kabasele" valident tous deux "Kabasele Jean
     * Pierre" : comparaison par mots, insensible à la casse, aux accents et
     * à l'ordre — un candidat qui ne se souvient pas de l'ordre exact saisi
     * par l'agent ne doit pas être bloqué.
     */
    public function test_le_nom_est_compare_par_mots_sans_tenir_compte_de_lordre_la_casse_ou_les_accents(): void
    {
        Courrier::factory()->demandeStage()->create([
            'numero_accuse_reception' => 'AR-2026-000061',
            'candidat_nom' => 'KABASÉLÉ Jean Pierre',
        ]);

        $this->verifier('AR-2026-000061', 'jean kabasele')->assertOk();
        $this->verifier('AR-2026-000061', 'kabasele')->assertOk();
    }

    public function test_un_mot_saisi_trop_court_ne_suffit_pas_a_valider(): void
    {
        Courrier::factory()->demandeStage()->create([
            'numero_accuse_reception' => 'AR-2026-000062',
            'candidat_nom' => 'Kabasele Jean Pierre',
        ]);

        // "je" (2 caractères) figure dans "Jean" une fois tokenisé ? Non :
        // le test porte sur l'exigence d'au moins un mot de 3+ caractères,
        // pas sur un sous-mot inclus dans un mot stocké.
        $this->verifier('AR-2026-000062', 'je')->assertStatus(404);
    }

    public function test_lemail_du_candidat_est_accepte_comme_alternative_au_nom(): void
    {
        Courrier::factory()->demandeStage()->create([
            'numero_accuse_reception' => 'AR-2026-000063',
            'candidat_nom' => 'Kabasele Jean Pierre',
            'candidat_email' => 'jean.kabasele@example.com',
        ]);

        $this->verifier('AR-2026-000063', 'JEAN.KABASELE@EXAMPLE.COM')->assertOk();
    }

    public function test_un_courrier_purement_interne_sans_nom_ni_expediteur_reste_introuvable_publiquement(): void
    {
        $direction = \Modules\Kernel\Models\Direction::factory()->create();
        Courrier::factory()->create([
            'numero_accuse_reception' => 'AR-2026-000064',
            'direction_origine_id' => $direction->id,
        ]);

        // Aucun nom stocké (ni candidat_nom, ni expediteur_externe_nom) :
        // aucune saisie ne peut jamais correspondre.
        $this->verifier('AR-2026-000064', 'Peu Importe Le Nom')->assertStatus(404);
    }

    public function test_cinq_echecs_sur_le_meme_numero_verrouillent_ce_numero_pendant_une_heure(): void
    {
        Courrier::factory()->demandeStage()->create([
            'numero_accuse_reception' => 'AR-2026-000070',
            'candidat_nom' => 'Kabasele Jean Pierre',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->verifier('AR-2026-000070', 'Nom Incorrect')->assertStatus(404);
        }

        // Le nom correct ne débloque plus rien une fois le numéro verrouillé.
        $this->verifier('AR-2026-000070', 'Kabasele Jean Pierre')->assertStatus(404);
    }
}
