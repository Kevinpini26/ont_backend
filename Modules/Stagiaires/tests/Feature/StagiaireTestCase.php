<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Models\Stagiaire;
use Tests\TestCase;

abstract class StagiaireTestCase extends TestCase
{
    /**
     * La DFP n'est jamais rattachée à une direction (voir
     * UserRole::rolesRequiringDirection()) — TableauRepartitionCircuitService
     * résout "la direction qui établit" par ce code de convention (voir
     * config('stagiaires.direction_dfp_code')), jamais présent par défaut
     * dans une base de test fraîchement migrée. firstOrCreate : `code` est
     * unique en base, un test qui l'appelle deux fois (directement, puis
     * via affecterViaTableau()) ne doit pas créer un doublon.
     */
    protected function directionDfp(): Direction
    {
        $code = config('stagiaires.direction_dfp_code');

        return Direction::query()->where('code', $code)->first()
            ?? Direction::factory()->create(['code' => $code]);
    }

    /**
     * Lot 5 : l'affectation réelle n'est plus qu'un effet de l'approbation
     * d'un tableau de répartition — plus de route "/affecter" directe.
     * Reproduit tout le trajet (création du tableau, ligne, soumission,
     * présentation à la DG, approbation) pour les tests qui ont seulement
     * besoin d'un stagiaire affecté, sans s'intéresser au tableau
     * lui-même. `$stagiaire` doit être au statut EN_ATTENTE_AFFECTATION.
     */
    protected function affecterViaTableau(
        Stagiaire $stagiaire,
        Direction $direction,
        User $dfp,
        ?User $reception = null,
        ?User $dg = null,
        string $encadrantPressenti = 'Encadrant de test',
    ): TestResponse {
        $directionDfp = $this->directionDfp();
        $reception ??= User::factory()->agentCircuitCourrier(Poste::RECEPTION, Direction::factory()->create())->create();
        // Une même base de test peut appeler ce raccourci plusieurs fois :
        // réutiliser le titulaire évite de fabriquer artificiellement deux DG.
        $dg ??= User::query()->where('poste', Poste::DG)->first()
            ?? User::factory()->agentCircuitCourrier(Poste::DG, Direction::factory()->create())->create();

        // Lot A : éligibilité au tableau conditionnée à une imputation du
        // courrier porteur vers la DFP (voir
        // TableauRepartitionCircuitService::estEligibleAuTableau()) —
        // jamais posée par défaut sur un Stagiaire::factory().
        $this->imputerADfp($stagiaire, $directionDfp);

        $idTableau = $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => now()->toDateString(),
            'periode_fin' => now()->addMonth()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $direction->id,
            'date_debut_proposee' => now()->addMonth()->toDateString(),
            'date_fin_proposee' => now()->addMonths(3)->toDateString(),
            'encadrant_pressenti' => $encadrantPressenti,
        ])->assertOk();

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/soumettre")->assertOk();
        $this->actingAs($reception)->postJson("/api/v1/tableaux-repartition/{$idTableau}/representer-dg")->assertOk();

        return $this->actingAs($dg)->postJson("/api/v1/tableaux-repartition/{$idTableau}/rendre-avis", ['approuve' => true]);
    }

    /**
     * Impute le courrier porteur d'un dossier à la DFP — précondition
     * d'éligibilité au tableau depuis le Lot A. `firstOrCreate` : un
     * courrier n'a qu'une seule imputation principale (contrainte
     * d'index partiel, voir la migration Lot 1 registre).
     */
    protected function imputerADfp(Stagiaire $stagiaire, ?Direction $directionDfp = null): void
    {
        $directionDfp ??= $this->directionDfp();

        $stagiaire->courrier()->firstOrFail()->imputations()->firstOrCreate(
            ['direction_id' => $directionDfp->id],
            ['mention' => 'pour_attribution', 'est_principale' => true],
        );
    }

    /**
     * Un courrier créé directement via factory à un statut intermédiaire
     * (raccourci de test, sans passer par le circuit réel) n'a aucun
     * bordereau : sans cet appel, il apparaît "en transit" indéfiniment et
     * bloque toute action — voir Courrier::enTransit().
     */
    protected function marquerDecharge(Courrier $courrier): void
    {
        $courrier->transitions()->create([
            'statut' => $courrier->statut,
            'accuse_reception_at' => now(),
            'created_at' => now(),
        ]);
    }
}
