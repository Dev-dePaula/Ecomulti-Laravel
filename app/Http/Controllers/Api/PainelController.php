<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Aviso;
use App\Models\PedidoAgente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/painel — pedidos e avisos registados pelo assistente (para painel.html).
 * Protegido pela PAINEL_CHAVE (Authorization: Bearer ...).
 */
class PainelController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $chave = (string) config('ecomulti.admin.painel_chave');
        if (strlen($chave) < 12) {
            return response()->json(['erro' => 'PAINEL_CHAVE não configurada (mín. 12 caracteres)'], 503);
        }
        if (! hash_equals($chave, (string) $request->bearerToken())) {
            usleep(800_000);   // trava tentativas em série

            return response()->json(['erro' => 'chave inválida'], 401);
        }

        return response()->json([
            'pedidos' => PedidoAgente::orderByDesc('id')->limit(100)->get()->map->paraApi()->values(),
            'avisos' => Aviso::orderByDesc('id')->limit(100)->get()->map->paraApi()->values(),
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
