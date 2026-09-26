<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatchs_courrier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->constrained('courriers')->cascadeOnDelete();
            $table->foreignId('dossier_id')->constrained('dossiers')->restrictOnDelete();
            $table->string('type_destination');
            $table->foreignId('direction_id')->nullable()->constrained('directions')->restrictOnDelete();
            $table->string('destinataire_externe_nom')->nullable();
            $table->string('destinataire_externe_email')->nullable();
            $table->text('instruction');
            $table->foreignId('decisionnaire_id')->constrained('users')->restrictOnDelete();
            $table->string('decisionnaire_poste')->nullable();
            $table->string('autorite_poste');
            $table->timestampTz('decide_at');
            $table->string('statut');
            $table->foreignId('execute_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('execute_at')->nullable();
            $table->string('reference_transmission')->nullable();
            $table->foreignId('preuve_piece_jointe_id')->nullable()->constrained('courrier_pieces_jointes')->nullOnDelete();
            $table->foreignId('accuse_reception_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('accuse_reception_at')->nullable();
            $table->timestampsTz();

            $table->index(['courrier_id', 'statut']);
            $table->index(['direction_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatchs_courrier');
    }
};
