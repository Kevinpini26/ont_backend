<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marque une transition effectuée par un utilisateur agissant au nom d'un
 * poste qui n'est pas le sien, via une délégation active (voir
 * DelegationPoste/DelegationResolver) — même principe que
 * Courrier::avis_dg_rendu_en_interim, généralisé à tout poste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courrier_transitions', function (Blueprint $table) {
            $table->boolean('agi_en_interim')->default(false)->after('changed_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('courrier_transitions', function (Blueprint $table) {
            $table->dropColumn('agi_en_interim');
        });
    }
};
