<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 6 : plan de classement réglable (rubriques, durée d'utilité administrative, sort final) et bordereaux
 * d'élimination ou de versement. Aucune destruction : un bordereau validé marque seulement le sort exécuté.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_classement_rubriques', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('libelle');
            $table->unsignedSmallInteger('duree_conservation_annees')->nullable();
            $table->string('sort_final')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestampsTz();
        });

        Schema::create('bordereaux_conservation', function (Blueprint $table): void {
            $table->id();
            $table->string('numero')->unique();
            $table->string('type');
            $table->string('statut');
            $table->text('motif');
            $table->foreignId('propose_par_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('propose_at');
            $table->foreignId('valide_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('valide_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('bordereau_conservation_dossier', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bordereau_conservation_id')->constrained('bordereaux_conservation')->restrictOnDelete();
            $table->foreignId('dossier_id')->constrained('dossiers')->restrictOnDelete();
            $table->string('rubrique');
            $table->date('fin_conservation_le');
            $table->unique(['bordereau_conservation_id', 'dossier_id']);
        });

        Schema::table('dossiers', function (Blueprint $table): void {
            $table->foreignId('rubrique_classement_id')->nullable()->constrained('plan_classement_rubriques')->restrictOnDelete();
            $table->foreignId('sort_final_bordereau_id')->nullable()->constrained('bordereaux_conservation')->restrictOnDelete();
            $table->timestampTz('sort_final_execute_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dossiers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rubrique_classement_id');
            $table->dropConstrainedForeignId('sort_final_bordereau_id');
            $table->dropColumn('sort_final_execute_at');
        });
        Schema::dropIfExists('bordereau_conservation_dossier');
        Schema::dropIfExists('bordereaux_conservation');
        Schema::dropIfExists('plan_classement_rubriques');
    }
};
