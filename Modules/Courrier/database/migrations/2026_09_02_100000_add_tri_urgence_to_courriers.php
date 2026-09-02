<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 2 (tri par urgence) : degre_urgence devient nullable, sans défaut SQL
 * ni applicatif (voir Courrier::$attributes) — "pas encore trié" et "trié
 * comme normal" ne sont pas le même état, le premier doit rester
 * distinguable du second (voir CourrierCircuitService::transmettreEnAttenteAvisDg()).
 * Les courriers déjà existants portant 'normal' par l'ancien défaut
 * gardent cette valeur telle quelle (pas de UPDATE rétroactif : on ne sait
 * pas s'ils ont réellement été triés ou seulement touchés par l'ancien
 * défaut). SQL brut (pas Schema::...->change()) : évite une dépendance à
 * doctrine/dbal, non installé dans ce projet.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE courriers ALTER COLUMN degre_urgence DROP NOT NULL');
        DB::statement('ALTER TABLE courriers ALTER COLUMN degre_urgence DROP DEFAULT');

        Schema::table('courriers', function (Blueprint $table) {
            $table->timestamp('urgence_triee_at')->nullable()->after('degre_urgence');
            $table->foreignId('urgence_triee_par_id')->nullable()->after('urgence_triee_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('urgence_triee_par_id');
            $table->dropColumn('urgence_triee_at');
        });

        DB::statement("UPDATE courriers SET degre_urgence = 'normal' WHERE degre_urgence IS NULL");
        DB::statement("ALTER TABLE courriers ALTER COLUMN degre_urgence SET DEFAULT 'normal'");
        DB::statement('ALTER TABLE courriers ALTER COLUMN degre_urgence SET NOT NULL');
    }
};
