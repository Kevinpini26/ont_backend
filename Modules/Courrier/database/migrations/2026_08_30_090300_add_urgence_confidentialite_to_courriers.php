<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->string('degre_urgence', 20)->default('normal')->after('classification');
            $table->string('niveau_confidentialite', 20)->default('ordinaire')->after('degre_urgence');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn(['degre_urgence', 'niveau_confidentialite']);
        });
    }
};
