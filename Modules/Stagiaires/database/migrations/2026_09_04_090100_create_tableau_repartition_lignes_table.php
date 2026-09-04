<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une ligne = une demande de stage (Stagiaire) proposée dans un tableau,
 * avec sa direction d'accueil proposée, ses dates proposées et son
 * encadrant pressenti — des propositions propres au tableau, jamais
 * copiées sur `stagiaires` (voir Stagiaire::direction_id/date_debut_stage,
 * posées seulement à l'affectation réelle, Lot 5). `unique(tableau, stagiaire)`
 * empêche un doublon au sein d'un même tableau ; "une seule ligne dans un
 * tableau APPROUVÉ" (contrainte inter-tables, pas exprimable en SQL
 * déclaratif ici) est vérifiée en service, sous verrou, à l'approbation —
 * voir TableauRepartitionCircuitService::rendreAvis().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tableau_repartition_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tableau_repartition_id')->constrained('tableaux_repartition')->cascadeOnDelete();
            $table->foreignId('stagiaire_id')->constrained('stagiaires')->restrictOnDelete();
            $table->foreignId('direction_accueil_proposee_id')->constrained('directions')->restrictOnDelete();
            $table->date('date_debut_proposee');
            $table->date('date_fin_proposee');
            $table->string('encadrant_pressenti');
            $table->timestamps();

            $table->unique(['tableau_repartition_id', 'stagiaire_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tableau_repartition_lignes');
    }
};
