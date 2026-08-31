<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique complet des versions numérisées d'un document — courrier ou
 * dossier stagiaire (relation polymorphe, voir AuditLog::auditable pour le
 * même principe déjà en place dans ce projet). Placé dans Kernel, pas dans
 * Courrier ni Stagiaires : cross-module par nature, même raisonnement que
 * DelegationResolver (lot 3) — aucun des deux modules concernés ne doit
 * dépendre de l'autre pour ça.
 *
 * Une nouvelle version n'écrase JAMAIS la précédente : le papier continue
 * de vivre après son arrivée (annotation DG, cachet Protocole, numéro du
 * Secrétariat), chaque nouveau scan capture un état réel du document à une
 * étape précise du circuit — voir `etape_circuit`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents_numerises', function (Blueprint $table) {
            $table->id();
            $table->string('numerisable_type');
            $table->unsignedBigInteger('numerisable_id');
            // Sur (numerisable_type, numerisable_id), jamais un id global :
            // la version 1 du courrier n°42 et la version 1 du stagiaire
            // n°42 coexistent sans collision.
            $table->unsignedInteger('version');
            // Libellé libre plutôt qu'une FK vers CourrierStatut : doit
            // s'appliquer aussi bien à un courrier (statut du circuit) qu'à
            // un dossier stagiaire (qui n'a pas le même jeu de statuts).
            $table->string('etape_circuit')->nullable();
            $table->string('chemin');
            $table->unsignedInteger('nombre_pages')->nullable();
            $table->unsignedBigInteger('poids_octets');
            $table->string('source', 20);
            $table->string('sha256', 64);
            $table->string('qualite', 20)->nullable();
            $table->foreignId('capture_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['numerisable_type', 'numerisable_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents_numerises');
    }
};
