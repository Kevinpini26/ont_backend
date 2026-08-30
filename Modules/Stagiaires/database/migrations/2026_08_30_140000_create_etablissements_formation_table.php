<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel des établissements d'origine des stagiaires — remplace
 * progressivement la saisie libre de `stagiaires.etablissement_origine`
 * (conservée pour la compatibilité et les établissements pas encore
 * référencés, voir Stagiaire::etablissement()/etablissement_origine).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etablissements_formation', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('ville')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['nom', 'ville']);
        });

        Schema::table('stagiaires', function (Blueprint $table) {
            $table->foreignId('etablissement_id')->nullable()->after('etablissement_origine')
                ->constrained('etablissements_formation')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->dropConstrainedForeignId('etablissement_id');
        });

        Schema::dropIfExists('etablissements_formation');
    }
};
