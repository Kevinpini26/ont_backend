<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empreinte d'intégrité (voir Modules\Kernel\Support\EmpreinteFichier) —
 * couvre uniformément les documents générés par le système (attestation,
 * certificat, note d'affectation) et ceux déposés par un tiers (pièces du
 * dossier, rapport de fin de stage, photo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stagiaire_documents', function (Blueprint $table) {
            $table->string('sha256', 64)->nullable()->after('chemin');
        });
    }

    public function down(): void
    {
        Schema::table('stagiaire_documents', function (Blueprint $table) {
            $table->dropColumn('sha256');
        });
    }
};
