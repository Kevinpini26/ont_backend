<?php

namespace Modules\Kernel\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Kernel\Models\Direction;

class DirectionSeeder extends Seeder
{
    /**
     * Les huit directions réelles de l'Office National du Tourisme de la RDC.
     */
    public function run(): void
    {
        $directions = [
            ['code' => 'DRHL', 'nom' => 'Direction des Ressources Humaines et de la Logistique'],
            ['code' => 'DMFPT', 'nom' => 'Direction de la Mobilisation du Fonds de Promotion du Tourisme'],
            ['code' => 'DF', 'nom' => 'Direction Financière'],
            ['code' => 'DMC', 'nom' => 'Direction Marketing et Communication'],
            ['code' => 'DEP', 'nom' => 'Direction des Études, de la Planification et du Développement Touristique'],
            ['code' => 'DIPP', 'nom' => 'Direction des Investissements, Partenariats et Patrimoine touristique'],
            ['code' => 'DFP', 'nom' => 'Direction de la Formation et de la Professionnalisation'],
            ['code' => 'DAI', 'nom' => "Direction de l'Audit Interne"],
        ];

        foreach ($directions as $direction) {
            Direction::query()->updateOrCreate(
                ['code' => $direction['code']],
                ['nom' => $direction['nom'], 'actif' => true],
            );
        }
    }
}
