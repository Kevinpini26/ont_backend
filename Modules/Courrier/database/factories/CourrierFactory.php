<?php

namespace Modules\Courrier\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * @extends Factory<Courrier>
 */
class CourrierFactory extends Factory
{
    protected $model = Courrier::class;

    public function definition(): array
    {
        // Part d'un offset élevé, jamais de 0 : DefaultNumeroGenerator (le
        // vrai générateur, table de séquence dédiée, réinitialisée à
        // chaque test par RefreshDatabase) repart lui aussi de 1 à chaque
        // test — un compteur de factory qui commencerait à 0 entrerait en
        // collision avec le premier accusé de réception "réel" généré
        // dans un test qui mélange les deux (ex. un Stagiaire::factory(),
        // qui crée son propre Courrier::factory() lié, suivi d'un appel à
        // un service utilisant le vrai générateur).
        static $sequence = 900000;
        $sequence++;

        return [
            'numero_accuse_reception' => sprintf('AR-%d-%06d', now()->year, $sequence),
            'objet' => fake()->sentence(),
            // null, comme un courrier réellement créé par la Réception
            // (voir StoreCourrierRequest — contenu n'est renseigné que
            // pour un courrier initié par la DG) : {type: doc, content:
            // []} est un document ProseMirror invalide (le schéma exige
            // "block+", au moins un bloc) et fait planter TipTapEditor à
            // l'affichage.
            'contenu' => null,
            'type' => CourrierType::CORRESPONDANCE_GENERALE,
            'statut' => CourrierStatut::RECU,
            'direction_origine_id' => Direction::factory(),
            'direction_destination_id' => Direction::factory(),
            'created_by' => User::factory()->administrateur(),
        ];
    }

    public function demandeStage(): static
    {
        return $this->state(fn () => [
            'type' => CourrierType::DEMANDE_STAGE,
            'candidat_nom' => fake()->name(),
            'candidat_contact' => fake()->safeEmail(),
            'candidat_etablissement' => fake()->company(),
            'type_stage' => 'academique',
            'periode_souhaitee_debut' => now()->addMonth()->toDateString(),
            'periode_souhaitee_fin' => now()->addMonths(3)->toDateString(),
        ]);
    }

    public function professionnel(): static
    {
        return $this->state(fn () => ['type_stage' => 'professionnel']);
    }
}
