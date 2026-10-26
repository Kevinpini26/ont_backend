<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confiages_dga', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('courrier_id')->constrained('courriers')->restrictOnDelete();
            $table->foreignId('confie_par_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('dga_id')->constrained('users')->restrictOnDelete();
            $table->text('consigne');
            $table->string('statut', 20)->default('en_cours');
            $table->timestampTz('confie_at');
            $table->foreignId('retourne_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('retourne_at')->nullable();
            $table->text('recommandation')->nullable();
            $table->timestampTz('repris_at')->nullable();
            $table->timestampsTz();

            $table->index(['courrier_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confiages_dga');
    }
};
