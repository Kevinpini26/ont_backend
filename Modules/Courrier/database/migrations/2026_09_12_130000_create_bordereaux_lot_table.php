<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot C, points 1-2 : transmission par lot — un bordereau unique porte
 * plusieurs dossiers à la fois (la Réception n'a plus à transmettre un
 * courrier à la fois), avec une décharge en une seule action pour tout le
 * lot. La trace individuelle par dossier reste portée par
 * `courrier_transitions` (voir la migration suivante, `bordereau_lot_id`) :
 * ce tableau ne fait qu'en-tête le lot, jamais le détail par dossier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bordereaux_lot', function (Blueprint $table) {
            $table->id();
            $table->string('numero')->unique();
            $table->foreignId('emetteur_id')->constrained('users')->cascadeOnDelete();
            $table->string('poste_destinataire');
            $table->timestamp('accuse_reception_at')->nullable();
            $table->foreignId('accuse_reception_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bordereaux_lot');
    }
};
