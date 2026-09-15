<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;

/**
 * Seeder de volumétrie, jamais appelé par DatabaseSeeder::run() : à lancer
 * explicitement (`php artisan db:seed --class=ChargeDemoSeeder`) pour
 * mesurer l'effet réel des index ajoutés par les migrations
 * add_indexes_to_courriers/add_indexes_to_stagiaires, sur un volume proche
 * de plusieurs années d'archives plutôt que sur les quelques dizaines de
 * lignes de DemoAccountsSeeder. Écrit en insertions groupées via le
 * QueryBuilder (pas Eloquent ni les factories) : à ce volume, les
 * événements de modèle et les sous-factories de relation (qui créeraient
 * une Direction par courrier) rendraient la génération à la fois beaucoup
 * plus lente et sémantiquement fausse pour ce qu'on veut mesurer — un
 * volume réaliste sur un petit nombre de directions réelles, pas un volume
 * de directions.
 */
class ChargeDemoSeeder extends Seeder
{
    private const NB_COURRIERS = 20_000;

    private const NB_STAGIAIRES = 5_000;

    private const PRESENCES_PAR_STAGIAIRE = 40; // 5 000 × 40 = 200 000

    private const TAILLE_LOT = 1000;

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException(
                'ChargeDemoSeeder génère un volume de test de charge, jamais des données réelles : interdit hors environnement local.'
            );
        }

        DB::disableQueryLog();

        $directionIds = Direction::query()->pluck('id')->all();
        if ($directionIds === []) {
            throw new \RuntimeException('Aucune direction en base : lancez DirectionSeeder avant ChargeDemoSeeder.');
        }

        $auteurId = User::query()->where('role', UserRole::ADMINISTRATEUR)->value('id')
            ?? User::factory()->administrateur()->create()->id;

        $this->command?->info('Génération de '.number_format(self::NB_COURRIERS).' courriers...');
        $courrierIds = $this->genererCourriers($directionIds, $auteurId);

        $this->command?->info('Génération de '.number_format(self::NB_STAGIAIRES).' stagiaires...');
        $stagiaireIds = $this->genererStagiaires(array_slice($courrierIds, 0, self::NB_STAGIAIRES), $directionIds);

        $this->command?->info('Génération des présences ('.number_format(self::NB_STAGIAIRES * self::PRESENCES_PAR_STAGIAIRE).' lignes)...');
        $this->genererPresences($stagiaireIds, $auteurId);

        $this->command?->info('Terminé.');
    }

    /**
     * Statuts pondérés vers "enregistré" : ce volume simule des années
     * d'archives déjà traitées, pas un flux du jour où tout serait encore
     * "reçu".
     *
     * @return int[] identifiants des courriers créés, dans l'ordre
     */
    private function genererCourriers(array $directionIds, int $auteurId): array
    {
        $statutsPonderes = [
            ...array_fill(0, 70, CourrierStatut::ENREGISTRE),
            ...array_fill(0, 10, CourrierStatut::SIGNE),
            ...array_fill(0, 10, CourrierStatut::PROJET_A_REDIGER),
            ...array_fill(0, 5, CourrierStatut::EN_ATTENTE_AVIS_DG),
            ...array_fill(0, 5, CourrierStatut::AU_PROTOCOLE),
        ];

        for ($debut = 0; $debut < self::NB_COURRIERS; $debut += self::TAILLE_LOT) {
            $lot = [];
            $tailleLot = min(self::TAILLE_LOT, self::NB_COURRIERS - $debut);

            for ($i = 0; $i < $tailleLot; $i++) {
                $indexGlobal = $debut + $i;
                // Les NB_STAGIAIRES premiers courriers sont des demandes de
                // stage (voir genererStagiaires, qui s'appuie sur ces
                // mêmes lignes comme courrier d'origine) ; le reste,
                // majoritaire, simule la correspondance générale qui
                // constitue l'essentiel du volume réel du circuit courrier.
                $estDemandeStage = $indexGlobal < self::NB_STAGIAIRES;
                $creeLe = now()->subDays(random_int(1, 1095))->subMinutes(random_int(0, 1440));

                $lot[] = [
                    'numero_accuse_reception' => sprintf('CHG-%d-%06d', $creeLe->year, $indexGlobal + 1),
                    'objet' => fake()->sentence(6),
                    'type' => ($estDemandeStage ? CourrierType::DEMANDE_STAGE : CourrierType::CORRESPONDANCE_GENERALE)->value,
                    'statut' => fake()->randomElement($statutsPonderes)->value,
                    'direction_origine_id' => fake()->randomElement($directionIds),
                    'direction_destination_id' => fake()->randomElement($directionIds),
                    'candidat_nom' => $estDemandeStage ? fake()->name() : null,
                    'candidat_etablissement' => $estDemandeStage ? fake()->company() : null,
                    'created_by' => $auteurId,
                    'necessite_avis_dg' => true,
                    'initie_par_dg' => false,
                    'avis_dg_rendu_en_interim' => false,
                    'validation_dg_requise' => false,
                    'created_at' => $creeLe,
                    'updated_at' => $creeLe,
                ];
            }

            DB::table('courriers')->insert($lot);
        }

        // insert() en lot ne renvoie pas les identifiants générés (contrairement
        // à insertGetId(), limité à une ligne) : on les retrouve après coup par
        // le préfixe "CHG-" du numéro d'accusé de réception, propre à ce
        // seeder — fiable même si la table n'était pas vide au départ,
        // contrairement à une supposition sur des id consécutifs.
        return DB::table('courriers')
            ->where('numero_accuse_reception', 'like', 'CHG-%')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * @param  int[]  $courrierIds  un courrier_id par stagiaire, déjà de type demande_stage
     * @return int[] identifiants des stagiaires créés, dans l'ordre
     */
    private function genererStagiaires(array $courrierIds, array $directionIds): array
    {
        $statutsPonderes = [
            ...array_fill(0, 65, StagiaireStatut::CLOTURE),
            ...array_fill(0, 15, StagiaireStatut::STAGE_EN_COURS),
            ...array_fill(0, 10, StagiaireStatut::EVALUATION_EN_COURS),
            ...array_fill(0, 5, StagiaireStatut::AFFECTE),
            ...array_fill(0, 5, StagiaireStatut::EN_ATTENTE_AFFECTATION),
        ];

        $total = count($courrierIds);

        for ($debut = 0; $debut < $total; $debut += self::TAILLE_LOT) {
            $lot = [];
            $tailleLot = min(self::TAILLE_LOT, $total - $debut);

            for ($i = 0; $i < $tailleLot; $i++) {
                $statut = fake()->randomElement($statutsPonderes);
                $debutStage = now()->subDays(random_int(30, 1095));
                $finStage = (clone $debutStage)->addDays(random_int(30, 90));
                $estCloture = $statut === StagiaireStatut::CLOTURE;
                $aDemarre = in_array($statut, [StagiaireStatut::STAGE_EN_COURS, StagiaireStatut::EVALUATION_EN_COURS, StagiaireStatut::CLOTURE], true);

                $lot[] = [
                    'courrier_id' => $courrierIds[$debut + $i],
                    'nom' => fake()->name(),
                    'contact' => fake()->phoneNumber(),
                    'etablissement_origine' => fake()->company(),
                    'reference_courrier' => sprintf('CHG-%d-%06d', $debutStage->year, $debut + $i + 1),
                    'statut' => $statut->value,
                    'direction_id' => fake()->randomElement($directionIds),
                    'type_stage' => fake()->randomElement(['academique', 'professionnel']),
                    'date_debut_stage' => $aDemarre ? $debutStage->toDateString() : null,
                    'date_fin_stage' => $aDemarre ? $finStage->toDateString() : null,
                    'cloture_at' => $estCloture ? $finStage : null,
                    'doublon_suspecte' => false,
                    'affecte_hors_quota' => false,
                    'origine' => 'systeme',
                    'created_at' => $debutStage,
                    'updated_at' => $debutStage,
                ];
            }

            DB::table('stagiaires')->insert($lot);
        }

        return DB::table('stagiaires')
            ->where('reference_courrier', 'like', 'CHG-%')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * @param  int[]  $stagiaireIds
     */
    private function genererPresences(array $stagiaireIds, int $saisiParId): void
    {
        $lot = [];

        foreach ($stagiaireIds as $stagiaireId) {
            $jour = now()->subDays(random_int(60, 900));

            for ($p = 0; $p < self::PRESENCES_PAR_STAGIAIRE; $p++) {
                while ($jour->isWeekend()) {
                    $jour->addDay();
                }

                $lot[] = [
                    'stagiaire_id' => $stagiaireId,
                    'date' => $jour->toDateString(),
                    'heure_arrivee' => '08:30:00',
                    'heure_depart' => '16:30:00',
                    'saisi_par_id' => $saisiParId,
                    'created_at' => $jour,
                    'updated_at' => $jour,
                ];

                $jour = (clone $jour)->addDay();

                if (count($lot) >= self::TAILLE_LOT) {
                    DB::table('stagiaire_presences')->insert($lot);
                    $lot = [];
                }
            }
        }

        if ($lot !== []) {
            DB::table('stagiaire_presences')->insert($lot);
        }
    }
}
