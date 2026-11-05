<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Kernel\Database\Seeders\AdminUserSeeder;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_en_production_le_seeder_ne_cree_jamais_de_compte_avec_un_mot_de_passe_connu(): void
    {
        $this->app['env'] = 'production';
        config(['kernel.admin_initial_password' => null]);

        (new AdminUserSeeder)->run();

        $admin = User::query()->where('email', 'admin@ont.cd')->first();
        $this->assertNotNull($admin);
        $this->assertFalse(Hash::check('ChangeMoi#ONT2026', $admin->password));
        $this->assertTrue($admin->doit_changer_mot_de_passe);
    }

    public function test_en_production_le_seeder_ne_reinitialise_pas_un_compte_existant(): void
    {
        $this->app['env'] = 'production';
        $admin = User::factory()->administrateur()->create(['email' => 'admin@ont.cd', 'password' => 'MotDePasseChoisi#42']);
        $empreinte = $admin->fresh()->password;

        (new AdminUserSeeder)->run();

        $this->assertSame($empreinte, $admin->fresh()->password);
    }

    public function test_en_production_le_mot_de_passe_initial_vient_de_la_configuration(): void
    {
        $this->app['env'] = 'production';
        config(['kernel.admin_initial_password' => 'Fourni#ParEnv2026']);

        (new AdminUserSeeder)->run();

        $admin = User::query()->where('email', 'admin@ont.cd')->firstOrFail();
        $this->assertTrue(Hash::check('Fourni#ParEnv2026', $admin->password));
    }

    public function test_en_local_le_compte_de_demonstration_garde_son_mot_de_passe_connu(): void
    {
        $this->app['env'] = 'local';

        (new AdminUserSeeder)->run();

        $admin = User::query()->where('email', 'admin@ont.cd')->firstOrFail();
        $this->assertTrue(Hash::check('ChangeMoi#ONT2026', $admin->password));
    }
}
