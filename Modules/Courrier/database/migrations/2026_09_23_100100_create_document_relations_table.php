<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_source_id')->constrained('courriers')->restrictOnDelete();
            $table->foreignId('document_cible_id')->constrained('courriers')->restrictOnDelete();
            $table->string('type_relation', 40);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('importe_historique')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['document_source_id', 'document_cible_id', 'type_relation'], 'document_relations_unique');
            $table->index(['document_cible_id', 'type_relation']);
        });

        DB::statement('ALTER TABLE document_relations ADD CONSTRAINT document_relations_documents_distincts CHECK (document_source_id <> document_cible_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_relations');
    }
};
