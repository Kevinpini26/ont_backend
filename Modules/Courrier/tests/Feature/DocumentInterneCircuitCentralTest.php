<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Schéma ONT, case 2 : un document INTERNE naît dans une direction (référence
 * attribuée et validation par son Directeur), passe par la Réception (numéro
 * d'enregistrement), SEC1, la DG, puis SEC2 le distribue à la direction
 * destinataire (jamais directement par la DG), qui le traite ; enfin classement
 * et archivage par SEC2.
 */
class DocumentInterneCircuitCentralTest extends CourrierTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_un_document_interne_suit_tout_le_circuit_du_schema_jusqua_larchivage(): void
    {
        $centrale = Direction::factory()->create();
        $productrice = Direction::query()->where('code', 'DMR')->firstOrFail();
        $cible = Direction::query()->where('code', 'DMC')->firstOrFail();
        $reception = $this->agent(Poste::RECEPTION, $centrale);
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $centrale);
        $dg = $this->agent(Poste::DG, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $secProductrice = User::factory()->secretariatDirection($productrice)->create();
        $directeur = User::factory()->directeurDirection($productrice)->create();
        $secCible = User::factory()->secretariatDirection($cible)->create();
        $directeurCible = User::factory()->directeurDirection($cible)->create();
        $contenu = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Note interne.']]]]];

        // Direction productrice : rédaction, soumission, validation par le Directeur (référence ONT).
        $creation = $this->actingAs($secProductrice)->postJson('/api/v1/documents-produits-direction/autonomes', [
            'objet' => 'Note interne DMR',
            'contenu' => $contenu,
            'direction_destination_souhaitee_id' => $cible->id,
        ])->assertCreated();
        $docId = $creation->json('data.id');
        $courrierId = $creation->json('data.courrier.id');
        $this->assertNull(Courrier::withoutGlobalScopes()->findOrFail($courrierId)->dossier_id);

        $this->actingAs($secProductrice)->postJson("/api/v1/documents-produits-direction/{$docId}/soumettre", [
            'contenu' => $contenu, 'version_contenu' => 1,
        ])->assertOk();
        $reference = $this->actingAs($directeur)->postJson("/api/v1/documents-produits-direction/{$docId}/valider")
            ->assertOk()->json('data.courrier.reference_documentaire');
        $this->assertSame('001/ONT/DMR/'.now()->year, $reference);

        $document = DocumentProduitDirection::query()->findOrFail($docId);
        $pdf = Storage::disk('local')->get($document->pdf_officiel_chemin);
        $this->actingAs($secProductrice)->post("/api/v1/documents-produits-direction/{$docId}/scan-officiel", [
            'scan' => UploadedFile::fake()->createWithContent('scan.pdf', $pdf),
            'attestation_confirmee' => 'true',
            'idempotency_key' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($secProductrice)->postJson("/api/v1/documents-produits-direction/{$docId}/transmettre-reception")->assertOk();

        // Règle 1 : la Réception enregistre et attribue le numéro ; règle 2 : il ne changera plus.
        $this->actingAs($reception)->postJson("/api/v1/documents-produits-direction/{$docId}/accepter-reception")->assertOk();
        $numero = Courrier::withoutGlobalScopes()->findOrFail($courrierId)->numero_enregistrement;
        $this->assertNotNull($numero);

        // SEC1 puis DG.
        $this->actingAs($sec1)->postJson("/api/v1/documents-produits-direction/{$docId}/prendre-en-charge-sec1")->assertOk();
        $this->actingAs($sec1)->postJson("/api/v1/documents-produits-direction/{$docId}/transmettre-dg-sec1")->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrierId}/accuser-reception")->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/documents-produits-direction/{$docId}/decision-dg", ['direction_id' => $cible->id])->assertOk();
        $dispatch = DispatchCourrier::query()->where('courrier_id', $courrierId)->firstOrFail();

        // Règles 5 et 6 : la direction destinataire ne reçoit rien de la DG ; seul SEC2 distribue.
        $this->actingAs($secCible)->getJson("/api/v1/courriers/{$courrierId}")->assertNotFound();
        $this->actingAs($dg)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertForbidden();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrierId}/accuser-reception")->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertOk();

        // La direction destinataire voit le document, qui a désormais un dossier.
        $this->actingAs($secCible)->getJson("/api/v1/courriers/{$courrierId}")->assertOk();
        $this->assertNotNull(Courrier::withoutGlobalScopes()->findOrFail($courrierId)->dossier_id);
        $this->actingAs($secCible)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();

        // Traitement dans la direction : secrétariat → Directeur → décision.
        $traitement = TraitementDirection::query()->where('dispatch_courrier_id', $dispatch->id)->firstOrFail();
        $this->actingAs($secCible)->postJson("/api/v1/traitements-direction/{$traitement->id}/transmettre-directeur")->assertOk();
        $this->actingAs($directeurCible)->postJson("/api/v1/traitements-direction/{$traitement->id}/prendre-en-charge")->assertOk();
        $this->actingAs($directeurCible)->postJson("/api/v1/traitements-direction/{$traitement->id}/decision", ['decision' => 'traitement_termine'])->assertOk();

        // Classement et archivage via SEC2.
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrierId}/dispatchs", ['destinations' => [[
            'type' => 'classement', 'instruction' => 'À classer',
        ]]])->assertOk();
        $dispatchClassement = DispatchCourrier::query()->where('courrier_id', $courrierId)->latest('id')->firstOrFail();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrierId}/accuser-reception")->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatchClassement->id}/classer", [
            'cote' => 'ONT-DMR-001', 'emplacement' => 'Archives',
        ])->assertOk();
        $classement = Courrier::withoutGlobalScopes()->findOrFail($courrierId)->classement()->firstOrFail();
        $this->actingAs($sec2)->postJson("/api/v1/classements-documents/{$classement->id}/archiver")->assertOk();

        $final = Courrier::withoutGlobalScopes()->findOrFail($courrierId);
        $this->assertTrue($final->estArchive());
        $this->assertSame($numero, $final->numero_enregistrement);
        $this->assertSame($reference, $final->reference_documentaire);
    }

    public function test_la_direction_destinataire_est_facultative_et_ne_peut_pas_etre_la_direction_productrice(): void
    {
        $productrice = Direction::factory()->create();
        $secProductrice = User::factory()->secretariatDirection($productrice)->create();
        User::factory()->directeurDirection($productrice)->create();
        $contenu = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        $this->actingAs($secProductrice)->postJson('/api/v1/documents-produits-direction/autonomes', [
            'objet' => 'Sans destinataire souhaité', 'contenu' => $contenu,
        ])->assertCreated();

        $this->actingAs($secProductrice)->postJson('/api/v1/documents-produits-direction/autonomes', [
            'objet' => 'Vers soi-même', 'contenu' => $contenu, 'direction_destination_souhaitee_id' => $productrice->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('direction_destination_souhaitee_id');
    }
}
