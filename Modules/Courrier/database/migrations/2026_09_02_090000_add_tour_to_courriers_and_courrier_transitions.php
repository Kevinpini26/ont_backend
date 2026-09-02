<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numéro de tour de la boucle interne (Lot 1, correction du circuit) —
 * incrémenté uniquement quand la Réception représente un dossier à la DG
 * après un avis "réservé" (voir CourrierCircuitService::representerDg()).
 * Défaut 1 : un courrier qui ne boucle jamais reste au premier tour, y
 * compris les courriers déjà existants (ADD COLUMN ... DEFAULT 1 les
 * couvre sans UPDATE séparé). Aucune reprise rétroactive des courriers déjà
 * avancés avec un avis réservé : ils gardent leur historique et leur statut
 * actuel tels quels, seul le compteur démarre à 1 comme pour tous les
 * autres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->unsignedInteger('tour')->default(1)->after('statut');
        });

        Schema::table('courrier_transitions', function (Blueprint $table) {
            $table->unsignedInteger('tour')->default(1)->after('statut');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn('tour');
        });

        Schema::table('courrier_transitions', function (Blueprint $table) {
            $table->dropColumn('tour');
        });
    }
};
