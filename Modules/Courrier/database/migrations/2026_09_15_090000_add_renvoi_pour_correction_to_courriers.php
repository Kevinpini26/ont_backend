<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot assistants (voir docs/questions-ont.md) : renvoi pour correction d'un
 * projet de réponse, du relecteur désigné vers l'assistant rédacteur — même
 * convention *_at/*_par_id que avis_dg_rendu_at/avis_dg_rendu_par_id.
 * L'observation est obligatoire côté validation (voir
 * RenvoyerPourCorrectionRequest), pas ici : la colonne reste nullable, un
 * courrier jamais renvoyé n'ayant simplement jamais ces champs renseignés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->text('projet_renvoi_observation')->nullable()->after('relecture_commentaire');
            $table->timestamp('projet_renvoye_at')->nullable()->after('projet_renvoi_observation');
            $table->foreignId('projet_renvoye_par_id')->nullable()->after('projet_renvoye_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('projet_renvoye_par_id');
            $table->dropColumn(['projet_renvoi_observation', 'projet_renvoye_at']);
        });
    }
};
