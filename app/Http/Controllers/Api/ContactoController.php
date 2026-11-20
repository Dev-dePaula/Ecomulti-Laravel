<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Email;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * POST /api/contacto — os formulários do site ("Enviar por e-mail") e o pedido de orçamento enviam a mensagem
 * directamente para a caixa da ECOMULTI. Destino: CONTACTO_EMAIL (por omissão suporte@ecomulti.ao).
 * Responder ao e-mail responde ao cliente (Reply-To). Público: validação, campo-armadilha e limite por IP.
 */
class ContactoController extends Controller
{
    private const EMAIL = '/^[^\s@,;<>"]{1,64}@[^\s@,;<>"]{1,190}\.[^\s@,;<>"]{2,}$/';

    private static function txt(mixed $v, int $max): string
    {
        return mb_substr(trim(str_replace(["\0", "\r\n", "\r"], ['', "\n", "\n"], (string) ($v ?? ''))), 0, $max);
    }

    private static function umaLinha(mixed $v, int $max): string
    {
        return preg_replace('/\s+/', ' ', self::txt($v, $max));
    }

    public function __invoke(Request $request): JsonResponse
    {
        $d = $request->json()->all();

        // campo escondido que só os robôs preenchem: finge que correu bem e não envia
        if (self::txt($d['site'] ?? '', 200) !== '') {
            return response()->json(['ok' => true]);
        }

        $nome = self::umaLinha($d['nome'] ?? '', 120);
        $contacto = self::umaLinha($d['contacto'] ?? '', 254);
        $assunto = self::umaLinha($d['assunto'] ?? '', 150) ?: 'Pedido pelo site';
        $texto = self::txt($d['texto'] ?? '', 8000);
        $pagina = self::umaLinha($d['pagina'] ?? '', 120);
        if (mb_strlen($nome) < 2 || $texto === '') {
            return response()->json(['erro' => 'Indique o seu nome e a mensagem.'], 400);
        }
        $email = preg_match(self::EMAIL, $contacto) ? strtolower($contacto) : '';
        if ($email === '' && strlen(preg_replace('/\D/', '', $contacto)) < 9) {
            return response()->json(['erro' => 'Indique um e-mail ou telefone válido.'], 400);
        }
        if (! Email::disponivel()) {
            return response()->json(['erro' => 'envio de e-mail indisponível'], 503);
        }

        $chave = 'contacto:ip:'.substr(hash('sha256', $request->ip().date('YmdH')), 0, 24);
        Cache::add($chave, 0, 3700);
        if ((int) Cache::get($chave) >= (int) config('ecomulti.contacto.limite_ip_hora')) {
            return response()->json(['erro' => 'Demasiados envios. Tente novamente mais tarde ou fale connosco pelo WhatsApp.'], 429);
        }
        Cache::increment($chave);

        $e = fn ($s) => Email::esc((string) $s);
        $rodape = "\n\n---\nNome: {$nome}\nContacto: {$contacto}".($pagina ? "\nPágina: {$pagina}" : '')."\nEnviado pelo site em ".now()->toIso8601String();
        $html = '<div style="font-family:Arial,sans-serif;color:#2b3555;max-width:640px">'
            .'<h2 style="color:#0a1747;font-size:18px">'.$e($assunto).'</h2>'
            .'<pre style="font-family:inherit;white-space:pre-wrap;font-size:14px;line-height:1.5">'.$e($texto).'</pre>'
            .'<hr style="border:0;border-top:1px solid #e2e7f1"><p style="font-size:13px;color:#5f6a8a">'
            .'<strong>Nome:</strong> '.$e($nome).'<br><strong>Contacto:</strong> '.$e($contacto).($pagina ? '<br><strong>Página:</strong> '.$e($pagina) : '').'<br>'
            .($email ? 'Responda a este e-mail para escrever directamente ao cliente.' : 'O cliente deixou só o telefone.').'</p></div>';
        try {
            Email::enviar((string) config('ecomulti.contacto.destino'), "[Site] {$assunto}", $texto.$rodape, $html,
                $email ? $email : null);
        } catch (\Throwable $ex) {
            report($ex);

            return response()->json(['erro' => 'Não foi possível enviar agora.'], 502);
        }

        return response()->json(['ok' => true]);
    }
}
