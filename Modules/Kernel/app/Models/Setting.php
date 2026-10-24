<?php

namespace Modules\Kernel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Paramètre de l'organisation, clé/valeur, édité depuis l'écran dédié
 * (voir SettingController). `valeur()` est le seul point d'entrée en
 * lecture pour le reste du code : jamais un appel direct à
 * Setting::query() ailleurs, pour que le cache (invalidé à chaque
 * écriture, voir definir()) reste la seule source de vérité en lecture.
 */
class Setting extends Model
{
    protected $primaryKey = 'cle';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['cle', 'valeur', 'modifie_par_id'];

    protected function casts(): array
    {
        return ['valeur' => 'array'];
    }

    public function modifiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modifie_par_id');
    }

    /**
     * @param  mixed  $defaut  Valeur utilisée si le paramètre n'a jamais été
     *                         modifié depuis l'écran dédié — même rôle que
     *                         le deuxième argument de config().
     */
    public static function valeur(string $cle, mixed $defaut = null): mixed
    {
        $enveloppe = Cache::rememberForever(
            self::cleCache($cle),
            fn () => ['v' => static::query()->find($cle)?->valeur],
        );

        return $enveloppe['v'] ?? $defaut;
    }

    public static function definir(string $cle, mixed $valeur, ?User $auteur = null): void
    {
        static::query()->updateOrCreate(
            ['cle' => $cle],
            ['valeur' => $valeur, 'modifie_par_id' => $auteur?->id],
        );

        Cache::forget(self::cleCache($cle));
    }

    private static function cleCache(string $cle): string
    {
        return "settings.{$cle}";
    }
}
