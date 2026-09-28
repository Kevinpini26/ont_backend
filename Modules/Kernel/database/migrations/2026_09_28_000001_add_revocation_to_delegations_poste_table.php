<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delegations_poste', function (Blueprint $table) {
            $table->timestamp('revoquee_at')->nullable();
            $table->foreignId('revoquee_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motif_revocation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delegations_poste', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revoquee_par_id');
            $table->dropColumn(['revoquee_at', 'motif_revocation']);
        });
    }
};
