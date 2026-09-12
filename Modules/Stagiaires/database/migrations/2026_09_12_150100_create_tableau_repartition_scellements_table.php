<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot D, point 3 (scellement du feu vert) : une ligne par tableau approuvé,
 * jamais modifiée ni supprimée après coup — empreinte SHA-256 du PDF
 * approuvé, horodatage, auteur, mention d'intérim. Table distincte plutôt
 * que des colonnes sur `tableaux_repartition` : le caractère non modifiable
 * de la preuve doit être visible dans le schéma lui-même (aucune méthode du
 * modèle TableauRepartitionScellement n'autorise de mise à jour), pas
 * seulement respecté par convention comme le reste de `tableaux_repartition`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tableau_repartition_scellements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tableau_repartition_id')->unique()->constrained('tableaux_repartition')->cascadeOnDelete();
            $table->string('pdf_sha256', 64);
            $table->foreignId('auteur_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('mention_interim')->default(false);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tableau_repartition_scellements');
    }
};
