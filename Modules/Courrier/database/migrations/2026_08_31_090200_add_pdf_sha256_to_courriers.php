<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empreinte d'intégrité du PDF définitif généré à la signature (voir
 * Modules\Kernel\Support\EmpreinteFichier et
 * CourrierCircuitService::signer()) — preuve technique complémentaire,
 * pas une signature électronique qualifiée (voir docs/conformite-donnees.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->string('pdf_sha256', 64)->nullable()->after('pdf_chemin');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn('pdf_sha256');
        });
    }
};
