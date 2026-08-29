<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Le numéro d'attestation (ATT-AAAA-NNNNNN) est séquentiel : le QR code de
 * l'attestation encode désormais un jeton aléatoire de 32 caractères
 * plutôt que ce numéro, pour empêcher la vérification publique par simple
 * énumération. Les attestations déjà émises reçoivent un jeton rétroactif
 * (leur PDF déjà imprimé continue de fonctionner via l'ancien numéro,
 * accompagné du nom du stagiaire — voir AttestationPublicController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->string('token_verification', 32)->nullable()->unique()->after('numero_attestation');
        });

        DB::table('stagiaires')
            ->whereNotNull('numero_attestation')
            ->whereNull('token_verification')
            ->select('id')
            ->orderBy('id')
            ->each(function ($stagiaire) {
                DB::table('stagiaires')
                    ->where('id', $stagiaire->id)
                    ->update(['token_verification' => Str::random(32)]);
            });
    }

    public function down(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->dropColumn('token_verification');
        });
    }
};
