<?php

namespace Modules\Courrier\Tests\Unit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Mockery;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use PHPUnit\Framework\TestCase;

class DocumentProduitDirectionAutonomeScopeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_autonomous_documents_are_identified_using_originally_migrated_columns(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('whereNull')
            ->once()
            ->with('documents_produits_direction.traitement_direction_id')
            ->andReturnSelf();
        $query->shouldReceive('whereNull')
            ->once()
            ->with('documents_produits_direction.document_source_id')
            ->andReturnSelf();

        $result = DocumentProduitDirection::appliquerFiltreAutonome($query);

        $this->assertSame($query, $result);
    }

    public function test_query_builder_autonomous_filter_uses_both_nullable_foreign_keys(): void
    {
        $query = Mockery::mock(QueryBuilder::class);
        $query->shouldReceive('whereNull')
            ->once()
            ->with('documents_produits_direction.traitement_direction_id')
            ->andReturnSelf();
        $query->shouldReceive('whereNull')
            ->once()
            ->with('documents_produits_direction.document_source_id')
            ->andReturnSelf();

        $result = DocumentProduitDirection::appliquerFiltreAutonome($query);

        $this->assertSame($query, $result);
    }

    public function test_producer_secretariat_visibility_is_limited_to_its_direction(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('whereNull')->twice()->andReturnSelf();
        $query->shouldReceive('where')
            ->once()
            ->with('direction_id', 24)
            ->andReturnSelf();

        $user = new User([
            'role' => UserRole::SECRETARIAT_DIRECTION,
            'direction_id' => 24,
        ]);

        $result = DocumentProduitDirection::appliquerVisibilitePour($query, $user);

        $this->assertSame($query, $result);
    }

    public function test_non_direction_agent_cannot_view_autonomous_document_metadata(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('whereRaw')
            ->once()
            ->with('1 = 0')
            ->andReturnSelf();

        $user = new User([
            'role' => UserRole::AGENT_CIRCUIT_COURRIER,
            'poste' => Poste::RECEPTION,
        ]);

        $result = DocumentProduitDirection::appliquerVisibilitePour($query, $user);

        $this->assertSame($query, $result);
    }

    public function test_director_visibility_requires_submission_and_allowed_status(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('whereNull')->twice()->andReturnSelf();
        $query->shouldReceive('where')
            ->once()
            ->with('directeur_id', 31)
            ->andReturnSelf();
        $query->shouldReceive('where')
            ->once()
            ->with('direction_id', 24)
            ->andReturnSelf();
        $query->shouldReceive('whereIn')
            ->once()
            ->with('statut', ['brouillon', 'soumis_directeur', 'a_corriger', 'valide', 'scan_enregistre'])
            ->andReturnSelf();
        $query->shouldReceive('whereNotNull')
            ->once()
            ->with('soumis_at')
            ->andReturnSelf();
        $query->shouldReceive('whereNotNull')
            ->once()
            ->with('soumis_par_id')
            ->andReturnSelf();

        $user = new User([
            'role' => UserRole::DIRECTEUR_DIRECTION,
            'direction_id' => 24,
        ]);
        $user->id = 31;

        $result = DocumentProduitDirection::appliquerVisibilitePour($query, $user);

        $this->assertSame($query, $result);
    }

    public function test_courrier_visibility_excludes_only_untransmitted_autonomous_documents(): void
    {
        $query = Mockery::mock(Builder::class);
        $subquery = Mockery::mock(QueryBuilder::class);

        $subquery->shouldReceive('selectRaw')->once()->with('1')->andReturnSelf();
        $subquery->shouldReceive('from')->once()->with('documents_produits_direction')->andReturnSelf();
        $subquery->shouldReceive('whereColumn')
            ->once()
            ->with('documents_produits_direction.courrier_id', 'courriers.id')
            ->andReturnSelf();
        $subquery->shouldReceive('whereNull')
            ->once()
            ->with('documents_produits_direction.traitement_direction_id')
            ->andReturnSelf();
        $subquery->shouldReceive('whereNull')
            ->once()
            ->with('documents_produits_direction.document_source_id')
            ->andReturnSelf();
        $subquery->shouldReceive('whereNotIn')
            ->once()
            ->with('documents_produits_direction.statut', ['transmis_reception', 'entre_circuit'])
            ->andReturnSelf();
        $query->shouldReceive('whereNotExists')
            ->once()
            ->with(Mockery::on(function (callable $callback) use ($subquery): bool {
                $callback($subquery);

                return true;
            }))
            ->andReturnSelf();

        $result = (new Courrier)->scopeExclureAutonomesAvantTransmission($query);

        $this->assertSame($query, $result);
    }
}
