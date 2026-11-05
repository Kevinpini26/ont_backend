<?php

namespace Modules\Courrier\Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Models\Courrier;
use Tests\TestCase;

class SeedDemonstrationRejouableTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_seed_complet_reussit_sur_une_base_neuve_et_peut_etre_rejoue(): void
    {
        Storage::fake('local');
        $this->app['env'] = 'local';

        $this->seed(DatabaseSeeder::class);
        $nombre = Courrier::withoutGlobalScopes()->count();
        $this->assertGreaterThan(0, $nombre);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($nombre, Courrier::withoutGlobalScopes()->count());
    }
}
