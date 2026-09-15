<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

class CourrierEnSouffranceTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_un_courrier_en_attente_depuis_plus_longtemps_que_le_delai_apparait_en_souffrance(): void
    {
        $direction = Direction::factory()->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'necessite_avis_dg' => true]);
        // Bordereau vieux de 72h > délai indicatif de 48h pour ce statut.
        $courrier->transitions()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'accuse_reception_at' => now()->subHours(72),
            'created_at' => now()->subHours(72),
        ]);
        $dg = $this->agent(Poste::DG, $direction);

        $response = $this->actingAs($dg)->getJson('/api/v1/courriers/en-souffrance')->assertOk();

        $response->assertJsonPath('data.0.courrier.id', $courrier->id)
            ->assertJsonPath('data.0.niveau', 1);
    }

    public function test_un_courrier_recent_napparait_pas_en_souffrance(): void
    {
        $direction = Direction::factory()->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'necessite_avis_dg' => true]);
        $this->marquerDecharge($courrier);
        $dg = $this->agent(Poste::DG, $direction);

        $this->actingAs($dg)->getJson('/api/v1/courriers/en-souffrance')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_le_niveau_de_gravite_augmente_avec_lanciennete(): void
    {
        $direction = Direction::factory()->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'necessite_avis_dg' => true]);
        // 3x le délai indicatif (48h) : niveau maximal.
        $courrier->transitions()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'accuse_reception_at' => now()->subHours(150),
            'created_at' => now()->subHours(150),
        ]);
        $dg = $this->agent(Poste::DG, $direction);

        $this->actingAs($dg)->getJson('/api/v1/courriers/en-souffrance')
            ->assertOk()
            ->assertJsonPath('data.0.niveau', 3);
    }

    public function test_seuls_les_courriers_actionnables_par_le_poste_apparaissent(): void
    {
        $direction = Direction::factory()->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'necessite_avis_dg' => true]);
        $courrier->transitions()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'accuse_reception_at' => now()->subHours(72),
            'created_at' => now()->subHours(72),
        ]);
        // Le Protocole n'est pas habilité à agir sur en_attente_avis_dg
        // (seuls DG/DGA le sont) : ne doit rien voir.
        $protocole = $this->agent(Poste::PROTOCOLE, $direction);

        $this->actingAs($protocole)->getJson('/api/v1/courriers/en-souffrance')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * PROJET_A_VALIDER joue le même rôle qu'EN_RELECTURE (voir
     * Courrier::enAttenteValidationRelecteur()) : actionnable par le
     * relecteur désigné, jamais par un poste — même un autre assistant.
     */
    public function test_un_dossier_projet_a_valider_napparait_que_pour_le_relecteur_designe(): void
    {
        $direction = Direction::factory()->create();
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $autreAssistant = $this->agent(Poste::ASSISTANT_2, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::PROJET_A_VALIDER,
            'necessite_avis_dg' => true,
            'relecteur_id' => $relecteur->id,
        ]);
        $courrier->transitions()->create([
            'statut' => CourrierStatut::PROJET_A_VALIDER,
            'accuse_reception_at' => now()->subHours(48),
            'created_at' => now()->subHours(48),
        ]);

        $this->actingAs($relecteur)->getJson('/api/v1/courriers/en-souffrance')
            ->assertOk()
            ->assertJsonPath('data.0.courrier.id', $courrier->id);

        $this->actingAs($autreAssistant)->getJson('/api/v1/courriers/en-souffrance')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Le classeur d'attente décore aussi l'ancienneté (voir
     * docs/questions-ont.md) : il ne remplace pas les statuts, il les
     * décore — le Secrétariat 01, seul habilité sur en_attente_classeur,
     * doit voir un dossier oublié au classeur.
     */
    public function test_un_dossier_au_classeur_dattente_apparait_pour_le_secretariat_01(): void
    {
        $direction = Direction::factory()->create();
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_CLASSEUR,
            'necessite_avis_dg' => true,
            'degre_urgence' => 'normal',
        ]);
        $courrier->transitions()->create([
            'statut' => CourrierStatut::EN_ATTENTE_CLASSEUR,
            'accuse_reception_at' => now()->subHours(72),
            'created_at' => now()->subHours(72),
        ]);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $this->actingAs($secretariat1)->getJson('/api/v1/courriers/en-souffrance')
            ->assertOk()
            ->assertJsonPath('data.0.courrier.id', $courrier->id);
    }
}
