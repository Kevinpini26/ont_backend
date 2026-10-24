<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Models\Courrier;
use Tests\TestCase;

class PrevisualiserEmailCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CLES = [
        'accuse-reception-candidat',
        'accuse-reception-courrier-externe',
        'avis-dg-en-attente',
        'courrier-recu',
        'reponse-finale-courrier-externe',
        'stage-echeance-proche',
        'stagiaire-affecte',
    ];

    protected function tearDown(): void
    {
        foreach (self::CLES as $cle) {
            @unlink(storage_path("app/previsualisations-email/{$cle}.html"));
        }

        parent::tearDown();
    }

    public function test_lister_affiche_les_cles_disponibles_sans_rien_generer(): void
    {
        $this->artisan('mail:previsualiser', ['--lister' => true])
            ->assertSuccessful()
            ->expectsTable(['Clé'], [
                ['accuse-reception-candidat'],
                ['accuse-reception-courrier-externe'],
                ['avis-dg-en-attente'],
                ['courrier-recu'],
                ['reponse-finale-courrier-externe'],
                ['stage-echeance-proche'],
                ['stagiaire-affecte'],
            ]);
    }

    public function test_une_cle_inconnue_echoue_proprement(): void
    {
        $this->artisan('mail:previsualiser', ['mailable' => 'inconnue'])->assertFailed();
    }

    public function test_generer_une_previsualisation_produit_un_fichier_html_et_nannule_aucune_donnee(): void
    {
        $chemin = storage_path('app/previsualisations-email/reponse-finale-courrier-externe.html');
        $nombreCourriersAvant = Courrier::withoutGlobalScopes()->count();

        $this->artisan('mail:previsualiser', ['mailable' => 'reponse-finale-courrier-externe'])->assertSuccessful();

        $this->assertFileExists($chemin);
        $this->assertStringContainsString('Signataire', file_get_contents($chemin));
        $this->assertSame($nombreCourriersAvant, Courrier::withoutGlobalScopes()->count());
    }

    /**
     * Les 7 Mailables du projet doivent chacun pouvoir être construits à
     * partir de données fictives et rendus sans erreur — garde contre une
     * régression silencieuse dans une des 7 branches de construire().
     */
    public function test_chacune_des_sept_cles_genere_un_fichier_non_vide(): void
    {
        foreach (self::CLES as $cle) {
            $this->artisan('mail:previsualiser', ['mailable' => $cle])->assertSuccessful();
            $chemin = storage_path("app/previsualisations-email/{$cle}.html");
            $this->assertFileExists($chemin, "Fichier manquant pour la clé {$cle}");
            $this->assertNotSame('', trim((string) file_get_contents($chemin)), "Fichier vide pour la clé {$cle}");
        }
    }
}
