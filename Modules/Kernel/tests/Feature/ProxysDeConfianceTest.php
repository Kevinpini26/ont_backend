<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Http\Request;
use Modules\Kernel\Http\Middleware\ConfigurerProxysDeConfiance;
use Tests\TestCase;

class ProxysDeConfianceTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    private function passer(?string $proxys): Request
    {
        config(['kernel.trusted_proxies' => $proxys]);
        $request = Request::create('http://ont.test/api/v1/ping', 'GET', server: [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        (new ConfigurerProxysDeConfiance)->handle($request, fn ($r) => response('ok'));

        return $request;
    }

    public function test_sans_configuration_les_en_tetes_forwarded_ne_sont_pas_crus(): void
    {
        $request = $this->passer(null);

        $this->assertSame('10.0.0.5', $request->ip());
        $this->assertFalse($request->secure());
    }

    public function test_un_proxy_declare_fait_apparaitre_la_vraie_ip_et_le_https(): void
    {
        $request = $this->passer('10.0.0.0/8');

        $this->assertSame('203.0.113.9', $request->ip());
        $this->assertTrue($request->secure());
    }

    public function test_un_proxy_non_declare_ne_peut_pas_falsifier_lip(): void
    {
        $request = $this->passer('192.168.1.1');

        $this->assertSame('10.0.0.5', $request->ip());
    }
}
