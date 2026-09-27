<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Enums\NumerisationStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\SourceDocumentNumerise;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Numérisation du courrier physique — voir docs/numerisation-courrier.md.
 * Le dépôt ne doit jamais être bloqué par une panne de numérisation
 * (coupure de courant, copieur en panne, téléphone déchargé) : le
 * courrier est enregistré quand même, avec numerisation_statut=a_numeriser.
 */
class NumerisationCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_un_depot_avec_piece_jointe_cree_la_version_1_du_document_numerise(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $response = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Correspondance numérisée',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire',
            'mode_reception' => 'porteur',
            'piece_jointe' => UploadedFile::fake()->create('lettre.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $numero = $response->json('data.numero_accuse_reception');
        $courrier = Courrier::query()->where('numero_accuse_reception', $numero)->firstOrFail();

        $this->assertSame(NumerisationStatut::NUMERISE->value, $response->json('data.numerisation_statut'));
        $this->assertCount(1, $courrier->numerisations);

        $version1 = $courrier->numerisations->first();
        $this->assertSame(1, $version1->version);
        $this->assertSame(SourceDocumentNumerise::TELEVERSEMENT, $version1->source);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($version1->chemin)), $version1->sha256);
    }

    public function test_la_reception_peut_enregistrer_sans_numeriser_si_impossible_le_jour_meme(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $response = $this->actingAs($reception)->postJson('/api/v1/courriers', [
            'objet' => 'Correspondance sans scan disponible',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire',
            'mode_reception' => 'porteur',
            'numerisation_impossible' => true,
        ])->assertCreated();

        $this->assertSame(NumerisationStatut::A_NUMERISER->value, $response->json('data.numerisation_statut'));

        $numero = $response->json('data.numero_accuse_reception');
        $courrier = Courrier::query()->where('numero_accuse_reception', $numero)->firstOrFail();
        $this->assertCount(0, $courrier->numerisations);
    }

    public function test_la_reception_ne_peut_toujours_pas_deposer_sans_piece_jointe_ni_derogation(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $this->actingAs($reception)->postJson('/api/v1/courriers', [
            'objet' => 'Correspondance incomplète',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire',
        ])->assertStatus(422);
    }

    public function test_un_courrier_ne_peut_plus_etre_initie_directement_par_une_direction(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $autreDirection = Direction::factory()->create();

        $this->actingAs($responsable)->postJson('/api/v1/courriers', [
            'objet' => 'Note rédigée directement',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'direction_destination_id' => $autreDirection->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('courriers', ['objet' => 'Note rédigée directement']);
    }

    public function test_la_liste_de_rattrapage_ne_contient_que_les_courriers_a_numeriser(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        Courrier::factory()->create(['numerisation_statut' => NumerisationStatut::A_NUMERISER, 'objet' => 'À rattraper', 'created_by' => $reception->id]);
        Courrier::factory()->create(['numerisation_statut' => NumerisationStatut::NUMERISE, 'objet' => 'Déjà numérisé', 'created_by' => $reception->id]);

        $reponse = $this->actingAs($reception)->getJson('/api/v1/courriers/a-numeriser')->assertOk();

        $reponse->assertJsonCount(1, 'data');
        $reponse->assertJsonPath('data.0.objet', 'À rattraper');
    }

    public function test_un_poste_non_concerne_ne_peut_pas_voir_la_liste_de_rattrapage(): void
    {
        $direction = Direction::factory()->create();
        $protocole = $this->agent(Poste::PROTOCOLE, $direction);

        $this->actingAs($protocole)->getJson('/api/v1/courriers/a-numeriser')->assertStatus(403);
    }

    public function test_une_version_numerisee_precise_peut_etre_telechargee(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create(['created_by' => $reception->id]);
        $chemin = UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf')->store('numerisations', 'local');
        $document = $courrier->numerisations()->create([
            'version' => 1,
            'chemin' => $chemin,
            'poids_octets' => 100,
            'sha256' => hash('sha256', 'contenu-test'),
            'source' => SourceDocumentNumerise::TELEPHONE,
        ]);

        $this->actingAs($reception)
            ->get("/api/v1/courriers/{$courrier->id}/numerisations/{$document->id}")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_le_telechargement_refuse_un_document_dun_autre_courrier(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create();
        $autreCourrier = Courrier::factory()->create();
        $chemin = UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf')->store('numerisations', 'local');
        $document = $autreCourrier->numerisations()->create([
            'version' => 1,
            'chemin' => $chemin,
            'poids_octets' => 100,
            'sha256' => hash('sha256', 'contenu-test'),
            'source' => SourceDocumentNumerise::TELEPHONE,
        ]);

        $this->actingAs($reception)
            ->get("/api/v1/courriers/{$courrier->id}/numerisations/{$document->id}")
            ->assertStatus(404);
    }
}
