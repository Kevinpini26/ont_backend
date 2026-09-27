<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructions_courrier_dg', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donneur_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('destinataire_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('instruction');
            $table->timestampTz('expire_at')->nullable();
            $table->timestampTz('ouvert_at')->nullable();
            $table->foreignId('ouvert_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('consomme_at')->nullable();
            $table->foreignId('consomme_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('annule_at')->nullable();
            $table->foreignId('courrier_id')->nullable()->unique()->constrained('courriers')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['destinataire_user_id', 'consomme_at', 'annule_at']);
            $table->index(['donneur_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            alter table instructions_courrier_dg
            add constraint instructions_courrier_dg_consommation_complete check (
                (consomme_at is null and consomme_par_id is null and courrier_id is null)
                or (consomme_at is not null and consomme_par_id is not null and courrier_id is not null)
            )
            SQL);
        DB::statement(<<<'SQL'
            alter table instructions_courrier_dg
            add constraint instructions_courrier_dg_non_annulee_si_consommee check (
                annule_at is null or consomme_at is null
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('instructions_courrier_dg');
    }
};
