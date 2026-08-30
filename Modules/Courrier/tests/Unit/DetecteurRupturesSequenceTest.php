<?php

namespace Modules\Courrier\Tests\Unit;

use Illuminate\Support\Collection;
use Modules\Courrier\Support\DetecteurRupturesSequence;
use Tests\TestCase;

class DetecteurRupturesSequenceTest extends TestCase
{
    private function element(int $id, ?string $numero): object
    {
        return (object) ['id' => $id, 'numero' => $numero];
    }

    public function test_aucune_rupture_sur_une_sequence_continue(): void
    {
        $elements = new Collection([
            $this->element(1, 'AR-2026-000001'),
            $this->element(2, 'AR-2026-000002'),
            $this->element(3, 'AR-2026-000003'),
        ]);

        $this->assertSame([], (new DetecteurRupturesSequence)->detecter($elements, 'numero'));
    }

    public function test_un_numero_manquant_dans_la_sequence_est_signale(): void
    {
        $elements = new Collection([
            $this->element(1, 'AR-2026-000001'),
            $this->element(2, 'AR-2026-000002'),
            // 000003 manque
            $this->element(3, 'AR-2026-000004'),
        ]);

        $this->assertSame([3], (new DetecteurRupturesSequence)->detecter($elements, 'numero'));
    }

    public function test_plusieurs_ruptures_sont_toutes_signalees(): void
    {
        $elements = new Collection([
            $this->element(1, 'AR-2026-000001'),
            $this->element(2, 'AR-2026-000003'),
            $this->element(3, 'AR-2026-000007'),
        ]);

        $this->assertSame([2, 3], (new DetecteurRupturesSequence)->detecter($elements, 'numero'));
    }

    public function test_un_numero_nul_est_ignore_sans_faire_planter_la_detection(): void
    {
        $elements = new Collection([
            $this->element(1, 'AR-2026-000001'),
            $this->element(2, null),
            $this->element(3, 'AR-2026-000002'),
        ]);

        $this->assertSame([], (new DetecteurRupturesSequence)->detecter($elements, 'numero'));
    }
}
