<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot C, point 3 (réorientation du tri) : quand un signataire (DG/DGA)
 * reçoit un courrier mal orienté vers l'avis DG, il le renvoie au tri sans
 * que ce soit jamais considéré comme une faute — une table dédiée plutôt
 * qu'un simple champ, pour alimenter une statistique de justesse (visible
 * du seul Secrétariat 01) sans polluer la sémantique de `courrier_transitions`
 * (qui représente les étapes normales du circuit, pas ses corrections).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reorientations_tri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->constrained('courriers')->cascadeOnDelete();
            $table->foreignId('trie_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reoriente_par_id')->constrained('users')->cascadeOnDelete();
            $table->text('motif')->nullable();
            $table->timestamp('created_at');

            $table->index('trie_par_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reorientations_tri');
    }
};
