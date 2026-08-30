<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Métadonnées de la lettre elle-même, distinctes de sa réception —
 * absentes jusqu'ici, alors qu'un courrier officiel s'y réfère toujours
 * pour répondre en citant la lettre reçue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->date('date_courrier')->nullable()->after('objet');
            $table->string('reference_expediteur')->nullable()->after('date_courrier');
            $table->string('qualite_expediteur')->nullable()->after('reference_expediteur');
            $table->string('mode_reception', 20)->nullable()->after('qualite_expediteur');
            $table->unsignedInteger('nombre_annexes')->default(0)->after('mode_reception');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn(['date_courrier', 'reference_expediteur', 'qualite_expediteur', 'mode_reception', 'nombre_annexes']);
        });
    }
};
