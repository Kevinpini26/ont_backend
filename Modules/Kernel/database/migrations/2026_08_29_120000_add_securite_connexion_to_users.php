<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Levé à la création du compte par un administrateur, ou à une
            // réinitialisation de mot de passe qu'il déclenche lui-même
            // (StoreUserRequest/UpdateUserRequest) : bloque tout le reste de
            // l'API tant que l'utilisateur n'a pas changé ce mot de passe
            // initial (voir EnsureMotDePasseAJour).
            $table->boolean('doit_changer_mot_de_passe')->default(false)->after('password');

            // Verrouillage temporaire distinct du limiteur de débit
            // 'auth' (par IP/e-mail, fenêtre glissante d'une minute) :
            // cinq échecs consécutifs sur ce compte précis verrouillent
            // verrouille_jusqu_a, indépendamment de l'IP appelante — voir
            // AuthController::login().
            $table->unsignedTinyInteger('echecs_connexion_consecutifs')->default(0)->after('doit_changer_mot_de_passe');
            $table->timestamp('verrouille_jusqu_a')->nullable()->after('echecs_connexion_consecutifs');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['doit_changer_mot_de_passe', 'echecs_connexion_consecutifs', 'verrouille_jusqu_a']);
        });
    }
};
