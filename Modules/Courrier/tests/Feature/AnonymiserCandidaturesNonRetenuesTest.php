<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Console\AnonymiserCandidaturesNonRetenuesCommand;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\ClassementDocument;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierAnnotation;
use Modules\Kernel\Models\User;

class AnonymiserCandidaturesNonRetenuesTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_une_candidature_non_retenue_depuis_plus_de_12_mois_est_anonymisee(): void
    {
        $courrier = Courrier::factory()->demandeStage()->create([
            'avis_dg' => AvisDg::DEFAVORABLE,
            'avis_dg_rendu_at' => now()->subMonths(13),
            'avis_dg_commentaire' => 'Profil ne correspondant pas aux besoins.',
            'note_technique' => 'Voir dossier papier.',
        ]);
        CourrierAnnotation::query()->create([
            'courrier_id' => $courrier->id,
            'auteur_id' => User::factory()->create()->id,
            'contenu' => 'Note interne sur le candidat.',
        ]);

        $this->artisan(AnonymiserCandidaturesNonRetenuesCommand::class)->assertSuccessful();

        $courrier->refresh();
        $this->assertNull($courrier->candidat_nom);
        $this->assertNull($courrier->candidat_contact);
        $this->assertNull($courrier->candidat_etablissement);
        $this->assertNull($courrier->avis_dg_commentaire);
        $this->assertNull($courrier->note_technique);
        $this->assertSame('Candidature anonymisée', $courrier->objet);
        $this->assertNotNull($courrier->anonymise_at);
        $this->assertCount(0, $courrier->annotations()->get());

        // Les données statistiques, elles, restent intactes.
        $this->assertSame(AvisDg::DEFAVORABLE, $courrier->avis_dg);
        $this->assertSame(CourrierType::DEMANDE_STAGE, $courrier->type);
        $this->assertNotNull($courrier->direction_destination_id);
    }

    public function test_une_candidature_non_retenue_archivee_peut_etre_anonymisee_sans_ouvrir_le_document_ni_changer_son_classement(): void
    {
        Storage::fake('local');

        $courrier = Courrier::factory()->demandeStage()->create([
            'avis_dg' => AvisDg::DEFAVORABLE,
            'avis_dg_rendu_at' => now()->subMonths(13),
            'candidat_nom' => 'Alice Exemple',
            'candidat_contact' => 'alice@example.test',
            'candidat_etablissement' => 'Université de Test',
            'expediteur_externe_nom' => 'Établissement partenaire',
            'objet' => 'Candidature pour un stage 2026',
            'contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Bonjour']]]]],
            'avis_dg_commentaire' => 'Profil non adapté.',
            'note_technique' => 'Dossier papier à conserver.',
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Réponse']]]]],
            'relecture_commentaire' => 'À vérifier',
            'lettre_stage_chemin' => 'courriers/lettre-stage.pdf',
            'cv_chemin' => 'courriers/cv.pdf',
            'diplome_etat_chemin' => 'courriers/diplome-etat.pdf',
            'dernier_diplome_chemin' => 'courriers/dernier-diplome.pdf',
            'lettre_demande_chemin' => 'courriers/lettre-demande.pdf',
        ]);

        foreach ([
            'courriers/lettre-stage.pdf',
            'courriers/cv.pdf',
            'courriers/diplome-etat.pdf',
            'courriers/dernier-diplome.pdf',
            'courriers/lettre-demande.pdf',
        ] as $chemin) {
            Storage::disk('local')->put($chemin, 'contenu de test');
        }

        CourrierAnnotation::query()->create([
            'courrier_id' => $courrier->id,
            'auteur_id' => User::factory()->create()->id,
            'contenu' => 'Annotation interne sur le candidat.',
        ]);

        $dispatch = $courrier->dispatchs()->create([
            'dossier_id' => $courrier->dossier_id,
            'cycle' => 1,
            'type_destination' => 'classement',
            'instruction' => 'À classer',
            'decisionnaire_id' => User::factory()->create()->id,
            'decisionnaire_poste' => 'dg',
            'autorite_poste' => 'dg',
            'decide_at' => now(),
            'statut' => 'execute',
        ]);

        $courrier->classement()->create([
            'dossier_id' => $courrier->dossier_id,
            'dispatch_courrier_id' => $dispatch->id,
            'statut' => 'archive',
            'cote' => 'COTE-ARCHIVE-1',
            'emplacement' => 'Archives permanentes',
            'classe_par_id' => User::factory()->create()->id,
            'classe_at' => now(),
            'archive_par_id' => User::factory()->create()->id,
            'archive_at' => now(),
        ]);

        $courrier->refresh();
        $this->assertTrue($courrier->estArchive());
        $this->assertNull($courrier->anonymise_at);
        $this->assertDatabaseHas('courriers', ['id' => $courrier->id, 'candidat_nom' => 'Alice Exemple']);
        $this->assertDatabaseHas('courrier_annotations', ['courrier_id' => $courrier->id, 'contenu' => 'Annotation interne sur le candidat.']);
        $this->assertTrue(Storage::disk('local')->exists('courriers/cv.pdf'));

        $this->artisan(AnonymiserCandidaturesNonRetenuesCommand::class)->assertSuccessful();

        $courrier->refresh();
        $this->assertNull($courrier->candidat_nom);
        $this->assertNull($courrier->candidat_contact);
        $this->assertNull($courrier->candidat_etablissement);
        $this->assertNull($courrier->expediteur_externe_nom);
        $this->assertNull($courrier->avis_dg_commentaire);
        $this->assertNull($courrier->note_technique);
        $this->assertSame('Candidature anonymisée', $courrier->objet);
        $this->assertNotNull($courrier->anonymise_at);
        $this->assertSame(0, $courrier->annotations()->count());
        $this->assertFalse(Storage::disk('local')->exists('courriers/cv.pdf'));
        $this->assertTrue($courrier->estArchive());
        $this->assertSame('COTE-ARCHIVE-1', $courrier->classement()->sole()->cote);
        $this->assertSame('Archives permanentes', $courrier->classement()->sole()->emplacement);
        $this->assertSame('Candidature anonymisée', $courrier->objet);
        $this->assertSame('archive', $courrier->classement()->sole()->statut->value);
        $this->assertNotNull($courrier->fresh()->classement()->sole()->archive_at);
    }

    public function test_un_courrier_archive_refuse_une_modification_metier_ordinaire_hors_anonymisation(): void
    {
        $courrier = Courrier::factory()->demandeStage()->create([
            'avis_dg' => AvisDg::DEFAVORABLE,
            'avis_dg_rendu_at' => now()->subMonths(13),
        ]);

        $dispatch = $courrier->dispatchs()->create([
            'dossier_id' => $courrier->dossier_id,
            'cycle' => 1,
            'type_destination' => 'classement',
            'instruction' => 'À classer',
            'decisionnaire_id' => User::factory()->create()->id,
            'decisionnaire_poste' => 'dg',
            'autorite_poste' => 'dg',
            'decide_at' => now(),
            'statut' => 'execute',
        ]);

        $courrier->classement()->create([
            'dossier_id' => $courrier->dossier_id,
            'dispatch_courrier_id' => $dispatch->id,
            'statut' => 'archive',
            'cote' => 'COTE-ARCHIVE-1',
            'emplacement' => 'Archives permanentes',
            'classe_par_id' => User::factory()->create()->id,
            'classe_at' => now(),
            'archive_par_id' => User::factory()->create()->id,
            'archive_at' => now(),
        ]);

        $courrier->refresh();
        $this->assertTrue($courrier->estArchive());

        try {
            $courrier->update(['objet' => 'objet modifié']);
            $this->fail('Un courrier archivé doit refuser une mutation métier ordinaire.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('courrier', $e->errors());
            $this->assertSame('Un document archivé ne peut plus recevoir de mutation métier.', $e->errors()['courrier'][0]);
        }

        $courrier->refresh();
        $this->assertNotSame('objet modifié', $courrier->objet);
        $this->assertTrue($courrier->estArchive());
    }

    public function test_une_candidature_non_retenue_depuis_moins_de_12_mois_nest_pas_anonymisee(): void
    {
        $courrier = Courrier::factory()->demandeStage()->create([
            'avis_dg' => AvisDg::DEFAVORABLE,
            'avis_dg_rendu_at' => now()->subMonths(6),
        ]);

        $this->artisan(AnonymiserCandidaturesNonRetenuesCommand::class)->assertSuccessful();

        $courrier->refresh();
        $this->assertNotNull($courrier->candidat_nom);
        $this->assertNull($courrier->anonymise_at);
    }

    public function test_une_candidature_retenue_avis_favorable_nest_jamais_anonymisee(): void
    {
        $courrier = Courrier::factory()->demandeStage()->create([
            'avis_dg' => AvisDg::FAVORABLE,
            'avis_dg_rendu_at' => now()->subMonths(24),
        ]);

        $this->artisan(AnonymiserCandidaturesNonRetenuesCommand::class)->assertSuccessful();

        $courrier->refresh();
        $this->assertNotNull($courrier->candidat_nom);
        $this->assertNull($courrier->anonymise_at);
    }

    public function test_un_courrier_qui_nest_pas_une_demande_de_stage_nest_pas_anonymise(): void
    {
        $courrier = Courrier::factory()->create([
            'avis_dg' => AvisDg::DEFAVORABLE,
            'avis_dg_rendu_at' => now()->subMonths(24),
        ]);

        $this->artisan(AnonymiserCandidaturesNonRetenuesCommand::class)->assertSuccessful();

        $this->assertNull($courrier->fresh()->anonymise_at);
    }

    public function test_une_seconde_execution_ne_retraite_pas_un_dossier_deja_anonymise(): void
    {
        $courrier = Courrier::factory()->demandeStage()->create([
            'avis_dg' => AvisDg::DEFAVORABLE,
            'avis_dg_rendu_at' => now()->subMonths(13),
        ]);

        $this->artisan(AnonymiserCandidaturesNonRetenuesCommand::class)->assertSuccessful();
        $premiereAnonymisation = $courrier->fresh()->anonymise_at;

        $this->travel(1)->days();
        $this->artisan(AnonymiserCandidaturesNonRetenuesCommand::class)->assertSuccessful();

        $this->assertEquals($premiereAnonymisation, $courrier->fresh()->anonymise_at);
    }

    public function test_le_seuil_de_conservation_est_configurable(): void
    {
        $courrier = Courrier::factory()->demandeStage()->create([
            'avis_dg' => AvisDg::DEFAVORABLE,
            'avis_dg_rendu_at' => now()->subMonths(2),
        ]);

        $this->artisan(AnonymiserCandidaturesNonRetenuesCommand::class, ['--seuil-mois' => 1])->assertSuccessful();

        $this->assertNotNull($courrier->fresh()->anonymise_at);
    }
}
