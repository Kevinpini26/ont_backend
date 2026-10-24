<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_email', function (Blueprint $table) {
            // Permet de retrouver l'entrée correspondante de la table
            // standard "failed_jobs" (payload sérialisé complet, y compris
            // le Mailable d'origine) pour relancer un envoi échoué via
            // "queue:retry" — voir SupervisionController::renvoyerEmail().
            $table->string('job_uuid')->nullable()->after('statut');
        });
    }

    public function down(): void
    {
        Schema::table('notifications_email', function (Blueprint $table) {
            $table->dropColumn('job_uuid');
        });
    }
};
