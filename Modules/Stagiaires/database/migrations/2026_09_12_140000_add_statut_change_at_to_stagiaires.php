<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot C, point 5 (délais et souffrance) : contrairement à Courrier (dont
 * l'historique complet vit dans courrier_transitions), Stagiaire n'a
 * aucune table de transitions — impossible de calculer une ancienneté
 * "depuis l'entrée dans l'étape courante" sans un repère. Un seul
 * timestamp générique plutôt qu'une table d'historique complète : aucun
 * autre besoin (audit, retour en arrière) ne justifie ici la complexité
 * d'un historique multi-lignes, voir StagiaireEnSouffrance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->timestamp('statut_change_at')->nullable()->after('statut');
        });
    }

    public function down(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->dropColumn('statut_change_at');
        });
    }
};
