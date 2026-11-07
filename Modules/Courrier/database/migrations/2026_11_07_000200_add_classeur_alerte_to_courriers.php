<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lot 6 : alerte unique (SEC1 et DG) quand un courrier dépasse le délai du classeur d'attente. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table): void {
            $table->timestampTz('classeur_alerte_envoyee_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table): void {
            $table->dropColumn('classeur_alerte_envoyee_at');
        });
    }
};
