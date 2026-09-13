<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Events\CourrierStageAvisFavorable;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\DocumentType;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

class CreationAutomatiqueFicheStagiaireTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_la_fiche_stagiaire_est_creee_automatiquement_sans_ressaisie(): void
    {
        $direction = Direction::factory()->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, $direction)->create();

        $courrier = Courrier::factory()->demandeStage()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
        ]);
        $this->marquerDecharge($courrier);

        $this->assertDatabaseMissing('stagiaires', ['courrier_id' => $courrier->id]);

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertOk();

        $stagiaire = Stagiaire::query()->where('courrier_id', $courrier->id)->first();

        $this->assertNotNull($stagiaire);
        $this->assertSame(StagiaireStatut::DOSSIER_RECU, $stagiaire->statut);
        $this->assertSame($courrier->candidat_nom, $stagiaire->nom);
        $this->assertSame($courrier->candidat_contact, $stagiaire->contact);
        $this->assertSame($courrier->candidat_etablissement, $stagiaire->etablissement_origine);
        $this->assertSame($courrier->numero_accuse_reception, $stagiaire->reference_courrier);
    }

    /**
     * candidat_contact (téléphone) est facultatif au dépôt public — seul
     * candidat_email est requis (voir DeposerDemandeStageRequest). Un
     * candidat qui ne renseigne pas de téléphone (cas courant, jamais
     * couvert par CourrierFactory::demandeStage() qui remplit toujours
     * candidat_contact) ne doit pas faire échouer la création de la fiche
     * sur la contrainte NOT NULL de stagiaires.contact.
     */
    public function test_la_fiche_stagiaire_utilise_lemail_comme_contact_si_aucun_telephone_nest_fourni(): void
    {
        $direction = Direction::factory()->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, $direction)->create();

        $courrier = Courrier::factory()->demandeStage()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'candidat_contact' => null,
            'candidat_email' => 'candidate-sans-telephone@example.cd',
        ]);
        $this->marquerDecharge($courrier);

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertOk();

        $stagiaire = Stagiaire::query()->where('courrier_id', $courrier->id)->first();

        $this->assertNotNull($stagiaire);
        $this->assertSame('candidate-sans-telephone@example.cd', $stagiaire->contact);
    }

    public function test_la_lettre_de_stage_est_copiee_dans_le_dossier_stagiaire_sans_redepot(): void
    {
        Storage::fake('local');

        $cheminOrigine = UploadedFile::fake()->create('lettre-stage.pdf', 100, 'application/pdf')
            ->store('lettres-stage', 'local');

        $courrier = Courrier::factory()->demandeStage()->create([
            'lettre_stage_chemin' => $cheminOrigine,
        ]);

        event(new CourrierStageAvisFavorable($courrier));

        $stagiaire = Stagiaire::query()->where('courrier_id', $courrier->id)->firstOrFail();
        $document = $stagiaire->documents()->where('type', DocumentType::LETTRE_STAGE_UNIVERSITE)->first();

        $this->assertNotNull($document);
        Storage::disk('local')->assertExists($document->chemin);
    }

    public function test_les_quatre_pieces_professionnelles_sont_copiees_et_pas_la_lettre_de_stage(): void
    {
        Storage::fake('local');

        $courrier = Courrier::factory()->demandeStage()->professionnel()->create([
            'lettre_stage_chemin' => null,
            'lettre_demande_chemin' => UploadedFile::fake()->create('lettre-demande.pdf', 100, 'application/pdf')->store('lettres-demande-stage', 'local'),
            'cv_chemin' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')->store('cv-candidats', 'local'),
            'diplome_etat_chemin' => UploadedFile::fake()->create('diplome-etat.pdf', 100, 'application/pdf')->store('diplomes-etat', 'local'),
            'dernier_diplome_chemin' => UploadedFile::fake()->create('dernier-diplome.pdf', 100, 'application/pdf')->store('derniers-diplomes', 'local'),
        ]);

        event(new CourrierStageAvisFavorable($courrier));

        $stagiaire = Stagiaire::query()->where('courrier_id', $courrier->id)->firstOrFail();

        $this->assertSame('professionnel', $stagiaire->type_stage->value);
        $this->assertNull($stagiaire->documents()->where('type', DocumentType::LETTRE_STAGE_UNIVERSITE)->first());

        foreach ([DocumentType::LETTRE_DEMANDE_STAGE, DocumentType::CV, DocumentType::DIPLOME_ETAT, DocumentType::DERNIER_DIPLOME] as $type) {
            $document = $stagiaire->documents()->where('type', $type)->first();
            $this->assertNotNull($document, "Document manquant : {$type->value}");
            Storage::disk('local')->assertExists($document->chemin);
        }
    }

    public function test_levenement_ne_cree_pas_de_doublon_si_rejoue(): void
    {
        $direction = Direction::factory()->create();
        $courrier = Courrier::factory()->demandeStage()->create();

        event(new CourrierStageAvisFavorable($courrier));
        event(new CourrierStageAvisFavorable($courrier));

        $this->assertSame(1, Stagiaire::query()->where('courrier_id', $courrier->id)->count());
    }
}
