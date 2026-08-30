<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Généralise le principe déjà en place pour la seule DG (DgDisponibilite) :
 * n'importe quel poste du circuit central peut être délégué pendant une
 * absence, sans bloquer le flux. `poste` (pas delegant_id) est la clé de
 * délégation : on délègue un POSTE (la fonction), pas la personne qui
 * l'occupait ce jour-là, ce qui reste valable même si le titulaire change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations_poste', function (Blueprint $table) {
            $table->id();
            $table->string('poste', 30);
            $table->foreignId('delegataire_id')->constrained('users')->cascadeOnDelete();
            $table->date('debut');
            $table->date('fin');
            $table->text('motif');
            $table->foreignId('cree_par_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['poste', 'debut', 'fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations_poste');
    }
};
