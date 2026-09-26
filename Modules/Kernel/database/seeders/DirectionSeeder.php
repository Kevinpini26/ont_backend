<?php

namespace Modules\Kernel\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Kernel\Models\Direction;

class DirectionSeeder extends Seeder
{
    /**
     * La Direction Générale institutionnelle et les huit directions
     * opérationnelles validées. Les codes historiques sont renommés en
     * conservant les lignes (et donc leurs clés étrangères).
     */
    public function run(): void
    {
        Direction::query()->where('code', 'DRHL')->update(['code' => 'DRH']);
        Direction::query()->where('code', 'DMFPT')->update(['code' => 'DMR']);

        $directions = [
            ['code' => 'DG', 'nom' => 'Direction Générale', 'est_operationnelle' => false],
            ['code' => 'DRH', 'nom' => 'Direction des Ressources Humaines', 'est_operationnelle' => true],
            ['code' => 'DMR', 'nom' => 'Direction de Mobilisation des Ressources', 'est_operationnelle' => true],
            ['code' => 'DF', 'nom' => 'Direction Financière'],
            ['code' => 'DMC', 'nom' => 'Direction de Marketing et Communication'],
            ['code' => 'DEP', 'nom' => 'Direction des Études, Planification et Développement Touristique'],
            ['code' => 'DIPP', 'nom' => 'Direction des Investissements, Partenariats et Patrimoines Touristiques'],
            ['code' => 'DFP', 'nom' => 'Direction de Formation et de Professionnalisation'],
            ['code' => 'DAI', 'nom' => 'Direction de l’Audit Interne'],
        ];

        foreach ($directions as $direction) {
            Direction::query()->updateOrCreate(
                ['code' => $direction['code']],
                [
                    'nom' => $direction['nom'],
                    'actif' => true,
                    'est_operationnelle' => $direction['est_operationnelle'] ?? true,
                ],
            );
        }
    }
}
