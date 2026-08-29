<?php

namespace Modules\Kernel\Tests\Unit;

use Modules\Kernel\Support\BackupDiskProductionGuard;
use Tests\TestCase;

class BackupDiskProductionGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    public function test_refuse_backups_local_en_production(): void
    {
        $this->app['env'] = 'production';
        config(['backup.disk' => 'backups-local']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BACKUP_DISK=backups-local en production');

        BackupDiskProductionGuard::verifier();
    }

    public function test_backups_s3_est_accepte_en_production(): void
    {
        $this->app['env'] = 'production';
        config(['backup.disk' => 'backups-s3']);

        BackupDiskProductionGuard::verifier();

        $this->assertTrue(true);
    }

    public function test_backups_local_reste_accepte_hors_production(): void
    {
        $this->app['env'] = 'local';
        config(['backup.disk' => 'backups-local']);

        BackupDiskProductionGuard::verifier();

        $this->assertTrue(true);
    }
}
