<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatchs_courrier', function (Blueprint $table) {
            $table->unsignedInteger('cycle')->default(1)->after('dossier_id');
            $table->index(['courrier_id', 'cycle'], 'dispatchs_courrier_cycle_index');
        });
    }

    public function down(): void
    {
        Schema::table('dispatchs_courrier', function (Blueprint $table) {
            $table->dropIndex('dispatchs_courrier_cycle_index');
            $table->dropColumn('cycle');
        });
    }
};
