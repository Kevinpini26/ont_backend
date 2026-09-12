<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courrier_transitions', function (Blueprint $table) {
            $table->foreignId('bordereau_lot_id')->nullable()->after('destinataire_user_id')
                ->constrained('bordereaux_lot')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courrier_transitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bordereau_lot_id');
        });
    }
};
