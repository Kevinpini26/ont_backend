<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 6 : la Réception peut marquer un dépôt en ligne « Non recevable », avec un motif obligatoire.
 * Le numéro d'arrivée est conservé, jamais réutilisé, et le dépôt reste au registre avec cette mention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table): void {
            $table->timestampTz('non_recevable_at')->nullable();
            $table->foreignId('non_recevable_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('non_recevable_motif')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('non_recevable_par_id');
            $table->dropColumn(['non_recevable_at', 'non_recevable_motif']);
        });
    }
};
