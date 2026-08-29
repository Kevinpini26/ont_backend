<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\TypeLienPublic;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\StagiaireLienPublic;
use Modules\Stagiaires\Notifications\RetourExperienceDemandeNotification;

class RetourExperienceTest extends StagiaireTestCase
{
    use RefreshDatabase;

    /**
     * Grille complète où chaque critère vaut `$facteur` fois son barème max
     * — donne un total /100 prévisible et facile à faire varier entre deux
     * évaluations (voir GrilleEvaluationTest pour le détail du calcul).
     */
    private function grille(float $facteur): array
    {
        return [
            'aptitudes_professionnelles' => [
                'connaissance_metier' => 10 * $facteur, 'esprit_initiative' => 10 * $facteur, 'sens_responsabilite' => 10 * $facteur,
                'soin_proprete' => 10 * $facteur, 'rendement' => 10 * $facteur, 'justification' => 'RAS',
            ],
            'relations_humaines' => [
                'esprit_equipe' => 10 * $facteur, 'communication' => 10 * $facteur, 'relations_sociales' => 10 * $facteur,
                'justification' => 'RAS',
            ],
            'presentation' => [
                'discipline' => 5 * $facteur, 'ponctualite' => 5 * $facteur, 'regularite' => 5 * $facteur, 'tenue' => 5 * $facteur,
                'justification' => 'RAS',
            ],
        ];
    }

    private function cloturerStagiaire(Direction $direction): Stagiaire
    {
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::EVALUATION_EN_COURS,
            'direction_id' => $direction->id,
            'evaluation_direction_grille' => $this->grille(0.8),
            'evaluation_direction_total' => 80,
            'evaluation_direction_at' => now(),
        ]);

        $dfp = User::factory()->agentDfp()->create();

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-dfp", ['grille' => $this->grille(0.7)])
            ->assertOk()
            ->assertJsonPath('data.statut', StagiaireStatut::CLOTURE->value);

        return $stagiaire->fresh();
    }

    public function test_la_cloture_genere_un_lien_de_retour_et_envoie_la_notification(): void
    {
        Notification::fake();

        $direction = Direction::factory()->create();
        $stagiaire = $this->cloturerStagiaire($direction);

        $this->assertDatabaseHas('stagiaire_liens_publics', [
            'stagiaire_id' => $stagiaire->id,
            'type' => TypeLienPublic::RETOUR_EXPERIENCE->value,
        ]);

        Notification::assertSentOnDemand(RetourExperienceDemandeNotification::class);
    }

    public function test_le_stagiaire_peut_soumettre_son_retour_une_seule_fois(): void
    {
        $direction = Direction::factory()->create();
        $stagiaire = $this->cloturerStagiaire($direction);
        $lien = StagiaireLienPublic::query()
            ->where('stagiaire_id', $stagiaire->id)
            ->where('type', TypeLienPublic::RETOUR_EXPERIENCE)
            ->firstOrFail();

        $this->postJson("/api/v1/public/liens/{$lien->token}/retour", [
            'note_encadrement' => 5,
            'note_missions' => 4,
            'note_ambiance' => 5,
            'commentaire' => 'Très bonne expérience.',
        ])->assertOk();

        $this->assertDatabaseHas('stagiaire_retours', ['stagiaire_id' => $stagiaire->id]);

        // Usage unique, 404 générique (pas de distinction avec un jeton
        // inconnu — voir LienPublicController::trouverLienValide()).
        $this->postJson("/api/v1/public/liens/{$lien->token}/retour", [
            'note_encadrement' => 3,
            'note_missions' => 3,
            'note_ambiance' => 3,
        ])->assertStatus(404);
    }

    public function test_seule_la_dfp_peut_consulter_le_retour_jamais_la_direction_daccueil(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $dfp = User::factory()->agentDfp()->create();

        $stagiaire = $this->cloturerStagiaire($direction);
        $lien = StagiaireLienPublic::query()
            ->where('stagiaire_id', $stagiaire->id)
            ->where('type', TypeLienPublic::RETOUR_EXPERIENCE)
            ->firstOrFail();

        $this->postJson("/api/v1/public/liens/{$lien->token}/retour", [
            'note_encadrement' => 2,
            'note_missions' => 2,
            'note_ambiance' => 1,
            'commentaire' => 'Encadrement insuffisant.',
        ])->assertOk();

        // La direction d'accueil concernée ne peut jamais consulter le retour.
        $this->actingAs($responsable)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}/retour")
            ->assertStatus(403);

        // La DFP, elle, y accède.
        $this->actingAs($dfp)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}/retour")
            ->assertOk()
            ->assertJsonPath('data.note_encadrement', 2)
            ->assertJsonPath('data.commentaire', 'Encadrement insuffisant.');
    }

    public function test_le_contenu_du_retour_napparait_jamais_dans_la_fiche_stagiaire_standard(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = $this->cloturerStagiaire($direction);

        $lien = StagiaireLienPublic::query()
            ->where('stagiaire_id', $stagiaire->id)
            ->where('type', TypeLienPublic::RETOUR_EXPERIENCE)
            ->firstOrFail();

        $this->postJson("/api/v1/public/liens/{$lien->token}/retour", [
            'note_encadrement' => 1,
            'note_missions' => 1,
            'note_ambiance' => 1,
            'commentaire' => 'Ne doit jamais fuiter vers la direction.',
        ])->assertOk();

        $reponse = $this->actingAs($responsable)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}")
            ->assertOk();

        $reponse->assertJsonMissingPath('data.retour');
        $this->assertStringNotContainsString('Ne doit jamais fuiter', $reponse->getContent());
    }
}
