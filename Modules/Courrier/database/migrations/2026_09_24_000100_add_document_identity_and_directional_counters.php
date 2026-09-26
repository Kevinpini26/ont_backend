<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->string('reference_documentaire')->nullable()->unique()->after('numero_enregistrement');
        });

        Schema::table('courrier_numero_compteurs', function (Blueprint $table) {
            $table->foreignId('direction_id')->nullable()->after('annee')->constrained('directions')->restrictOnDelete();
        });

        DB::statement('alter table courrier_numero_compteurs alter column type type varchar(40)');
        DB::statement('alter table courrier_numero_compteurs drop constraint courrier_numero_compteurs_pkey');
        DB::statement('create unique index courrier_compteurs_globaux_unique on courrier_numero_compteurs (type, annee) where direction_id is null');
        DB::statement('create unique index courrier_compteurs_direction_unique on courrier_numero_compteurs (type, annee, direction_id) where direction_id is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists courrier_compteurs_direction_unique');
        DB::statement('drop index if exists courrier_compteurs_globaux_unique');
        DB::statement('delete from courrier_numero_compteurs where direction_id is not null');

        Schema::table('courrier_numero_compteurs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('direction_id');
        });

        DB::statement('alter table courrier_numero_compteurs add primary key (type, annee)');
        DB::statement('alter table courrier_numero_compteurs alter column type type varchar(20)');

        Schema::table('courriers', function (Blueprint $table) {
            $table->dropUnique(['reference_documentaire']);
            $table->dropColumn('reference_documentaire');
        });
    }
};
