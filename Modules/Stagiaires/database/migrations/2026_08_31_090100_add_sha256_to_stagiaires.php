<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->string('convention_sha256', 64)->nullable()->after('convention_chemin');
            $table->string('engagement_confidentialite_sha256', 64)->nullable()->after('engagement_confidentialite_chemin');
        });
    }

    public function down(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->dropColumn(['convention_sha256', 'engagement_confidentialite_sha256']);
        });
    }
};
