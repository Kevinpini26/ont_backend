<?php

namespace Modules\Kernel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de défense en profondeur sur les réponses de l'API — celle-ci
 * ne sert jamais de HTML (uniquement du JSON), donc le risque direct de
 * XSS/clickjacking sur ces réponses est faible, mais un navigateur mal
 * configuré ou un futur endpoint qui échapperait à cette règle reste
 * couvert. La CSP effective pour la SPA elle-même est une affaire de
 * configuration nginx (voir le guide d'installation), pas de ce middleware
 * — cet en-tête-ci ne fait que verrouiller les réponses de CETTE API.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::appliquer($next($request), $request);
    }

    /**
     * Séparé de handle() pour rester appelable depuis
     * bootstrap/app.php::withExceptions() — une exception (401, 403, 422,
     * 500...) est convertie en réponse par le gestionnaire d'exceptions
     * avant de retraverser la pile de middleware : ce middleware ne la
     * voit jamais dans ce cas (le throw contourne son propre retour de
     * $next()).
     */
    public static function appliquer(Response $response, Request $request): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
