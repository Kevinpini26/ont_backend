<?php

namespace Modules\Courrier\Database\Seeders;

use Illuminate\Database\Seeder;

class CourrierDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Dossiers de démonstration (un par étape atteignable du circuit) :
        // uniquement en local, jamais en production — voir CourrierDemoSeeder.
        // Dépend des comptes créés par Modules\Kernel\Database\Seeders\DemoAccountsSeeder.
        if (app()->environment('local')) {
            $this->call(CourrierDemoSeeder::class);
        }
    }
}
