<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Lot 5 — le lien entre le fichier et le papier : localisation de
 * l'original, sortie/retour, QR de vérification. Voir
 * docs/numerisation-courrier.md.
 */
class LienFichierPapierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_lemplacement_physique_est_rempli_a_lenregistrement(): void
    {
        $direction = Direction::factory()->create();
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::SIGNE]);
        $this->marquerDecharge($courrier);

        $reponse = $this->actingAs($secretariat2)->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
            'classification' => $courrier->classificationAttendue()->value,
            'accuse_reception_partenaire' => $courrier->classificationAttendue() === CourrierClassification::EXTERNE ? 'Signature partenaire' : null,
            'note_technique' => $courrier->classificationAttendue() === CourrierClassification::INTERNE ? 'RAS' : null,
            'emplacement_physique' => 'Armoire 3, chrono 2026',
        ])->assertOk();

        $this->assertSame('Armoire 3, chrono 2026', $reponse->json('data.emplacement_physique'));
    }

    public function test_lemplacement_physique_est_filtrable_dans_la_liste(): void
    {
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::PROTOCOLE, $direction);
        Courrier::factory()->create(['emplacement_physique' => 'Armoire 3, chrono 2026', 'objet' => 'Cible']);
        Courrier::factory()->create(['emplacement_physique' => 'Armoire 1, chrono 2025', 'objet' => 'Autre']);

        $reponse = $this->actingAs($agent)->getJson('/api/v1/courriers?emplacement_physique=Armoire 3')->assertOk();

        $reponse->assertJsonCount(1, 'data');
        $reponse->assertJsonPath('data.0.objet', 'Cible');
    }

    public function test_sortir_puis_restituer_loriginal(): void
    {
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::PROTOCOLE, $direction);
        $courrier = Courrier::factory()->create();

        $this->actingAs($agent)->postJson("/api/v1/courriers/{$courrier->id}/sortir-original", [
            'motif' => 'Consultation par la DG',
        ])->assertCreated();

        $this->assertNotNull($courrier->fresh()->empruntEnCours());

        $this->actingAs($agent)->postJson("/api/v1/courriers/{$courrier->id}/restituer-original")->assertOk();

        $this->assertNull($courrier->fresh()->empruntEnCours());
    }

    public function test_une_sortie_deja_en_cours_ne_peut_pas_etre_dupliquee(): void
    {
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::PROTOCOLE, $direction);
        $courrier = Courrier::factory()->create();

        $this->actingAs($agent)->postJson("/api/v1/courriers/{$courrier->id}/sortir-original", ['motif' => 'Premier emprunt'])
            ->assertCreated();

        $this->actingAs($agent)->postJson("/api/v1/courriers/{$courrier->id}/sortir-original", ['motif' => 'Second emprunt'])
            ->assertStatus(422);
    }

    public function test_la_liste_des_originaux_empruntes_signale_lalerte_au_dela_de_7_jours(): void
    {
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::PROTOCOLE, $direction);
        $courrierRecent = Courrier::factory()->create(['objet' => 'Emprunt récent']);
        $courrierAncien = Courrier::factory()->create(['objet' => 'Emprunt ancien']);

        $courrierRecent->empruntsOriginaux()->create([
            'emprunte_par_id' => $agent->id, 'emprunte_le' => now()->subDays(2), 'motif' => 'Test',
        ]);
        $courrierAncien->empruntsOriginaux()->create([
            'emprunte_par_id' => $agent->id, 'emprunte_le' => now()->subDays(10), 'motif' => 'Test',
        ]);

        $reponse = $this->actingAs($agent)->getJson('/api/v1/courriers/originaux-empruntes')->assertOk();

        $parObjet = collect($reponse->json('data'))->keyBy('objet');
        $this->assertFalse($parObjet['Emprunt récent']['alerte']);
        $this->assertTrue($parObjet['Emprunt ancien']['alerte']);
    }

    public function test_un_poste_non_central_ne_peut_pas_voir_la_liste_des_originaux_empruntes(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $this->actingAs($responsable)->getJson('/api/v1/courriers/originaux-empruntes')->assertStatus(403);
    }
}
