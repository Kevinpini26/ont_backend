<?php

namespace Modules\Kernel\Tests\Unit;

use Modules\Kernel\Support\MailProductionGuard;
use Tests\TestCase;

class MailProductionGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    public function test_refuse_mail_mailer_log_en_production(): void
    {
        $this->app['env'] = 'production';
        config(['mail.default' => 'log']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MAIL_MAILER=log en production');

        MailProductionGuard::verifier();
    }

    public function test_un_mailer_reel_est_accepte_en_production(): void
    {
        $this->app['env'] = 'production';
        config(['mail.default' => 'smtp']);

        MailProductionGuard::verifier();

        $this->assertTrue(true);
    }

    public function test_mail_mailer_log_reste_accepte_hors_production(): void
    {
        $this->app['env'] = 'local';
        config(['mail.default' => 'log']);

        MailProductionGuard::verifier();

        $this->assertTrue(true);
    }
}
