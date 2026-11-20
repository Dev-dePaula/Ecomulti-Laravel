<?php

namespace App\Jobs;

use App\Services\Agente\Armazem;
use App\Services\Agente\Catalogo;
use App\Services\Agente\Nucleo;
use App\Services\WhatsApp;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Responde a uma mensagem recebida no WhatsApp com o assistente. */
class ProcessarMensagemWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public array $mensagem, public array $contactos = []) {}

    public function handle(): void
    {
        try {
            $this->tratar();
        } catch (\Throwable $e) {
            Log::error('[whatsapp] '.$e->getMessage());
        }
    }

    private function tratar(): void
    {
        $m = $this->mensagem;
        $empresa = Catalogo::empresa();
        $site = config('ecomulti.site_url');
        $humano = 'wa.me/'.$empresa['whatsapp'];
        $pausaH = (int) config('ecomulti.whatsapp.pausa_humano_horas');

        // a Meta pode reenviar a mesma mensagem
        if (! Cache::add('wa:visto:'.($m['id'] ?? ''), 1, now()->addDays(2))) {
            return;
        }
        if (time() - (int) ($m['timestamp'] ?? 0) > 3600) {
            return;   // ignora mensagens muito antigas
        }
        $de = (string) ($m['from'] ?? '');
        if ($de === '') {
            return;
        }
        WhatsApp::marcarLida((string) $m['id']);

        if (($m['type'] ?? '') !== 'text' || empty($m['text']['body'])) {
            WhatsApp::enviarTexto($de, 'Por agora só consigo ler mensagens de texto 🙂 Pode escrever a sua pergunta, por favor?');

            return;
        }
        if (! Nucleo::activo()) {
            WhatsApp::enviarTexto($de, "Olá! 👋 Obrigado por contactar a *{$empresa['nome']}*.\n\n"
                ."Pode ver preços e montar o seu orçamento no site: $site\n"
                ."• Domínios: $site/dominios.html\n• Hospedagem: $site/hospedagem-ecomulti.html\n"
                ."• E-mail profissional: $site/email-corporativo-ecomulti.html\n• Websites: $site/desenvolvimento-ecomulti.html\n\n"
                ."Para falar com a nossa equipa: $humano");

            return;
        }

        $id = 'wa:'.$de;
        $texto = mb_substr((string) $m['text']['body'], 0, 1500);
        // uma conversa de cada vez por cliente
        $trinco = Cache::lock('wa:trinco:'.$id, 300);
        try {
            $trinco->block(90);
        } catch (LockTimeoutException) {
            // segue mesmo assim, para não perder a mensagem
        }

        try {
            $estado = Armazem::lerConversa($id);
            if (! empty($estado['humano']) && $pausaH > 0) {
                if (Armazem::agoraMs() - $estado['humano']['desde'] < $pausaH * 3_600_000) {
                    return;   // a equipa está a atender
                }
                $estado['humano'] = null;
            }
            if ($estado['turnos'] >= config('ecomulti.agente.limite_conversa_wa') || Armazem::contarUsoDiario() > config('ecomulti.agente.limite_diario')) {
                WhatsApp::enviarTexto($de, "Obrigado pela mensagem! Para continuar, fale directamente com a nossa equipa: $humano");

                return;
            }

            $nome = null;
            foreach ($this->contactos as $c) {
                if (($c['wa_id'] ?? null) === $de) {
                    $nome = $c['profile']['name'] ?? null;
                }
            }
            $prefixo = $estado['turnos'] === 0 ? '[Contexto: primeiro contacto pelo WhatsApp. Nome no perfil: '.($nome ?: 'desconhecido').". Telefone: +$de]\n" : '';

            $ctx = [
                'estado' => $estado, 'canal' => 'whatsapp', 'sessao' => $id, 'contacto' => $de, 'pausaHumano' => $pausaH > 0,
                'guardarPedido' => fn ($p) => Armazem::guardarPedido($p),
                'avisarEquipa' => fn ($a) => WhatsApp::avisarEquipa($a),
            ];
            $resposta = Nucleo::executarTurno($prefixo.$texto, $ctx);
            Armazem::gravarConversa($id, $ctx['estado']);
            WhatsApp::enviarTexto($de, $resposta ?: 'Desculpe, não percebi bem. Pode explicar de outra forma?');
        } catch (\Throwable $e) {
            Log::error('[whatsapp] falha no agente: '.$e->getMessage());
            try {
                WhatsApp::enviarTexto($de, "Desculpe, tive um problema técnico. A nossa equipa vai responder-lhe em breve — ou fale já connosco: $humano");
                WhatsApp::avisarEquipa(['tipo' => 'humano', 'motivo' => 'erro_tecnico', 'texto' => 'O assistente falhou ao responder a: "'.mb_substr($texto, 0, 300).'"', 'canal' => 'whatsapp', 'whatsapp' => $de]);
            } catch (\Throwable) {
            }
        } finally {
            $trinco->release();
        }
    }
}
