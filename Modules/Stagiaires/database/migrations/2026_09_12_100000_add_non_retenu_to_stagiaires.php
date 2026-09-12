<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot B (issue individuelle) : un dossier non retenu sort de la boucle
 * comme un dossier retenu — voir StagiaireStatut::NON_RETENU.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->string('motif_non_retenu', 40)->nullable()->after('doublon_stagiaire_id');
            $table->text('motif_non_retenu_libre')->nullable()->after('motif_non_retenu');
            $table->timestamp('non_retenu_at')->nullable()->after('motif_non_retenu_libre');
            $table->foreignId('non_retenu_par_id')->nullable()->after('non_retenu_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->dropConstrainedForeignId('non_retenu_par_id');
            $table->dropColumn(['motif_non_retenu', 'motif_non_retenu_libre', 'non_retenu_at']);
        });
    }
};
