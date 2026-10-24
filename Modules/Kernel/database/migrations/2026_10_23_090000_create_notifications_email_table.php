<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_email', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('destinataire');
            $table->string('notifiable_type')->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->string('statut')->default('en_file');
            $table->unsignedTinyInteger('tentatives')->default(0);
            $table->text('derniere_erreur')->nullable();
            $table->timestamp('envoye_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index('statut');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_email');
    }
};
