<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche de suivi périodique — points d'étape rédigés en cours de stage
 * (pas seulement l'évaluation finale), par la direction d'accueil ou le
 * maître de stage désigné. Voir StagiairePolicy::gererSuivi().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stagiaire_suivis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stagiaire_id')->constrained()->cascadeOnDelete();
            $table->date('date_suivi');
            $table->text('observations');
            $table->text('difficultes_signalees')->nullable();
            $table->foreignId('redige_par_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stagiaire_suivis');
    }
};
