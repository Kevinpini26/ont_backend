<?php

namespace Modules\Kernel\Tests\Unit;

use Modules\Kernel\Enums\StatutNotificationEmail;
use PHPUnit\Framework\TestCase;

class StatutNotificationEmailTest extends TestCase
{
    public function test_les_statuts_sont_stables_et_libelles(): void
    {
        $this->assertSame(
            ['en_file', 'envoye', 'echec'],
            array_map(fn (StatutNotificationEmail $statut) => $statut->value, StatutNotificationEmail::cases()),
        );

        foreach (StatutNotificationEmail::cases() as $statut) {
            $this->assertNotSame('', $statut->label());
        }
    }
}
