<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "à numériser" n'est jamais un blocage de l'enregistrement : la Réception
 * enregistre le courrier et remet l'accusé de réception même si la
 * numérisation échoue le jour même (coupure de courant, copieur en panne,
 * téléphone déchargé) — le dossier part avec ce statut, qui alimente une
 * liste de rattrapage tant que le scan n'est pas arrivé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->string('numerisation_statut', 20)->default('non_applicable')->after('piece_jointe_chemin');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn('numerisation_statut');
        });
    }
};
