<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->foreignId('valide_signature_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valide_signature_at')->nullable();
            $table->string('pdf_a_signer_chemin')->nullable();
            $table->string('pdf_a_signer_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('valide_signature_par_id');
            $table->dropColumn([
                'valide_signature_at',
                'pdf_a_signer_chemin',
                'pdf_a_signer_sha256',
            ]);
        });
    }
};
