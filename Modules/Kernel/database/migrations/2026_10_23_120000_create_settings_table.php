<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paramètres de l'organisation éditables en production sans déploiement
 * (nom, contacts, formats, délais d'alerte...). Clé/valeur plutôt qu'une
 * colonne par paramètre : une ligne par paramètre ajouté plus tard sans
 * nouvelle migration de schéma, seulement un nouvel appel à
 * Setting::valeur() côté code. Aucune ligne n'est pré-remplie ici : tant
 * qu'un paramètre n'a pas été modifié depuis l'écran dédié, Setting::valeur()
 * retourne la valeur par défaut codée à l'appel (même convention que
 * config(), voir Modules\Kernel\Models\Setting).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('cle')->primary();
            $table->json('valeur');
            $table->foreignId('modifie_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
