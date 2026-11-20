<?php

namespace App\Services;

use App\Services\Agente\Armazem;
use App\Services\Agente\Catalogo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** WhatsApp Business Cloud API (Meta): enviar mensagens, validar webhooks e avisar a equipa. */
class WhatsApp
{
    public static function configurado(): bool
    {
        return config('ecomulti.whatsapp.token') && config('ecomulti.whatsapp.phone_number_id');
    }

    private static function graph(array $corpo): array
    {
        $c = config('ecomulti.whatsapp');
        $r = Http::withToken((string) $c['token'])->timeout(15)
            ->post("{$c['graph_url']}/{$c['versao']}/{$c['phone_number_id']}/messages", ['messaging_product' => 'whatsapp'] + $corpo);
        if (! $r->successful()) {
            throw new \RuntimeException('WhatsApp '.$r->status().': '.($r->json('error.message') ?? 'erro'));
        }

        return $r->json() ?? [];
    }

    /** Markdown do modelo → formatação do WhatsApp */
    public static function paraWhatsApp(string $t): string
    {
        $t = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $t);
        $t = preg_replace('/^#{1,6}\s+/m', '', $t);

        return preg_replace('/\[([^\]]+)\]\((https?:[^)]+)\)/', '$1: $2', $t);
    }

    public static function enviarTexto(string $para, string $texto): void
    {
        $corpo = trim(self::paraWhatsApp($texto));
        // limite do WhatsApp: 4096 caracteres por mensagem
        foreach (mb_str_split($corpo, 4000) as $parte) {
            self::graph(['to' => $para, 'type' => 'text', 'text' => ['body' => $parte, 'preview_url' => true]]);
        }
    }

    public static function marcarLida(string $id): void
    {
        try {
            self::graph(['status' => 'read', 'message_id' => $id]);
        } catch (\Throwable) {
        }
    }

    /** Assinatura X-Hub-Signature-256 (HMAC SHA-256 do corpo com o App Secret) */
    public static function assinaturaValida(string $corpoBruto, ?string $cabecalho): bool
    {
        $segredo = (string) config('ecomulti.whatsapp.app_secret');
        if ($segredo === '' || ! $cabecalho || ! str_starts_with($cabecalho, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $corpoBruto, $segredo), substr($cabecalho, 7));
    }

    /**
     * Avisar a equipa (pedido novo / pedido de atendimento humano). Fica sempre registado no
     * painel; se o WhatsApp estiver configurado, também envia mensagem para o número da equipa.
     * Fora da janela de 24 h só são aceites modelos aprovados: WHATSAPP_MODELO_AVISO é o recurso.
     */
    public static function avisarEquipa(array $aviso): void
    {
        Armazem::registarAviso($aviso);
        if (! self::configurado()) {
            return;
        }
        $c = config('ecomulti.whatsapp');
        $equipa = $c['equipa'] ?: Catalogo::empresa()['whatsapp'];
        $titulo = $aviso['tipo'] === 'pedido' ? "🛒 Novo pedido {$aviso['numero']}" : "🙋 Cliente pede atendimento humano ({$aviso['motivo']})";
        $contacto = (! empty($aviso['whatsapp']) ? "\nWhatsApp do cliente: wa.me/".preg_replace('/\D/', '', (string) $aviso['whatsapp']) : '')
            .(! empty($aviso['contacto']) ? "\nTelefone indicado: {$aviso['contacto']}" : '');
        $texto = "$titulo\nCanal: {$aviso['canal']}$contacto\n\n{$aviso['texto']}";

        try {
            self::enviarTexto($equipa, $texto);
        } catch (\Throwable $e) {
            if (! $c['modelo_aviso']) {
                Log::error('[avisarEquipa] '.$e->getMessage());

                return;
            }
            try {
                self::graph([
                    'to' => $equipa, 'type' => 'template',
                    'template' => ['name' => $c['modelo_aviso'], 'language' => ['code' => $c['modelo_idioma']],
                        'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr(str_replace("\n", ' · ', $titulo.$contacto), 0, 900)]]]]],
                ]);
            } catch (\Throwable $e2) {
                Log::error('[avisarEquipa modelo] '.$e2->getMessage());
            }
        }
    }
}
