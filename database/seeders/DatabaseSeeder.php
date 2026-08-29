<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Courrier\Database\Seeders\CourrierDatabaseSeeder;
use Modules\Kernel\Database\Seeders\KernelDatabaseSeeder;
use Modules\Stagiaires\Database\Seeders\StagiairesDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ordre obligatoire : Kernel crée les directions et les comptes de
        // démonstration dont CourrierDemoSeeder/StagiaireDemoSeeder ont
        // besoin (voir chacun des deux DatabaseSeeder de module).
        $this->call([
            KernelDatabaseSeeder::class,
            CourrierDatabaseSeeder::class,
            StagiairesDatabaseSeeder::class,
        ]);
    }
}
