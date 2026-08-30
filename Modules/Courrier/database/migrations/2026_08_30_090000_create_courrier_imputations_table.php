<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'imputation normalisée d'un courrier vers une ou plusieurs directions,
 * avec une mention distincte par direction — remplace à terme la lecture
 * directe de courriers.direction_destination_id pour la visibilité (voir
 * CourrierDirectionScope), sans la supprimer : cette colonne continue de
 * piloter le circuit lui-même (routage, quotas), l'imputation n'en est
 * qu'une lecture normalisée côté "qui doit voir ce courrier".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courrier_imputations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->constrained('courriers')->cascadeOnDelete();
            $table->foreignId('direction_id')->constrained('directions')->restrictOnDelete();
            $table->string('mention', 30);
            $table->boolean('est_principale')->default(false);
            $table->foreignId('imputee_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['courrier_id', 'direction_id']);
        });

        // Index partiel Postgres : une seule imputation principale par
        // courrier, garanti par la base plutôt que par la seule discipline
        // applicative — une contrainte unique classique ne peut pas
        // exprimer "unique parmi les lignes où est_principale est vraie".
        DB::statement(
            'create unique index courrier_imputations_une_principale_par_courrier
             on courrier_imputations (courrier_id)
             where est_principale = true',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('courrier_imputations');
    }
};
