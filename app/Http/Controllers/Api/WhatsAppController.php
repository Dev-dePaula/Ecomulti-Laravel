<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessarMensagemWhatsApp;
use App\Services\WhatsApp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook do WhatsApp Business (Meta) — /api/whatsapp
 *  GET : verificação do webhook (hub.challenge)
 *  POST: valida a assinatura, responde 200 de imediato e entrega cada mensagem à fila
 *        (o agente pode demorar mais do que a Meta espera pela resposta).
 */
class WhatsAppController extends Controller
{
    public function verificar(Request $request): Response
    {
        $token = (string) config('ecomulti.whatsapp.verify_token');
        $ok = $request->query('hub_mode') === 'subscribe' && $token !== ''
            && hash_equals($token, (string) $request->query('hub_verify_token'));

        return $ok
            ? response((string) $request->query('hub_challenge', ''), 200)
            : response('proibido', 403);
    }

    public function receber(Request $request): Response
    {
        $bruto = $request->getContent();
        if (! WhatsApp::assinaturaValida($bruto, $request->header('x-hub-signature-256'))) {
            return response('assinatura inválida', 401);
        }

        // a Meta também envia estados (entregue/lida): só as mensagens recebidas interessam
        $dados = json_decode($bruto, true);
        foreach ($dados['entry'] ?? [] as $entrada) {
            foreach ($entrada['changes'] ?? [] as $alteracao) {
                $v = $alteracao['value'] ?? [];
                foreach ($v['messages'] ?? [] as $m) {
                    ProcessarMensagemWhatsApp::dispatch($m, $v['contacts'] ?? []);
                }
            }
        }

        return response('ok', 200);
    }
}
