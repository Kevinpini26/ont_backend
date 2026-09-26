<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courrier_transitions', function (Blueprint $table) {
            $table->string('ancien_statut')->nullable()->after('statut');
            $table->string('nouveau_statut')->nullable()->after('ancien_statut');
            $table->string('expediteur_poste')->nullable()->after('changed_by_id');
            $table->text('instruction')->nullable()->after('expediteur_poste');
        });

        DB::statement(<<<'SQL'
            with historique as (
                select id,
                       lag(statut) over (partition by courrier_id order by id) as ancien_statut,
                       statut as nouveau_statut
                from courrier_transitions
            )
            update courrier_transitions as transition
            set ancien_statut = historique.ancien_statut,
                nouveau_statut = historique.nouveau_statut
            from historique
            where transition.id = historique.id
            SQL);
    }

    public function down(): void
    {
        Schema::table('courrier_transitions', function (Blueprint $table) {
            $table->dropColumn(['ancien_statut', 'nouveau_statut', 'expediteur_poste', 'instruction']);
        });
    }
};
