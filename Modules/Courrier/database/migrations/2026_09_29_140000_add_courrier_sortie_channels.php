<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->string('mode_sortie', 32)->nullable();
            $table->timestamp('courriel_envoye_at')->nullable();
            $table->foreignId('courriel_envoye_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('courriel_destinataire')->nullable();
            $table->timestamp('retrait_disponible_at')->nullable();
            $table->foreignId('retrait_disponible_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('retrait_disponible_observation')->nullable();
            $table->foreignId('retrait_effectue_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('retrait_observation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('courriel_envoye_par_id');
            $table->dropConstrainedForeignId('retrait_disponible_par_id');
            $table->dropConstrainedForeignId('retrait_effectue_par_id');
            $table->dropColumn([
                'mode_sortie',
                'courriel_envoye_at',
                'courriel_destinataire',
                'retrait_disponible_at',
                'retrait_disponible_observation',
                'retrait_observation',
            ]);
        });
    }
};
