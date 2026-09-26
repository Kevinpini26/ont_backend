<?php

namespace Modules\Courrier\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Enums\DegreUrgence;
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
        $dg = User::query()->where('email', 'dg@ont.cd')->firstOrFail();
        $secretariat1 = User::query()->where('email', 'secretariat_1@ont.cd')->firstOrFail();
        $secretariat2 = User::query()->where('email', 'secretariat_2@ont.cd')->firstOrFail();
        // Lot assistants : le rédacteur du projet de réponse et son relecteur
        // désigné doivent être deux comptes distincts (voir
        // SoumettreProjetReponseRequest::withValidator()) — jamais
        // Secrétariat 01, qui ne rédige plus (voir docs/questions-ont.md).
        $redacteur = User::query()->where('email', 'assistant_1@ont.cd')->firstOrFail();
        $relecteur = User::query()->where('email', 'assistant_1@ont.cd')->firstOrFail();

        // Liste ordonnée (pas de tableau associatif indexé par l'enum : PHP
        // n'autorise que des clés int/string) de [statut cible, objet].
        // Le Protocole n'est plus un point de passage par défaut depuis le
        // Lot 1 (bouclage du circuit) : recu -> en_attente_tri directement,
        // via le Secrétariat 01 — voir config('courrier.circuit_transitions.complet').
        $objets = [
            [CourrierStatut::RECU, 'Demande de partenariat touristique — Office du Tourisme du Kwilu'],
            [CourrierStatut::EN_ATTENTE_TRI, 'Invitation au Forum régional du tourisme durable'],
            [CourrierStatut::EN_ATTENTE_CLASSEUR, 'Courrier sans caractère urgent — gardé au classeur d\'attente'],
            [CourrierStatut::EN_ATTENTE_AVIS_DG, "Sollicitation d'avis sur une convention de coopération"],
            [CourrierStatut::PROJET_A_REDIGER, 'Demande de subvention — festival culturel de Matadi'],
            [CourrierStatut::PROJET_A_VALIDER, 'Réponse à une requête de la Fédération des hôteliers'],
            [CourrierStatut::SIGNE, "Autorisation d'exploitation d'un site touristique"],
            [CourrierStatut::ENREGISTRE, 'Correspondance avec le Ministère du Tourisme — accusé de suivi'],
        ];

        foreach ($objets as [$statutCible, $objet]) {
            $courrier = $this->creer($circuit, $reception, $direction, $objet, $statutCible->value);

            if ($statutCible === CourrierStatut::RECU) {
                continue;
            }

            $circuit->accuserReception($courrier, $secretariat1);
            $courrier = $circuit->transmettreTri($courrier, $secretariat1);

            if ($statutCible === CourrierStatut::EN_ATTENTE_TRI) {
                continue;
            }

            $circuit->accuserReception($courrier, $secretariat1);
            // Normal reste au classeur (voir CourrierStatut::EN_ATTENTE_CLASSEUR) ;
            // tout le reste de la liste doit franchir cette étape, donc urgent.
            $degreUrgence = $statutCible === CourrierStatut::EN_ATTENTE_CLASSEUR ? DegreUrgence::NORMAL : DegreUrgence::URGENT;
            $courrier = $circuit->transmettreEnAttenteAvisDg($courrier, $secretariat1, $degreUrgence);

            if ($statutCible === CourrierStatut::EN_ATTENTE_CLASSEUR || $statutCible === CourrierStatut::EN_ATTENTE_AVIS_DG) {
                continue;
            }

            $circuit->accuserReception($courrier, $dg);
            $courrier = $circuit->rendreAvisDg($courrier, $dg, AvisDg::FAVORABLE, 'Avis favorable — dossier conforme.');

            if ($statutCible === CourrierStatut::PROJET_A_REDIGER) {
                continue;
            }

            $circuit->accuserReception($courrier, $redacteur);
            $courrier = $circuit->soumettreProjetReponse($courrier, $redacteur, [
                'type' => 'doc',
                'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Projet de réponse — brouillon de démonstration.']]]],
            ], $relecteur->id);

            if ($statutCible === CourrierStatut::PROJET_A_VALIDER) {
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
