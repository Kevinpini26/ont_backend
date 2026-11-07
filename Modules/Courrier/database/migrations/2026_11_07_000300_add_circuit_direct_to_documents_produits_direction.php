<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 6 : circuit direct entre directions (désactivé par défaut). Nature du document, transmission directe
 * au secrétariat destinataire et accusé de réception de celui-ci.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_produits_direction', function (Blueprint $table): void {
            $table->string('nature')->nullable();
            $table->foreignId('circuit_direct_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('circuit_direct_at')->nullable();
            $table->foreignId('recu_destination_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('recu_destination_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents_produits_direction', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('circuit_direct_par_id');
            $table->dropConstrainedForeignId('recu_destination_par_id');
            $table->dropColumn(['nature', 'circuit_direct_at', 'recu_destination_at']);
        });
    }
};
