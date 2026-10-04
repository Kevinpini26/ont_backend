<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->foreignId('scan_signe_televerse_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scan_signe_televerse_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scan_signe_televerse_par_id');
            $table->dropColumn('scan_signe_televerse_at');
        });
    }
};
