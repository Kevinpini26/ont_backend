<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel des sites physiques de l'ONT — préparation à la présence en
 * province (représentations provinciales), en plus du siège de Kinshasa.
 * Volontairement minimal : une Direction opère depuis UN site (voir
 * `directions.site_id`), sans toucher aux séquences de numérotation, aux
 * quotas ni au routage du circuit, qui restent nationaux dans ce lot —
 * voir docs/questions-ont.md pour ce qui reste à trancher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('ville');
            $table->string('province')->nullable();
            $table->boolean('siege')->default(false);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        $siegeId = DB::table('sites')->insertGetId([
            'nom' => 'Siège — Kinshasa',
            'ville' => 'Kinshasa',
            'province' => 'Kinshasa',
            'siege' => true,
            'actif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Défaut au niveau colonne (pas seulement un backfill ponctuel) :
        // une direction créée après ce lot sans site précisé reste, par
        // défaut, rattachée au siège — cohérent avec le fait qu'aucune
        // représentation provinciale n'existe encore dans les faits.
        Schema::table('directions', function (Blueprint $table) use ($siegeId) {
            $table->foreignId('site_id')->nullable()->default($siegeId)->after('id')->constrained('sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('directions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });

        Schema::dropIfExists('sites');
    }
};
