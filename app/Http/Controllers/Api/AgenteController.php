<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Agente\Armazem;
use App\Services\Agente\Nucleo;
use App\Services\WhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET  /api/agente — diz ao widget se o modo IA está activo
 * POST /api/agente — chat do site (resposta em NDJSON: {t:"txt"}, {t:"accao"}, {t:"fim"}, {t:"erro"})
 */
class AgenteController extends Controller
{
    private const PAGINAS = [
        '/' => 'Início', '/index.html' => 'Início', '/dominios.html' => 'Domínios', '/hospedagem-ecomulti.html' => 'Hospedagem',
        '/email-corporativo-ecomulti.html' => 'E-mail profissional', '/desenvolvimento-ecomulti.html' => 'Websites', '/orcamento-ecomulti.html' => 'Orçamento',
    ];

    public function estado(): JsonResponse
    {
        return response()->json(['ativo' => Nucleo::activo()], 200, ['Cache-Control' => 'no-store']);
    }

    public function conversar(Request $request): JsonResponse|StreamedResponse
    {
        $sessao = (string) $request->input('sessao', '');
        $mensagem = trim((string) $request->input('mensagem', ''));
        if (! preg_match('/^[a-z0-9-]{16,64}$/i', $sessao) || $mensagem === '' || mb_strlen($mensagem) > 1200) {
            return response()->json(['erro' => 'pedido inválido'], 400);
        }
        if (! Nucleo::activo()) {
            return response()->json(['modo' => 'guiado'], 503);
        }

        $id = 'site:'.$sessao;
        $estado = Armazem::lerConversa($id);
        if ($estado['turnos'] >= config('ecomulti.agente.limite_conversa')) {
            return response()->json(['modo' => 'humano', 'erro' => 'limite da conversa'], 429);
        }
        if (Armazem::contarUsoDiario() > config('ecomulti.agente.limite_diario')) {
            return response()->json(['modo' => 'guiado', 'erro' => 'limite diário'], 429);
        }

        $texto = $mensagem;
        $pagina = self::PAGINAS[(string) $request->input('pagina', '')] ?? null;
        if ($estado['turnos'] === 0 && $pagina) {
            $texto = "[Contexto: o cliente abriu o chat na página \"$pagina\" do site]\n$mensagem";
        }

        return response()->stream(function () use ($texto, $estado, $id) {
            $enviar = function (array $o) {
                echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };
            $ctx = [
                'estado' => $estado, 'canal' => 'site', 'sessao' => $id,
                'aoTexto' => fn ($d) => $enviar(['t' => 'txt', 'd' => $d."\n\n"]),
                'aoAccao' => fn ($a) => $enviar(['t' => 'accao'] + $a),
                'guardarPedido' => fn ($p) => Armazem::guardarPedido($p),
                'avisarEquipa' => fn ($a) => WhatsApp::avisarEquipa($a),
            ];
            try {
                Nucleo::executarTurno($texto, $ctx);
                Armazem::gravarConversa($id, $ctx['estado']);
                $enviar(['t' => 'fim']);
            } catch (\Throwable $e) {
                report($e);
                $enviar(['t' => 'erro']);
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
