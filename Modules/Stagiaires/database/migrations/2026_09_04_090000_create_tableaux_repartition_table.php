<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 4 (tableau de répartition) : la DFP y regroupe les demandes de stage
 * qu'elle a enregistrées, avant soumission à la Direction Générale.
 * `courrier_id` est nul tant que le tableau est en brouillon (voir
 * TableauRepartitionStatut::BROUILLON) — le courrier synthétique qui porte
 * le trajet Réception -> DG n'est créé qu'à la soumission (voir
 * TableauRepartitionCircuitService::soumettre()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tableaux_repartition', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->nullable()->unique()->constrained('courriers')->nullOnDelete();
            $table->foreignId('direction_id')->constrained('directions')->restrictOnDelete();
            $table->foreignId('redacteur_id')->constrained('users')->restrictOnDelete();
            $table->date('periode_debut');
            $table->date('periode_fin');
            $table->string('statut', 20);
            $table->foreignId('approuve_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approuve_at')->nullable();
            $table->string('pdf_chemin')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tableaux_repartition');
    }
};
