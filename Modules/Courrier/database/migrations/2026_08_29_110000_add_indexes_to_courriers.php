<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index sur les colonnes réellement filtrées par CourrierController::index()
 * et les tableaux de bord (statut, type, direction_origine_id,
 * direction_destination_id, created_at pour le tri/pagination et les
 * filtres de période). PostgreSQL ne crée aucun index sur une colonne de
 * clé étrangère par défaut, contrairement à MySQL — ces deux `foreignId()`
 * n'en avaient donc aucun malgré leur usage constant en filtre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->index('statut');
            $table->index('type');
            $table->index('direction_origine_id');
            $table->index('direction_destination_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropIndex(['statut']);
            $table->dropIndex(['type']);
            $table->dropIndex(['direction_origine_id']);
            $table->dropIndex(['direction_destination_id']);
            $table->dropIndex(['created_at']);
        });
    }
};
