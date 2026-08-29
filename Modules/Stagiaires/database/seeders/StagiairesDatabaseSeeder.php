<?php

namespace Modules\Stagiaires\Database\Seeders;

use Illuminate\Database\Seeder;

class StagiairesDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Stagiaires de démonstration (un académique et un professionnel à
        // chaque étape du cycle de vie) : uniquement en local, jamais en
        // production — voir StagiaireDemoSeeder. Dépend des comptes créés
        // par Modules\Kernel\Database\Seeders\DemoAccountsSeeder.
        if (app()->environment('local')) {
            $this->call(StagiaireDemoSeeder::class);
        }
    }
}
