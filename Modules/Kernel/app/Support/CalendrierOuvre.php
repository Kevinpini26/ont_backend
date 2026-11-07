<?php

namespace Modules\Kernel\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\Kernel\Models\Setting;

/**
 * Calendrier de travail de l'ONT : jours ouvrés, horaires et jours fériés, réglables dans
 * « Paramètres de l'organisation ». Tous les délais du circuit se comptent en heures ouvrées :
 * un dépôt du vendredi soir n'est pas en retard le samedi.
 *
 * Les heures sont lues dans le fuseau de l'ONT (Africa/Kinshasa par défaut), puis rendues en UTC,
 * comme les dates stockées en base.
 */
class CalendrierOuvre
{
    /** Garde-fou : jamais plus de deux ans de recherche, même avec un calendrier mal réglé. */
    private const JOURS_MAX = 730;

    /** @var list<int> 1 = lundi … 7 = dimanche */
    private array $joursOuvres;

    private int $debutMinutes;

    private int $finMinutes;

    private string $fuseau;

    /** @var list<string> « MM-JJ » (chaque année) ou « AAAA-MM-JJ » (une fois) */
    private array $feries;

    /**
     * @param  array<string, mixed>  $reglages  jours_ouvres, heure_debut, heure_fin, fuseau, jours_feries
     */
    public function __construct(array $reglages = [])
    {
        $this->joursOuvres = array_values(array_unique(array_map('intval', (array) ($reglages['jours_ouvres'] ?? [1, 2, 3, 4, 5]))));
        $this->debutMinutes = self::enMinutes((string) ($reglages['heure_debut'] ?? '08:30'));
        $this->finMinutes = self::enMinutes((string) ($reglages['heure_fin'] ?? '15:30'));
        $this->fuseau = (string) ($reglages['fuseau'] ?? 'Africa/Kinshasa');
        $this->feries = [];
        foreach ((array) ($reglages['jours_feries'] ?? []) as $ferie) {
            $this->feries[] = is_array($ferie) ? (string) ($ferie['date'] ?? '') : (string) $ferie;
        }
    }

    /** Le calendrier tel que réglé par l'administrateur (valeurs par défaut sinon). */
    public static function depuisReglages(): self
    {
        $defauts = (array) config('kernel.calendrier', []);

        // Les valeurs par défaut viennent de config('kernel.calendrier'), le constructeur ne lit rien.
        return new self([
            'jours_ouvres' => Setting::valeur('calendrier.jours_ouvres', $defauts['jours_ouvres'] ?? null),
            'heure_debut' => Setting::valeur('calendrier.heure_debut', $defauts['heure_debut'] ?? null),
            'heure_fin' => Setting::valeur('calendrier.heure_fin', $defauts['heure_fin'] ?? null),
            'fuseau' => Setting::valeur('calendrier.fuseau', $defauts['fuseau'] ?? null),
            'jours_feries' => Setting::valeur('calendrier.jours_feries', $defauts['jours_feries'] ?? null),
        ]);
    }

    /** Durée d'une journée de travail, en heures (7 par défaut). */
    public function heuresParJour(): float
    {
        return max(0, $this->finMinutes - $this->debutMinutes) / 60;
    }

    public function estJourOuvre(CarbonInterface $instant): bool
    {
        $jour = CarbonImmutable::instance($instant)->setTimezone($this->fuseau);

        return in_array($jour->dayOfWeekIso, $this->joursOuvres, true)
            && ! in_array($jour->format('m-d'), $this->feries, true)
            && ! in_array($jour->format('Y-m-d'), $this->feries, true);
    }

    /** Heures ouvrées écoulées entre deux instants (0 si la fin précède le début). */
    public function heuresOuvreesEntre(CarbonInterface $debut, CarbonInterface $fin): float
    {
        $debut = CarbonImmutable::instance($debut)->setTimezone($this->fuseau);
        $fin = CarbonImmutable::instance($fin)->setTimezone($this->fuseau);
        if ($fin->lte($debut)) {
            return 0.0;
        }

        $minutes = 0;
        $jour = $debut->startOfDay();
        for ($i = 0; $i <= self::JOURS_MAX && $jour->lte($fin); $i++, $jour = $jour->addDay()) {
            if (! $this->estJourOuvre($jour)) {
                continue;
            }
            $ouverture = $jour->addMinutes($this->debutMinutes);
            $fermeture = $jour->addMinutes($this->finMinutes);
            $de = $debut->max($ouverture);
            $a = $fin->min($fermeture);
            if ($a->gt($de)) {
                $minutes += $de->diffInMinutes($a);
            }
        }

        return $minutes / 60;
    }

    /**
     * Instant le plus récent tel qu'il reste exactement $heures ouvrées jusqu'à $maintenant :
     * un élément arrivé avant cet instant est en retard. Sert aussi de seuil dans les requêtes.
     */
    public function seuil(float $heures, ?CarbonInterface $maintenant = null): Carbon
    {
        $maintenant = CarbonImmutable::instance($maintenant ?? now())->setTimezone($this->fuseau);
        $reste = (int) round($heures * 60);
        if ($reste <= 0) {
            return Carbon::instance($maintenant)->utc();
        }

        $jour = $maintenant->startOfDay();
        for ($i = 0; $i <= self::JOURS_MAX; $i++, $jour = $jour->subDay()) {
            if (! $this->estJourOuvre($jour)) {
                continue;
            }
            $ouverture = $jour->addMinutes($this->debutMinutes);
            $fermeture = $jour->addMinutes($this->finMinutes)->min($maintenant);
            if ($fermeture->lte($ouverture)) {
                continue;
            }
            $disponible = $ouverture->diffInMinutes($fermeture);
            if ($disponible >= $reste) {
                return Carbon::instance($fermeture->subMinutes($reste))->utc();
            }
            $reste -= $disponible;
        }

        // Aucun jour ouvré trouvé (calendrier vide) : rien n'est jamais en retard.
        return Carbon::instance($maintenant->subDays(self::JOURS_MAX * 10))->utc();
    }

    /** Vrai si plus de $heures ouvrées se sont écoulées depuis $depuis. */
    public function estEnRetard(?CarbonInterface $depuis, float $heures, ?CarbonInterface $maintenant = null): bool
    {
        return $depuis !== null && CarbonImmutable::instance($depuis)->lt($this->seuil($heures, $maintenant));
    }

    /** Recule de $jours jours ouvrés complets (pour les délais exprimés en jours). */
    public function seuilJours(int $jours, ?CarbonInterface $maintenant = null): Carbon
    {
        return $this->seuil($jours * $this->heuresParJour(), $maintenant);
    }

    /**
     * Une échéance (date) est dépassée à la fin de la journée de travail du premier jour ouvré à partir
     * de cette date : une échéance tombant un samedi n'est en retard qu'après la fermeture du lundi.
     */
    public function echeanceDepassee(CarbonInterface $echeance, ?CarbonInterface $maintenant = null): bool
    {
        $jour = CarbonImmutable::parse($echeance->format('Y-m-d'), $this->fuseau);
        for ($i = 0; $i <= self::JOURS_MAX && ! $this->estJourOuvre($jour); $i++) {
            $jour = $jour->addDay();
        }

        return CarbonImmutable::instance($maintenant ?? now())->gt($jour->addMinutes($this->finMinutes));
    }

    private static function enMinutes(string $heure): int
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $heure, $m)) {
            return 0;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
