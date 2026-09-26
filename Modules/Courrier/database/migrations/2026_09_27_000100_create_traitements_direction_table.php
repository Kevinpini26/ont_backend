<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traitements_direction', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->constrained('courriers')->cascadeOnDelete();
            $table->foreignId('dossier_id')->constrained('dossiers')->restrictOnDelete();
            $table->foreignId('dispatch_courrier_id')->unique()->constrained('dispatchs_courrier')->cascadeOnDelete();
            $table->foreignId('direction_id')->constrained('directions')->restrictOnDelete();
            $table->string('statut');
            $table->foreignId('recu_par_secretariat_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recu_secretariat_at');
            $table->foreignId('transmis_par_secretariat_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('directeur_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('note_transmission')->nullable();
            $table->timestampTz('transmis_directeur_at')->nullable();
            $table->foreignId('pris_en_charge_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('pris_en_charge_at')->nullable();
            $table->string('decision_directeur')->nullable();
            $table->text('commentaire_directeur')->nullable();
            $table->foreignId('decision_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decision_at')->nullable();
            $table->timestampsTz();

            $table->index(['direction_id', 'statut']);
            $table->index(['directeur_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traitements_direction');
    }
};
