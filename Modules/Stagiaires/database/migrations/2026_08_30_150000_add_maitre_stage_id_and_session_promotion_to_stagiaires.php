<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `maitre_stage` (texte libre, conservé) reste utilisable quand le maître de
 * stage n'est pas lui-même un utilisateur du système ; `maitre_stage_id`
 * permet de le lier à un compte réel quand il en a un — voir
 * docs/questions-ont.md sur l'absence de rôle "maître de stage" dédié.
 * `session_promotion` (ex: "2026-2027") reste un texte libre : aucun
 * référentiel de sessions n'existe par ailleurs dans le système.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->foreignId('maitre_stage_id')->nullable()->after('maitre_stage')
                ->constrained('users')->nullOnDelete();
            $table->string('session_promotion')->nullable()->after('niveau_formation');
        });
    }

    public function down(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maitre_stage_id');
            $table->dropColumn('session_promotion');
        });
    }
};
