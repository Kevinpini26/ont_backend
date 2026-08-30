<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fait du courrier de réponse un enregistrement à part entière (Courrier
 * sens=sortant) plutôt qu'une colonne de l'enregistrement d'arrivée —
 * projet_reponse_contenu reste utilisé tel quel comme corps du courrier
 * sortant lui-même, pas dupliqué.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->string('sens', 20)->default('entrant')->after('type');
            $table->foreignId('en_reponse_a_courrier_id')->nullable()->after('sens')->constrained('courriers')->nullOnDelete();
            $table->string('destinataire_externe_nom')->nullable()->after('en_reponse_a_courrier_id');
            $table->string('destinataire_externe_email')->nullable()->after('destinataire_externe_nom');
            $table->string('mode_expedition', 20)->nullable()->after('destinataire_externe_email');
            $table->date('date_envoi')->nullable()->after('mode_expedition');
            $table->string('numero_depart')->nullable()->unique()->after('numero_enregistrement');

            // Preuve de remise — un courrier sortant confié à un porteur
            // n'est réellement "délivré" qu'une fois la décharge signée
            // rapportée, pas au seul moment où il quitte l'Office.
            $table->timestamp('remis_le')->nullable()->after('date_envoi');
            $table->string('remis_a')->nullable()->after('remis_le');
            $table->string('mode_remise', 30)->nullable()->after('remis_a');
            $table->string('decharge_remise_chemin')->nullable()->after('mode_remise');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('en_reponse_a_courrier_id');
            $table->dropColumn([
                'sens', 'destinataire_externe_nom', 'destinataire_externe_email',
                'mode_expedition', 'date_envoi', 'numero_depart',
                'remis_le', 'remis_a', 'mode_remise', 'decharge_remise_chemin',
            ]);
        });
    }
};
