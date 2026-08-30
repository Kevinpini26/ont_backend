<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engagement de confidentialité : document distinct de la convention de
 * stage (qui contient déjà une clause de confidentialité générale, voir
 * convention.blade.php) — signé individuellement par le seul stagiaire,
 * même mécanisme de lien public à usage unique que la convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->string('engagement_confidentialite_chemin')->nullable();
            $table->timestamp('engagement_confidentialite_genere_at')->nullable();
            $table->timestamp('engagement_confidentialite_signe_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->dropColumn([
                'engagement_confidentialite_chemin',
                'engagement_confidentialite_genere_at',
                'engagement_confidentialite_signe_at',
            ]);
        });
    }
};
