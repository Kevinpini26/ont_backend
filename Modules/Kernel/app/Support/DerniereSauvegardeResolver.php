<?php

namespace Modules\Kernel\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Extrait de SupervisionController pour être réutilisé par
 * AdminDashboardController sans dupliquer la logique de recherche du
 * fichier le plus récent sur le disque de sauvegarde (voir
 * SauvegarderBaseDeDonneesCommand, qui n'enregistre aucun état structuré
 * ailleurs — ce résolveur interroge donc directement le disque).
 */
class DerniereSauvegardeResolver
{
    /**
     * @return array{fichier: string, taille_octets: int, date: string}|null
     */
    public function resoudre(): ?array
    {
        $disque = config('backup.disk');
        $fichiers = Storage::disk($disque)->files('database');
        if ($fichiers === []) {
            return null;
        }

        $plusRecent = collect($fichiers)->sortByDesc(fn ($f) => Storage::disk($disque)->lastModified($f))->first();

        return [
            'fichier' => basename($plusRecent),
            'taille_octets' => Storage::disk($disque)->size($plusRecent),
            'date' => now()->createFromTimestamp(Storage::disk($disque)->lastModified($plusRecent))->toIso8601String(),
        ];
    }
}
