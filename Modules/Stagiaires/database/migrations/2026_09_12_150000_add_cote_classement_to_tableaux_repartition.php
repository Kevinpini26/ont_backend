<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot D, point 2 : le tableau approuvé reçoit sa propre cote de classement
 * — même principe que Courrier::cote_classement (retrouvable seul), posée
 * à l'approbation (voir TableauRepartitionCircuitService::rendreAvis()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tableaux_repartition', function (Blueprint $table) {
            $table->string('cote_classement')->nullable()->unique()->after('pdf_chemin');
        });
    }

    public function down(): void
    {
        Schema::table('tableaux_repartition', function (Blueprint $table) {
            $table->dropColumn('cote_classement');
        });
    }
};
