<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directions', function (Blueprint $table) {
            $table->boolean('est_operationnelle')->default(true)->after('actif');
        });

        // Renommages conservant les identifiants et toutes les relations.
        DB::table('directions')->where('code', 'DRHL')->update([
            'code' => 'DRH',
            'nom' => 'Direction des Ressources Humaines',
        ]);
        DB::table('directions')->where('code', 'DMFPT')->update([
            'code' => 'DMR',
            'nom' => 'Direction de Mobilisation des Ressources',
        ]);

        $directions = [
            ['code' => 'DG', 'nom' => 'Direction Générale', 'est_operationnelle' => false],
            ['code' => 'DMC', 'nom' => 'Direction de Marketing et Communication', 'est_operationnelle' => true],
            ['code' => 'DEP', 'nom' => 'Direction des Études, Planification et Développement Touristique', 'est_operationnelle' => true],
            ['code' => 'DIPP', 'nom' => 'Direction des Investissements, Partenariats et Patrimoines Touristiques', 'est_operationnelle' => true],
            ['code' => 'DRH', 'nom' => 'Direction des Ressources Humaines', 'est_operationnelle' => true],
            ['code' => 'DF', 'nom' => 'Direction Financière', 'est_operationnelle' => true],
            ['code' => 'DMR', 'nom' => 'Direction de Mobilisation des Ressources', 'est_operationnelle' => true],
            ['code' => 'DFP', 'nom' => 'Direction de Formation et de Professionnalisation', 'est_operationnelle' => true],
            ['code' => 'DAI', 'nom' => 'Direction de l’Audit Interne', 'est_operationnelle' => true],
        ];

        foreach ($directions as $direction) {
            $existe = DB::table('directions')->where('code', $direction['code'])->exists();
            $valeurs = [...$direction, 'actif' => true, 'updated_at' => now()];

            if ($existe) {
                DB::table('directions')->where('code', $direction['code'])->update($valeurs);
            } else {
                DB::table('directions')->insert([...$valeurs, 'created_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // La ligne DG et les données liées sont volontairement conservées.
        Schema::table('directions', function (Blueprint $table) {
            $table->dropColumn('est_operationnelle');
        });
    }
};
