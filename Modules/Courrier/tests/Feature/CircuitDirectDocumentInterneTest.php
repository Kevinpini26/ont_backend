<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Notifications\DocumentCircuitDirectNotification;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\Setting;
use Modules\Kernel\Models\User;

/**
 * Lot 6, point 3 : circuit direct entre directions, DÉSACTIVÉ par défaut. Le schéma officiel (Réception, SEC1,
 * DG, SEC2) reste obligatoire tant que l'administrateur ne l'active pas, et toujours pour les types exclus.
 */
class CircuitDirectDocumentInterneTest extends CourrierTestCase
{
    use RefreshDatabase;

    private Direction $productrice;

    private Direction $cible;

    private User $secProductrice;

    private User $directeur;

    private User $secCible;

    private User $dg;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->productrice = Direction::query()->where('code', 'DMR')->firstOrFail();
        $this->cible = Direction::query()->where('code', 'DMC')->firstOrFail();
        $this->secProductrice = User::factory()->secretariatDirection($this->productrice)->create();
        $this->directeur = User::factory()->directeurDirection($this->productrice)->create();
        $this->secCible = User::factory()->secretariatDirection($this->cible)->create();
        $this->dg = $this->agent(Poste::DG, Direction::factory()->create());
        $this->admin = User::factory()->administrateur()->create();
    }

    /** Document autonome rédigé, validé (référence officielle), PDF, scan signé déposé. */
    private function documentScanne(string $nature = 'note_de_service', ?int $destination = null, string $confidentialite = 'ordinaire'): DocumentProduitDirection
    {
        $contenu = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Note de service.']]]]];
        $creation = $this->actingAs($this->secProductrice)->postJson('/api/v1/documents-produits-direction/autonomes', [
            'objet' => 'Note de service DMR', 'contenu' => $contenu, 'nature' => $nature,
            // 0 : aucune destination (document pour la DG, qui l'oriente elle-même).
            'direction_destination_souhaitee_id' => $destination === 0 ? null : ($destination ?? $this->cible->id),
        ])->assertCreated();
        $id = $creation->json('data.id');
        Courrier::withoutGlobalScopes()->whereKey($creation->json('data.courrier.id'))->update(['niveau_confidentialite' => $confidentialite]);
        $this->actingAs($this->secProductrice)->postJson("/api/v1/documents-produits-direction/{$id}/soumettre", ['contenu' => $contenu, 'version_contenu' => 1])->assertOk();
        $this->actingAs($this->directeur)->postJson("/api/v1/documents-produits-direction/{$id}/valider")->assertOk();
        $document = DocumentProduitDirection::query()->findOrFail($id);
        $this->actingAs($this->secProductrice)->post("/api/v1/documents-produits-direction/{$id}/scan-officiel", [
            'scan' => UploadedFile::fake()->createWithContent('scan.pdf', Storage::disk('local')->get($document->pdf_officiel_chemin)),
            'attestation_confirmee' => 'true', 'idempotency_key' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])->assertCreated();

        return $document->fresh();
    }

    private function activer(array $natures = ['note_de_service']): void
    {
        $this->actingAs($this->admin)->putJson('/api/v1/parametres/circuit_direct.actif', ['valeur' => true])->assertOk();
        $this->actingAs($this->admin)->putJson('/api/v1/parametres/circuit_direct.natures_autorisees', ['valeur' => $natures])->assertOk();
    }

    private function transmettreDirect(DocumentProduitDirection $document)
    {
        return $this->actingAs($this->secProductrice)->postJson("/api/v1/documents-produits-direction/{$document->id}/transmettre-direct");
    }

    public function test_desactive_par_defaut_le_circuit_central_reste_obligatoire(): void
    {
        $document = $this->documentScanne();

        $this->transmettreDirect($document)->assertUnprocessable()->assertJsonPath('errors.document.0', 'Le circuit direct est désactivé : ce document passe par la Réception.');
        $this->actingAs($this->secProductrice)->getJson("/api/v1/documents-produits-direction/{$document->id}")
            ->assertOk()->assertJsonPath('data.peut_transmettre_direct', false);
        $this->actingAs($this->secProductrice)->postJson("/api/v1/documents-produits-direction/{$document->id}/transmettre-reception")->assertOk();
    }

    public function test_active_une_note_de_service_va_directement_au_secretariat_destinataire_avec_copie_a_la_dg(): void
    {
        $this->activer();
        $document = $this->documentScanne();

        $this->actingAs($this->secProductrice)->getJson("/api/v1/documents-produits-direction/{$document->id}")
            ->assertOk()->assertJsonPath('data.peut_transmettre_direct', true);
        $this->transmettreDirect($document)->assertOk()->assertJsonPath('data.statut', 'transmis_direction');

        $courrier = Courrier::withoutGlobalScopes()->findOrFail($document->courrier_id);
        $this->assertMatchesRegularExpression('/^'.now()->year.'-\d{4,}$/', (string) $courrier->numero_enregistrement, 'Enregistré : numéro d’arrivée.');
        $this->assertSame(1, $this->secCible->fresh()->notifications()->where('type', DocumentCircuitDirectNotification::class)->count());
        $this->assertSame(1, $this->dg->fresh()->notifications()->where('type', DocumentCircuitDirectNotification::class)->count(), 'Copie pour information à la DG.');

        $this->actingAs($this->secCible)->getJson('/api/v1/documents-produits-direction/recus-direct')
            ->assertOk()->assertJsonPath('data.0.id', $document->id);
        $this->actingAs($this->secCible)->postJson("/api/v1/documents-produits-direction/{$document->id}/accuser-reception-direct")
            ->assertOk()->assertJsonPath('data.recu_destination', true);
        $this->assertSame(1, AuditLog::query()->where('action', 'document_direction.circuit_direct_recu')->count());
    }

    public function test_les_types_exclus_restent_toujours_dans_le_circuit_central(): void
    {
        $this->activer(['note_de_service', 'compte_rendu']);

        $exclus = [
            'nature non autorisée' => $this->documentScanne('note_interne'),
            'engagement financier' => $this->documentScanne('engagement_financier'),
            'contrat' => $this->documentScanne('contrat'),
            // La DG ne peut pas être désignée comme destinataire : un document pour elle se crée sans destination.
            'adressé à la DG' => $this->documentScanne('note_de_service', 0),
            'confidentiel' => $this->documentScanne('note_de_service', null, 'confidentiel'),
            'secret' => $this->documentScanne('note_de_service', null, 'secret'),
        ];

        foreach ($exclus as $cas => $document) {
            $this->transmettreDirect($document)->assertUnprocessable();
            $this->assertNull(Courrier::withoutGlobalScopes()->findOrFail($document->courrier_id)->numero_enregistrement, $cas);
        }
    }

    public function test_un_engagement_financier_ne_peut_jamais_etre_autorise_et_lactivation_est_auditee(): void
    {
        $this->actingAs($this->admin)->putJson('/api/v1/parametres/circuit_direct.natures_autorisees', ['valeur' => ['engagement_financier']])->assertUnprocessable();
        $this->actingAs($this->dg)->putJson('/api/v1/parametres/circuit_direct.actif', ['valeur' => true])->assertForbidden();
        $this->activer();

        $this->assertTrue((bool) Setting::valeur('circuit_direct.actif'));
        $this->assertTrue(AuditLog::query()->where('action', 'parametre.modifie')->get()->contains(fn ($log) => ($log->meta['cle'] ?? null) === 'circuit_direct.actif'));
    }
}
