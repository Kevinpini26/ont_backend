<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Les tests n'écrivent jamais dans le stockage réel de l'application (storage/app) : un fichier
 * à nom fixe, comme une note d'affectation, y écraserait celui d'une vraie base.
 */
class StockageIsoleDesTestsTest extends TestCase
{
    public function test_le_stockage_et_les_disques_sont_hors_du_dossier_storage_reel(): void
    {
        $reel = realpath(base_path('storage'));

        $this->assertStringStartsNotWith($reel, storage_path());

        foreach (['local', 'public', 'backups-local'] as $disque) {
            $this->assertStringStartsNotWith($reel, config("filesystems.disks.{$disque}.root"), $disque);
        }

        $this->assertStringStartsNotWith($reel, Storage::disk('local')->path(''));
    }
}
