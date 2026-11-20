<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * POST   /api/newsletter           — público: subscrever
 * GET    /api/newsletter           — painel: lista de subscritores
 * DELETE /api/newsletter?email=x   — painel: remover subscritor
 */
class NewsletterController extends Controller
{
    private const INTERESSES = ['Notícias da empresa', 'Domínios .ao', 'Hospedagem', 'Websites e lojas online', 'E-mail profissional', 'VPS', 'Automação com IA', 'Dicas e novidades digitais'];

    private const EMAIL = '/^[^\s@]{1,64}@[^\s@]{1,190}\.[^\s@]{2,}$/';

    /** Números angolanos: 9 dígitos a começar por 9 (aceita +244 / 00244) */
    private static function telefoneAO(?string $v): ?string
    {
        $n = preg_replace('/[\s().-]/', '', (string) $v);
        $n = preg_replace('/^(\+|00)?244/', '', $n);

        return preg_match('/^9\d{8}$/', $n) ? '+244'.$n : null;
    }

    public function subscrever(Request $request): JsonResponse
    {
        $d = $request->json()->all();
        if (! empty($d['website'])) {
            return response()->json(['ok' => true]);   // campo-isco (robôs): finge sucesso
        }
        $email = strtolower(PedidoController::txt($d['email'] ?? '', 254));
        if (! preg_match(self::EMAIL, $email)) {
            return response()->json(['erro' => 'Indique um e-mail válido.'], 400);
        }
        if (($d['consentimento'] ?? null) !== true) {
            return response()->json(['erro' => 'É preciso aceitar a política de privacidade.'], 400);
        }
        $whatsapp = '';
        if (PedidoController::txt($d['whatsapp'] ?? '', 30) !== '') {
            $whatsapp = self::telefoneAO($d['whatsapp']);
            if (! $whatsapp) {
                return response()->json(['erro' => 'O WhatsApp deve ter 9 dígitos, por exemplo 923 000 000.'], 400);
            }
        }

        // limite por dia e por IP (guardado só como hash)
        $chave = 'newsletter:ip:'.substr(hash('sha256', $request->ip().Carbon::now()->format('Y-m-d')), 0, 24);
        Cache::add($chave, 0, now()->addDay());
        if (Cache::increment($chave) > config('ecomulti.limite_newsletter_ip_dia')) {
            return response()->json(['erro' => 'Demasiados pedidos. Tente novamente amanhã.'], 429);
        }

        $antigo = Lead::where('email', $email)->first();
        $interesses = array_values(array_filter(
            array_map(fn ($i) => PedidoController::txt($i, 40), is_array($d['interesses'] ?? null) ? $d['interesses'] : []),
            fn ($i) => in_array($i, self::INTERESSES, true)
        ));
        $agora = Carbon::now();

        Lead::updateOrCreate(['email' => $email], [
            'nome' => PedidoController::txt($d['nome'] ?? '', 80) ?: ($antigo?->nome ?? ''),
            'whatsapp' => $whatsapp ?: ($antigo?->whatsapp ?? ''),
            'interesses' => array_values(array_unique([...($antigo?->interesses ?? []), ...$interesses])),
            'origem' => $antigo?->origem ?? (PedidoController::txt($d['origem'] ?? '', 30) ?: 'site'),   // fica a primeira origem
            'pagina' => $antigo?->pagina ?? PedidoController::txt($d['pagina'] ?? '', 120),
            'consentimento' => true,
            'consentido_em' => $antigo?->consentido_em ?? $agora,
        ]);

        return response()->json(['ok' => true, 'jaExistia' => (bool) $antigo]);
    }

    public function listar(): JsonResponse
    {
        return response()->json(['subscritores' => Lead::orderByDesc('created_at')->get()->map->paraApi()->values()]);
    }

    public function remover(Request $request): JsonResponse
    {
        $email = strtolower(PedidoController::txt($request->query('email'), 254));
        if (! preg_match(self::EMAIL, $email)) {
            return response()->json(['erro' => 'e-mail inválido'], 400);
        }
        Lead::where('email', $email)->delete();

        return response()->json(['ok' => true]);
    }
}
