<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missions_documentaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->constrained('courriers')->cascadeOnDelete();
            $table->foreignId('dossier_id')->constrained('dossiers')->restrictOnDelete();
            $table->foreignId('demandeur_id')->constrained('users')->restrictOnDelete();
            // Nullable pour un acteur sans poste propre agissant via une
            // délégation ; autorite_poste conserve alors le poste représenté.
            $table->string('demandeur_poste')->nullable();
            $table->string('autorite_poste');
            $table->foreignId('assistant_id')->constrained('users')->restrictOnDelete();
            $table->text('instruction');
            $table->string('statut');
            $table->timestampTz('envoyee_at');
            $table->timestampTz('prise_en_charge_at')->nullable();
            $table->text('compte_rendu')->nullable();
            $table->json('projet_reponse_contenu')->nullable();
            $table->timestampTz('retournee_at')->nullable();
            $table->foreignId('annulee_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motif_annulation')->nullable();
            $table->timestampTz('annulee_at')->nullable();
            $table->timestampsTz();

            $table->index(['assistant_id', 'statut']);
            $table->index(['courrier_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            create unique index missions_documentaires_active_autorite_unique
            on missions_documentaires (courrier_id, autorite_poste)
            where statut in ('assignee', 'en_cours')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('missions_documentaires');
    }
};
