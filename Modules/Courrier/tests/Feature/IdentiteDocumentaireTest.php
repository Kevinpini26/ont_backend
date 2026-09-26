<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\ReferenceDocumentaireService;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class IdentiteDocumentaireTest extends CourrierTestCase
{
    use RefreshDatabase;

    private function service(): ReferenceDocumentaireService
    {
        return app(ReferenceDocumentaireService::class);
    }

    private function direction(string $code): Direction
    {
        return Direction::query()->where('code', $code)->firstOrFail();
    }

    public function test_les_references_sont_sequencees_par_direction_et_par_annee(): void
    {
        $dmc = $this->direction('DMC');
        $drh = $this->direction('DRH');
        $directeurDmc = User::factory()->directeurDirection($dmc)->create();
        $directeurDrh = User::factory()->directeurDirection($drh)->create();

        $dmc1 = $this->service()->attribuer(Courrier::factory()->create(['direction_origine_id' => $dmc->id]), $directeurDmc, 2026);
        $dmc2 = $this->service()->attribuer(Courrier::factory()->create(['direction_origine_id' => $dmc->id]), $directeurDmc, 2026);
        $drh1 = $this->service()->attribuer(Courrier::factory()->create(['direction_origine_id' => $drh->id]), $directeurDrh, 2026);
        $dmcNouvelleAnnee = $this->service()->attribuer(Courrier::factory()->create(['direction_origine_id' => $dmc->id]), $directeurDmc, 2027);

        $this->assertSame('001/ONT/DMC/2026', $dmc1->reference_documentaire);
        $this->assertSame('002/ONT/DMC/2026', $dmc2->reference_documentaire);
        $this->assertSame('001/ONT/DRH/2026', $drh1->reference_documentaire);
        $this->assertSame('001/ONT/DMC/2027', $dmcNouvelleAnnee->reference_documentaire);
        $this->assertSame(4, collect([$dmc1, $dmc2, $drh1, $dmcNouvelleAnnee])->pluck('reference_documentaire')->unique()->count());
    }

    public function test_seul_le_directeur_de_la_direction_origine_peut_attribuer_la_reference(): void
    {
        $dmc = $this->direction('DMC');
        $drh = $this->direction('DRH');
        $acteur = User::factory()->directeurDirection($drh)->create();
        $document = Courrier::factory()->create(['direction_origine_id' => $dmc->id]);

        $this->expectException(AuthorizationException::class);
        $this->service()->attribuer($document, $acteur, 2026);
    }

    public function test_un_non_directeur_ne_peut_pas_attribuer_la_reference(): void
    {
        $direction = $this->direction('DMC');
        $acteur = User::factory()->secretariatDirection($direction)->create();
        $document = Courrier::factory()->create(['direction_origine_id' => $direction->id]);

        $this->expectException(AuthorizationException::class);
        $this->service()->attribuer($document, $acteur, 2026);
    }

    public function test_la_dg_non_operationnelle_ne_peut_pas_utiliser_la_reference_directionnelle(): void
    {
        $dg = $this->direction('DG');
        $directeur = User::factory()->directeurDirection($dg)->create();
        $document = Courrier::factory()->create(['direction_origine_id' => $dg->id]);

        $this->expectException(ValidationException::class);
        $this->service()->attribuer($document, $directeur, 2026);
    }

    public function test_les_identites_attribuees_sont_immuables(): void
    {
        $document = Courrier::factory()->create([
            'numero_enregistrement' => '2026-0042',
            'reference_documentaire' => '001/ONT/DMC/2026',
            'numero_depart' => '2026-D0007',
        ]);

        foreach (['numero_enregistrement', 'reference_documentaire', 'numero_depart'] as $champ) {
            try {
                $document->refresh();
                $document->{$champ} = 'VALEUR-ECRASEE';
                $document->save();
                $this->fail("{$champ} aurait dû être immuable.");
            } catch (LogicException) {
                $this->assertDatabaseMissing('courriers', ['id' => $document->id, $champ => 'VALEUR-ECRASEE']);
            }
        }
    }

    public function test_une_reference_est_propre_au_document_et_nest_pas_heritee_par_un_document_lie(): void
    {
        $direction = $this->direction('DMC');
        $directeur = User::factory()->directeurDirection($direction)->create();
        $parent = Courrier::factory()->create(['direction_origine_id' => $direction->id]);
        $parent = $this->service()->attribuer($parent, $directeur, 2026);
        $enfant = Courrier::factory()->create([
            'dossier_id' => $parent->dossier_id,
            'direction_origine_id' => $direction->id,
        ]);

        $this->assertSame('001/ONT/DMC/2026', $parent->reference_documentaire);
        $this->assertNull($enfant->reference_documentaire);
        $this->assertNull($parent->dossier->getAttribute('reference_documentaire'));
    }

    public function test_les_donnees_historiques_restent_inchangees_et_la_nouvelle_reference_reste_nulle(): void
    {
        $historique = Courrier::factory()->create([
            'numero_enregistrement' => 'HIST-ARR-12',
            'numero_depart' => 'HIST-DEP-09',
        ])->refresh();

        $this->assertSame('HIST-ARR-12', $historique->numero_enregistrement);
        $this->assertSame('HIST-DEP-09', $historique->numero_depart);
        $this->assertNull($historique->reference_documentaire);
    }

    public function test_lapi_expose_separement_les_trois_identites(): void
    {
        $admin = User::factory()->administrateur()->create();
        $document = Courrier::factory()->create([
            'numero_enregistrement' => '2026-0001',
            'reference_documentaire' => '001/ONT/DMC/2026',
            'numero_depart' => '2026-D0001',
        ]);

        $this->actingAs($admin)->getJson("/api/v1/courriers/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.numero_enregistrement', '2026-0001')
            ->assertJsonPath('data.reference_documentaire', '001/ONT/DMC/2026')
            ->assertJsonPath('data.numero_depart', '2026-D0001');
    }

    public function test_lattribution_de_reference_est_auditee_avec_acteur_direction_et_horodatage(): void
    {
        $direction = $this->direction('DMC');
        $directeur = User::factory()->directeurDirection($direction)->create();
        $document = Courrier::factory()->create(['direction_origine_id' => $direction->id]);

        $this->service()->attribuer($document, $directeur, 2026);

        $trace = AuditLog::query()->where('action', 'courrier.reference_documentaire_attribuee')->sole();
        $this->assertSame($directeur->id, $trace->user_id);
        $this->assertSame($direction->id, $trace->meta['direction_id']);
        $this->assertSame('001/ONT/DMC/2026', $trace->meta['valeur']);
        $this->assertArrayHasKey('attribuee_at', $trace->meta);
    }
}
