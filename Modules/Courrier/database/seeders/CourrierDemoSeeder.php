<?php

namespace Modules\Courrier\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Courrier\Enums\AvisDg;
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
        // Rejouable : les courriers de démonstration déjà semés ne sont jamais recréés.
        if (Courrier::withoutGlobalScopes()->where('piece_jointe_chemin', 'like', 'courriers/demo-%')->exists()) {
            return;
        }

        $direction = Direction::query()->where('code', 'DMC')->firstOrFail();

        $reception = User::query()->where('email', 'reception@ont.cd')->firstOrFail();
        $dg = User::query()->where('email', 'dg@ont.cd')->firstOrFail();
        $secretariat1 = User::query()->where('email', 'secretariat_1@ont.cd')->firstOrFail();
        // Lot assistants : le rédacteur du projet de réponse et son relecteur
        // désigné doivent être deux comptes distincts (voir
        // SoumettreProjetReponseRequest::withValidator()) — jamais
        // Secrétariat 01, qui ne rédige plus (voir docs/questions-ont.md).

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
        ];

        foreach ($objets as [$statutCible, $objet]) {
            $courrier = $this->creer($circuit, $reception, $direction, $objet, $statutCible->value);

            if ($statutCible === CourrierStatut::RECU) {
                continue;
            }

            $this->accuser($circuit, $courrier, $secretariat1);
            $courrier = $circuit->transmettreTri($courrier, $secretariat1);

            if ($statutCible === CourrierStatut::EN_ATTENTE_TRI) {
                continue;
            }

            $this->accuser($circuit, $courrier, $secretariat1);
            // Normal reste au classeur (voir CourrierStatut::EN_ATTENTE_CLASSEUR) ;
            // tout le reste de la liste doit franchir cette étape, donc urgent.
            $degreUrgence = $statutCible === CourrierStatut::EN_ATTENTE_CLASSEUR ? DegreUrgence::NORMAL : DegreUrgence::URGENT;
            $courrier = $circuit->transmettreEnAttenteAvisDg($courrier, $secretariat1, $degreUrgence);

            if ($statutCible === CourrierStatut::EN_ATTENTE_CLASSEUR || $statutCible === CourrierStatut::EN_ATTENTE_AVIS_DG) {
                continue;
            }

            $this->accuser($circuit, $courrier, $dg);
            $courrier = $circuit->rendreAvisDg($courrier, $dg, AvisDg::FAVORABLE, 'Avis favorable — dossier conforme.');

            // Les étapes suivantes (projet de réponse, relecture, signature, envoi) passent désormais par
            // une mission de préparation confiée par la DG à un assistant : elles se démontrent en direct
            // dans l'application (voir GUIDE-PRESENTATION-ONT.md), pas par un seed figé.
        }
    }

    /** Accuse la réception seulement si la décharge n'a pas déjà été donnée : le seed doit rester rejouable. */
    private function accuser(CourrierCircuitService $circuit, Courrier $courrier, User $utilisateur): void
    {
        $bordereau = $courrier->fresh()->bordereauCourant();

        if ($bordereau !== null && $bordereau->accuse_reception_at === null) {
            $circuit->accuserReception($courrier, $utilisateur);
        }
    }

    private function creer(CourrierCircuitService $circuit, User $reception, Direction $direction, string $objet, string $cle): Courrier
    {
        $chemin = "courriers/demo-{$cle}.pdf";
        Storage::disk('local')->put($chemin, self::pdfDeDemonstration($objet));

        return $circuit->creer($reception, [
            'objet' => $objet,
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'direction_destination_id' => $direction->id,
            'piece_jointe_chemin' => $chemin,
        ]);
    }

    /**
     * Petit PDF valide (une page, du texte) : un lecteur PDF doit pouvoir ouvrir la pièce de démonstration.
     * Les accents sont retirés, car la police standard Helvetica n'est pas encodée en UTF-8.
     */
    public static function pdfDeDemonstration(string $titre): string
    {
        $ascii = static fn (string $texte): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], Str::ascii($texte));
        $flux = 'BT /F1 16 Tf 72 760 Td ('.$ascii($titre).') Tj 0 -28 Td /F1 11 Tf (Piece jointe de demonstration - ONT) Tj ET';
        $objets = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($flux)." >>\nstream\n".$flux."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $positions = [];
        foreach ($objets as $index => $objet) {
            $positions[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$objet."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objets) + 1)."\n0000000000 65535 f \n";
        foreach ($positions as $position) {
            $pdf .= sprintf("%010d 00000 n \n", $position);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objets) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
