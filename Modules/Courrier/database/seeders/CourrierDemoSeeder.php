<?php

namespace Modules\Courrier\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Un dossier « garé » à chaque étape atteignable du circuit courrier
 * standard, pour qu'une démonstration à la tutelle montre immédiatement le
 * pipeline complet (files d'attente de chaque poste, bordereaux de
 * transmission, PDF signé...) sans avoir à faire progresser un dossier à la
 * main. Chaque dossier est volontairement laissé « en transit » à son
 * étape cible (décharge suivante non encore donnée) plutôt que déchargé :
 * c'est l'état réaliste d'un dossier qui vient d'arriver dans une file.
 * N'est jamais appelé en dehors de l'environnement local — voir
 * CourrierDatabaseSeeder::run().
 */
class CourrierDemoSeeder extends Seeder
{
    public function run(CourrierCircuitService $circuit): void
    {
        $direction = Direction::query()->where('code', 'DMC')->firstOrFail();

        $reception = User::query()->where('email', 'reception@ont.cd')->firstOrFail();
        $protocole = User::query()->where('email', 'protocole@ont.cd')->firstOrFail();
        $dg = User::query()->where('email', 'dg@ont.cd')->firstOrFail();
        $secretariat1 = User::query()->where('email', 'secretariat_1@ont.cd')->firstOrFail();
        $secretariat2 = User::query()->where('email', 'secretariat_2@ont.cd')->firstOrFail();
        $relecteur = User::query()->where('email', 'assistant_1@ont.cd')->firstOrFail();

        // Liste ordonnée (pas de tableau associatif indexé par l'enum : PHP
        // n'autorise que des clés int/string) de [statut cible, objet].
        $objets = [
            [CourrierStatut::RECU, 'Demande de partenariat touristique — Office du Tourisme du Kwilu'],
            [CourrierStatut::AU_PROTOCOLE, 'Invitation au Forum régional du tourisme durable'],
            [CourrierStatut::EN_ATTENTE_AVIS_DG, "Sollicitation d'avis sur une convention de coopération"],
            [CourrierStatut::PROJET_REPONSE_EN_COURS, 'Demande de subvention — festival culturel de Matadi'],
            [CourrierStatut::EN_RELECTURE, 'Réponse à une requête de la Fédération des hôteliers'],
            [CourrierStatut::SIGNE, "Autorisation d'exploitation d'un site touristique"],
            [CourrierStatut::ENREGISTRE, 'Correspondance avec le Ministère du Tourisme — accusé de suivi'],
        ];

        foreach ($objets as [$statutCible, $objet]) {
            $courrier = $this->creer($circuit, $reception, $direction, $objet, $statutCible->value);

            if ($statutCible === CourrierStatut::RECU) {
                continue;
            }

            $circuit->accuserReception($courrier, $protocole);
            $courrier = $circuit->transmettreAuProtocole($courrier, $protocole);

            if ($statutCible === CourrierStatut::AU_PROTOCOLE) {
                continue;
            }

            $circuit->accuserReception($courrier, $protocole);
            $courrier = $circuit->transmettreEnAttenteAvisDg($courrier, $protocole);

            if ($statutCible === CourrierStatut::EN_ATTENTE_AVIS_DG) {
                continue;
            }

            $circuit->accuserReception($courrier, $dg);
            $courrier = $circuit->rendreAvisDg($courrier, $dg, AvisDg::FAVORABLE, 'Avis favorable — dossier conforme.');

            if ($statutCible === CourrierStatut::PROJET_REPONSE_EN_COURS) {
                continue;
            }

            $circuit->accuserReception($courrier, $secretariat1);
            $courrier = $circuit->soumettreProjetReponse($courrier, $secretariat1, [
                'type' => 'doc',
                'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Projet de réponse — brouillon de démonstration.']]]],
            ], $relecteur->id);

            if ($statutCible === CourrierStatut::EN_RELECTURE) {
                continue;
            }

            $circuit->accuserReception($courrier, $relecteur);
            $circuit->validerRelecture($courrier, $relecteur, 'Relu, aucune remarque.');
            $courrier = $circuit->signer($courrier, $dg);

            if ($statutCible === CourrierStatut::SIGNE) {
                continue;
            }

            $circuit->accuserReception($courrier, $secretariat2);
            $circuit->enregistrer(
                $courrier,
                $secretariat2,
                CourrierClassification::EXTERNE,
                null,
                'AR-PARTENAIRE-DEMO-'.$courrier->id,
            );
        }
    }

    private function creer(CourrierCircuitService $circuit, User $reception, Direction $direction, string $objet, string $cle): Courrier
    {
        $chemin = "courriers/demo-{$cle}.pdf";
        Storage::disk('local')->put($chemin, '%PDF-1.4 — pièce jointe de démonstration.');

        return $circuit->creer($reception, [
            'objet' => $objet,
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'direction_destination_id' => $direction->id,
            'piece_jointe_chemin' => $chemin,
        ]);
    }
}
