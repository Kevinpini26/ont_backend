<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Où se trouve le papier original — rien ne l'indiquait jusqu'ici. Générée
 * automatiquement à l'enregistrement (voir CourrierCircuitService::enregistrer,
 * config('courrier.format_cote_classement')), emplacement_physique reste
 * une saisie manuelle (armoire précise, salle d'archives...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->string('cote_classement')->nullable()->after('numero_enregistrement');
            $table->string('emplacement_physique')->nullable()->after('cote_classement');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn(['cote_classement', 'emplacement_physique']);
        });
    }
};
