<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sortie et retour de l'original physique d'un courrier — voir
 * docs/numerisation-courrier.md (Lot 5). Une table d'historique, pas un
 * simple champ sur `courriers` : un original peut être emprunté plusieurs
 * fois au fil de sa vie, et chaque emprunt doit rester traçable même une
 * fois restitué.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emprunts_originaux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->constrained('courriers')->cascadeOnDelete();
            $table->foreignId('emprunte_par_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('emprunte_le');
            $table->text('motif');
            $table->timestamp('restitue_le')->nullable();
            $table->foreignId('restitue_par_id')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['courrier_id', 'restitue_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emprunts_originaux');
    }
};
