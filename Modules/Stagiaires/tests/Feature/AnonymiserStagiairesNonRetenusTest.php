<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\AuditLog;
use Modules\Stagiaires\Console\AnonymiserStagiairesNonRetenusCommand;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;
use Tests\TestCase;

class AnonymiserStagiairesNonRetenusTest extends TestCase
{
    use RefreshDatabase;

    private function nonRetenu(int $moisEcoules): Stagiaire
    {
        $courrier = Courrier::factory()->demandeStage()->create([
            'candidat_nom' => 'Mbuyi Kalala',
            'candidat_email' => 'mbuyi@example.test',
            'avis_dg' => null,
        ]);

        return Stagiaire::factory()->create([
            'courrier_id' => $courrier->id,
            'nom' => 'Mbuyi Kalala',
            'contact' => 'mbuyi@example.test',
            'lieu_naissance' => 'Kananga',
            'statut' => StagiaireStatut::NON_RETENU,
            'motif_non_retenu' => 'capacite',
            'non_retenu_at' => now()->subMonths($moisEcoules),
        ]);
    }

    public function test_un_refus_du_tableau_de_repartition_est_anonymise_apres_le_delai(): void
    {
        $stagiaire = $this->nonRetenu(13);

        $this->artisan(AnonymiserStagiairesNonRetenusCommand::class)->assertSuccessful();

        $stagiaire->refresh();
        $this->assertSame('Candidat anonymisé', $stagiaire->nom);
        $this->assertNull($stagiaire->lieu_naissance);
        $this->assertStringNotContainsString('mbuyi', mb_strtolower($stagiaire->contact));
        $courrier = Courrier::withoutGlobalScopes()->findOrFail($stagiaire->courrier_id);
        $this->assertNull($courrier->candidat_nom);
        $this->assertNull($courrier->candidat_email);
        $this->assertNotNull($courrier->anonymise_at);
        // Statistiques conservées.
        $this->assertSame(StagiaireStatut::NON_RETENU, $stagiaire->statut);
        $this->assertSame('capacite', $stagiaire->motif_non_retenu);
    }

    public function test_un_refus_recent_nest_pas_anonymise(): void
    {
        $stagiaire = $this->nonRetenu(3);

        $this->artisan(AnonymiserStagiairesNonRetenusCommand::class)->assertSuccessful();

        $this->assertSame('Mbuyi Kalala', $stagiaire->fresh()->nom);
    }

    public function test_le_journal_daudit_nest_jamais_supprime(): void
    {
        $this->nonRetenu(13);
        $avant = AuditLog::query()->count();

        $this->artisan(AnonymiserStagiairesNonRetenusCommand::class)->assertSuccessful();

        $this->assertGreaterThan($avant, AuditLog::query()->count());
    }
}
