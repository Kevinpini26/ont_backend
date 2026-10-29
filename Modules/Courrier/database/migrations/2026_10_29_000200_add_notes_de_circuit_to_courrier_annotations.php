<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courrier_annotations', function (Blueprint $table): void {
            $table->string('type', 20)->default('observation');
            $table->string('destinataire_poste', 40)->nullable();
            $table->foreignId('destinataire_direction_id')->nullable()->constrained('directions')->restrictOnDelete();
            $table->string('visibilite', 20)->default('circuit');
            // Renseigné sur l'ancienne note : identifiant de la note qui la corrige.
            $table->foreignId('annule_par_id')->nullable()->constrained('courrier_annotations')->restrictOnDelete();

            $table->index(['courrier_id', 'visibilite']);
            $table->index(['type', 'destinataire_poste']);
        });

        Schema::create('courrier_annotation_lectures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('annotation_id')->constrained('courrier_annotations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('lu_at');

            $table->unique(['annotation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courrier_annotation_lectures');

        Schema::table('courrier_annotations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('annule_par_id');
            $table->dropConstrainedForeignId('destinataire_direction_id');
            $table->dropIndex(['courrier_id', 'visibilite']);
            $table->dropIndex(['type', 'destinataire_poste']);
            $table->dropColumn(['type', 'destinataire_poste', 'visibilite']);
        });
    }
};
