<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents_produits_direction', function (Blueprint $table) {
            $table->id();
            $table->foreignId('traitement_direction_id')->unique()->constrained('traitements_direction')->cascadeOnDelete();
            $table->foreignId('courrier_id')->unique()->constrained('courriers')->cascadeOnDelete();
            $table->foreignId('document_source_id')->constrained('courriers')->restrictOnDelete();
            $table->foreignId('direction_id')->constrained('directions')->restrictOnDelete();
            $table->string('statut');
            $table->foreignId('cree_par_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('cree_at');
            $table->foreignId('soumis_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('soumis_at')->nullable();
            $table->foreignId('correction_demandee_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('motif_correction')->nullable();
            $table->timestampTz('correction_demandee_at')->nullable();
            $table->foreignId('valide_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('valide_at')->nullable();
            $table->foreignId('transmis_reception_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('transmis_reception_at')->nullable();
            $table->foreignId('recu_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('recu_at')->nullable();
            $table->timestampsTz();
            $table->index(['direction_id', 'statut']);
            $table->index(['statut', 'transmis_reception_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents_produits_direction');
    }
};
