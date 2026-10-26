<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class PreparerDemonstrationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_commande_unifie_le_mot_de_passe_et_deverrouille_les_comptes(): void
    {
        $compte = User::factory()->create([
            'echecs_connexion_consecutifs' => 5,
            'verrouille_jusqu_a' => now()->addHour(),
        ]);

        $this->artisan('kernel:preparer-demonstration', ['mot_de_passe' => 'MotDePasseDemo#2026'])->assertSuccessful();

        $compte->refresh();
        $this->assertTrue(Hash::check('MotDePasseDemo#2026', $compte->password));
        $this->assertSame(0, $compte->echecs_connexion_consecutifs);
        $this->assertNull($compte->verrouille_jusqu_a);
    }

    public function test_la_commande_refuse_un_mot_de_passe_trop_court(): void
    {
        $this->artisan('kernel:preparer-demonstration', ['mot_de_passe' => 'court'])->assertFailed();
    }
}
