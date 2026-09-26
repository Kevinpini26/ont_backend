<?php

namespace Modules\Courrier\Tests\Unit;

use Modules\Courrier\Enums\DocumentRelationType;
use PHPUnit\Framework\TestCase;

class DocumentRelationTypeTest extends TestCase
{
    public function test_les_types_de_relation_documentaire_sont_stables_et_libelles(): void
    {
        $this->assertSame(
            ['reponse_a', 'suite_de', 'produit_a_partir_de', 'document_retour', 'remplace', 'autre'],
            array_map(fn (DocumentRelationType $type) => $type->value, DocumentRelationType::cases()),
        );

        foreach (DocumentRelationType::cases() as $type) {
            $this->assertNotSame('', $type->label());
        }
    }
}
