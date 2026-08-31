<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jeton à usage unique et à durée courte reliant une page de capture
 * mobile (sans authentification Sanctum classique) à un courrier ou un
 * dossier stagiaire précis — même principe que
 * Modules\Stagiaires\Models\StagiaireLienPublic, généralisé en
 * relation polymorphe (capturable_type/capturable_id) puisqu'il sert
 * désormais aux deux modules, et complété d'une expiration (StagiaireLienPublic
 * n'expire jamais, seulement "consommé" ou non — ici, 15 minutes glissantes
 * depuis la génération, voir JetonCaptureNumerisation::genererPour()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jetons_capture_numerisation', function (Blueprint $table) {
            $table->id();
            $table->string('capturable_type');
            $table->unsignedBigInteger('capturable_id');
            $table->string('token', 64)->unique();
            $table->timestamp('expire_at');
            $table->timestamp('consomme_at')->nullable();
            $table->foreignId('cree_par_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['capturable_type', 'capturable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jetons_capture_numerisation');
    }
};
