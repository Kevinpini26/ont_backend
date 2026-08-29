<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * decimal(4,2) plafonne à 99,99 : un stagiaire noté au maximum par la
 * direction ET par la DFP (100,0 chacune, voir GrilleEvaluation::total() /
 * GrilleEvaluationProfessionnelle::total()) obtient une moyenne de 100,0
 * (voir MoyenneCalculateurNoteFinale), que la colonne ne pouvait pas
 * stocker — trouvé en peuplant des données de démonstration réalistes
 * (StagiaireDemoSeeder), pas seulement théorique. decimal(5,2) laisse une
 * marge largement suffisante (jusqu'à 999,99).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->decimal('note_finale', 5, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->decimal('note_finale', 4, 2)->nullable()->change();
        });
    }
};
