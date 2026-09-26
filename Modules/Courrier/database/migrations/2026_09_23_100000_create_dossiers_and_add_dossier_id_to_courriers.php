<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossiers', function (Blueprint $table) {
            $table->id();
            $table->string('libelle')->nullable();
            $table->boolean('importe_historique')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('courriers', function (Blueprint $table) {
            $table->foreignId('dossier_id')->nullable()->after('id')->constrained('dossiers')->restrictOnDelete();
            $table->index(['dossier_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropIndex(['dossier_id', 'created_at']);
            $table->dropConstrainedForeignId('dossier_id');
        });
        Schema::dropIfExists('dossiers');
    }
};
