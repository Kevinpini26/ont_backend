<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dg_interims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dg_titulaire_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('dga_interimaire_id')->constrained('users')->restrictOnDelete();
            $table->text('motif');
            $table->timestamp('started_at');
            $table->foreignId('started_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE dg_interims ADD CONSTRAINT dg_interims_fin_coherente CHECK ((ended_at IS NULL AND ended_by_id IS NULL) OR (ended_at IS NOT NULL AND ended_by_id IS NOT NULL AND ended_at >= started_at))');
        DB::statement('ALTER TABLE dg_interims ADD CONSTRAINT dg_interims_motif_non_vide CHECK (length(btrim(motif)) > 0)');
        DB::statement('CREATE UNIQUE INDEX dg_interims_un_seul_ouvert ON dg_interims ((1)) WHERE ended_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('dg_interims');
    }
};
