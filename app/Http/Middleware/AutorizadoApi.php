<?php

namespace App\Http\Middleware;

use App\Services\AdminAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige sessão válida do dashboard (login) ou a chave PAINEL_CHAVE
 * (cabeçalho Authorization: Bearer ...).
 */
class AutorizadoApi
{
    public static function permitido(Request $request): bool
    {
        $dada = (string) $request->bearerToken();
        if ($dada === '') {
            return false;
        }
        $chave = (string) config('ecomulti.admin.painel_chave');

        return AdminAuth::validarToken($dada) !== null
            || (strlen($chave) >= 12 && hash_equals($chave, $dada));
    }

    public static function negar(): Response
    {
        usleep(600_000);   // trava tentativas em série

        return response()->json(['erro' => 'Sessão expirada ou sem permissão. Entre novamente.'], 401, ['Cache-Control' => 'no-store']);
    }

    public function handle(Request $request, Closure $next): Response
    {
        return self::permitido($request) ? $next($request) : self::negar();
    }
}
