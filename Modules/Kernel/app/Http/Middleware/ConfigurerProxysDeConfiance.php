<?php

namespace Modules\Kernel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Déclare les proxys de confiance à partir de kernel.trusted_proxies
 * (variable TRUSTED_PROXIES : liste d'adresses ou de plages CIDR séparées par des
 * virgules, ou « * » pour un déploiement où seul le proxy peut joindre l'application).
 * Sans cette déclaration, derrière un reverse proxy HTTPS, l'IP de l'audit et des limiteurs
 * est celle du proxy, le schéma est vu en http (pas de HSTS) et les liens signés sont invalides.
 * Par défaut aucun proxy n'est de confiance : un client ne peut pas falsifier son IP.
 */
class ConfigurerProxysDeConfiance
{
    public function handle(Request $request, Closure $next): Response
    {
        $proxys = config('kernel.trusted_proxies');

        if (is_string($proxys) && trim($proxys) !== '') {
            $liste = $proxys === '*' ? ['*'] : array_values(array_filter(array_map('trim', explode(',', $proxys))));
            $remote = $request->server->get('REMOTE_ADDR');
            $liste = $liste === ['*'] ? [(string) $remote] : $liste;

            $request->setTrustedProxies(
                $liste,
                Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        return $next($request);
    }
}
