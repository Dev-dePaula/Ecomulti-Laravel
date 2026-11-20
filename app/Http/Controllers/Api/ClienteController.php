<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErroApi;
use App\Http\Controllers\Controller;
use App\Services\Cliente\ClienteAuth;
use App\Services\Cliente\Subscricoes;
use App\Services\Email;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/cliente   corpo: { acao: "...", ...dados }
 * Públicas: registar, confirmar, reenviar, entrar, recuperar, redefinir.
 * Com sessão (Authorization: Bearer <token>): sessao, subscricoes.
 */
class ClienteController extends Controller
{
    private const PUBLICAS = ['registar', 'confirmar', 'reenviar', 'entrar', 'recuperar', 'redefinir'];

    private const PRECISAM_EMAIL = ['registar', 'reenviar', 'recuperar'];   // entrar funciona sempre (contas já confirmadas)

    public function __invoke(Request $request): JsonResponse
    {
        $d = $request->json()->all();
        $acao = (string) ($d['acao'] ?? '');
        $cab = ['Cache-Control' => 'no-store'];

        try {
            if (in_array($acao, self::PUBLICAS, true)) {
                if (in_array($acao, self::PRECISAM_EMAIL, true) && ! Email::disponivel()) {
                    return response()->json(['erro' => 'A área de cliente está temporariamente indisponível. Contacte-nos por WhatsApp.'], 503, $cab);
                }

                return response()->json(ClienteAuth::$acao($d, (string) $request->ip()), 200, $cab);
            }
            $conta = ClienteAuth::validarSessao((string) $request->bearerToken());
            if (! $conta) {
                return response()->json(['erro' => 'A sessão terminou. Entre novamente.'], 401, $cab);
            }

            return match ($acao) {
                'sessao' => response()->json(['ok' => true, 'cliente' => ClienteAuth::publico($conta)], 200, $cab),
                'subscricoes' => response()->json(['ok' => true, 'subscricoes' => Subscricoes::listarDoCliente($conta->email)], 200, $cab),
                default => response()->json(['erro' => 'acção desconhecida'], 400, $cab),
            };
        } catch (ErroApi $e) {
            return response()->json(['erro' => $e->getMessage()], $e->estado, $cab);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['erro' => 'Não foi possível concluir o pedido. Tente novamente dentro de momentos.'], 500, $cab);
        }
    }
}
