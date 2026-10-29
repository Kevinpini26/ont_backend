<?php

namespace Modules\Kernel\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Les heures « avec fuseau » doivent revenir à l'identique quel que soit le fuseau du serveur PostgreSQL. */
class FuseauHorairePostgresTest extends TestCase
{
    public function test_la_session_postgresql_est_en_utc_et_les_heures_font_l_aller_retour_sans_decalage(): void
    {
        $this->assertSame('UTC', DB::selectOne('show timezone')->TimeZone);

        DB::statement('create temporary table tz_test (a timestamptz)');
        $ecrit = Carbon::parse('2026-10-29 12:59:34', 'UTC');
        DB::table('tz_test')->insert(['a' => $ecrit]);

        $this->assertTrue($ecrit->equalTo(Carbon::parse(DB::table('tz_test')->value('a'))));
    }
}
