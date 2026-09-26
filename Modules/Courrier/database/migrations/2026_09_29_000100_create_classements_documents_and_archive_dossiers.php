<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classements_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->unique()->constrained('courriers')->cascadeOnDelete();
            $table->foreignId('dossier_id')->constrained('dossiers')->restrictOnDelete();
            $table->foreignId('dispatch_courrier_id')->unique()->constrained('dispatchs_courrier')->restrictOnDelete();
            $table->string('statut');
            $table->string('cote')->nullable();
            $table->string('emplacement');
            $table->text('observation')->nullable();
            $table->foreignId('classe_par_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('classe_at');
            $table->foreignId('archive_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('archive_at')->nullable();
            $table->timestampsTz();
            $table->index(['statut', 'classe_at']);
            $table->index('cote');
        });
        Schema::table('dossiers', function (Blueprint $table) {
            $table->string('statut_archivage')->default('actif');
            $table->foreignId('archivage_decide_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('archivage_decide_at')->nullable();
            $table->foreignId('archive_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('archive_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archivage_decide_par_id');
            $table->dropConstrainedForeignId('archive_par_id');
            $table->dropColumn(['statut_archivage', 'archivage_decide_at', 'archive_at']);
        });
        Schema::dropIfExists('classements_documents');
    }
};
