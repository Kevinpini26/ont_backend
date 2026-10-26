<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courrier_copies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('courrier_id')->constrained('courriers')->restrictOnDelete();
            $table->foreignId('direction_id')->constrained('directions')->restrictOnDelete();
            $table->foreignId('ajoute_par_id')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['courrier_id', 'direction_id']);
        });

        Schema::table('dispatchs_courrier', function (Blueprint $table): void {
            $table->date('echeance_at')->nullable();
            $table->timestampTz('relance_direction_envoyee_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dispatchs_courrier', function (Blueprint $table): void {
            $table->dropColumn(['echeance_at', 'relance_direction_envoyee_at']);
        });
        Schema::dropIfExists('courrier_copies');
    }
};
