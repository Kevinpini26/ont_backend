<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot B : la diffusion (retenu ou non retenu) devient un acte enregistré,
 * pas un envoi dans le vide — la DFP doit pouvoir répondre à un stagiaire
 * qui affirme ne pas avoir été prévenu. Chaque envoi (premier envoi ou
 * renvoi) est sa propre ligne, jamais mise à jour — un historique complet,
 * pas un seul statut écrasé à chaque renvoi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_diffusion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stagiaire_id')->constrained('stagiaires')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('canal', 10);
            $table->string('destinataire');
            $table->text('contenu');
            $table->timestamp('envoye_at');
            $table->string('statut_remise', 20)->nullable();
            $table->foreignId('envoye_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_diffusion');
    }
};
