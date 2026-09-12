<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot A : la DFP propose une issue (retenu/non retenu) dès la composition
 * du tableau, avec un motif court pour un refus — voir
 * config('stagiaires.motifs_non_retenu'). L'effet réel (sortie de boucle,
 * notification) reste au Lot B ; cette migration ne porte que la
 * proposition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tableau_repartition_lignes', function (Blueprint $table) {
            $table->string('issue_proposee', 20)->default('retenu')->after('encadrant_pressenti');
            $table->string('motif_non_retenu', 40)->nullable()->after('issue_proposee');
            $table->text('motif_non_retenu_libre')->nullable()->after('motif_non_retenu');
        });
    }

    public function down(): void
    {
        Schema::table('tableau_repartition_lignes', function (Blueprint $table) {
            $table->dropColumn(['issue_proposee', 'motif_non_retenu', 'motif_non_retenu_libre']);
        });
    }
};
