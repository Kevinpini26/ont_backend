<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remplace à terme piece_jointe_chemin (colonne unique sur courriers) par
 * une table à plusieurs lignes : un courrier officiel arrive presque
 * toujours avec ses annexes, pas un seul fichier. La colonne
 * piece_jointe_chemin N'EST PAS supprimée ici — encore lue par plusieurs
 * contrôleurs/ressources et par le frontend (vérifié par grep avant
 * d'écrire cette migration) ; cette table coexiste, alimentée en
 * parallèle pour tout nouveau courrier, et reprend l'existant par backfill
 * ci-dessous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courrier_pieces_jointes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->constrained('courriers')->cascadeOnDelete();
            $table->string('libelle');
            $table->string('chemin');
            $table->string('type_mime')->nullable();
            $table->unsignedBigInteger('taille_octets')->nullable();
            $table->unsignedInteger('ordre')->default(1);
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['courrier_id', 'ordre']);
        });

        // Backfill : chaque courrier ayant déjà une piece_jointe_chemin
        // devient une ligne unique (ordre=1) dans la nouvelle table,
        // plutôt que de partir d'un historique vide.
        DB::statement(<<<'SQL'
            insert into courrier_pieces_jointes (courrier_id, libelle, chemin, ordre, created_at, updated_at)
            select id, 'Pièce jointe', piece_jointe_chemin, 1, now(), now()
            from courriers
            where piece_jointe_chemin is not null
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('courrier_pieces_jointes');
    }
};
