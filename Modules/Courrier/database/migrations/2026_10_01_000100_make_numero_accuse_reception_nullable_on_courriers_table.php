<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->string('numero_accuse_reception')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Une fois des courriers physiques ou internes créés avec un AR
        // nul, rétablir NOT NULL imposerait de fabriquer ou d'effacer des
        // identités historiques. Le rollback reste donc volontairement
        // non destructif : aucune donnée ne peut être restaurée ici.
    }
};
