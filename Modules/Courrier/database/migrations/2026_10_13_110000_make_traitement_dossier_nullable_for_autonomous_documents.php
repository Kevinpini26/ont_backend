<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traitements_direction', function (Blueprint $table) {
            $table->dropForeign('traitements_direction_dossier_id_foreign');
            $table->unsignedBigInteger('dossier_id')->nullable()->change();
            $table->foreign('dossier_id', 'traitements_direction_dossier_id_foreign')
                ->references('id')
                ->on('dossiers')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('traitements_direction', function (Blueprint $table) {
            $table->dropForeign('traitements_direction_dossier_id_foreign');
            $table->unsignedBigInteger('dossier_id')->nullable(false)->change();
            $table->foreign('dossier_id', 'traitements_direction_dossier_id_foreign')
                ->references('id')
                ->on('dossiers')
                ->restrictOnDelete();
        });
    }
};
