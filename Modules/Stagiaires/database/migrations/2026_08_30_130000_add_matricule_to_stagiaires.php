<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identifiant stable du stagiaire dans le système, distinct du numéro
 * d'attestation (délivré seulement à la clôture) — attribué dès
 * l'affectation, voir StagiaireCircuitService::affecter().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->string('matricule')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->dropColumn('matricule');
        });
    }
};
