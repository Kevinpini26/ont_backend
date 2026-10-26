<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table): void {
            $table->timestampTz('annule_at')->nullable();
            $table->foreignId('annule_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('motif_annulation')->nullable();
            $table->timestampTz('suspendu_at')->nullable();
            $table->foreignId('suspendu_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('motif_suspension')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('annule_par_id');
            $table->dropConstrainedForeignId('suspendu_par_id');
            $table->dropColumn(['annule_at', 'motif_annulation', 'suspendu_at', 'motif_suspension']);
        });
    }
};
