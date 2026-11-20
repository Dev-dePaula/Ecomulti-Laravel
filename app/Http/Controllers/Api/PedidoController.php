<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\MensagemPedido;
use App\Models\Venda;
use App\Services\Agente\Catalogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * POST /api/pedido — o site regista aqui os pedidos de orçamento (além de os enviar por
 * WhatsApp/e-mail), para aparecerem no dashboard como vendas "em aberto". Público, com limites e validação.
 */
class PedidoController extends Controller
{
    private const EMAIL = '/^[^\s@]{1,64}@[^\s@]{1,190}\.[^\s@]{2,}$/';

    public static function txt(mixed $v, int $max): string
    {
        return mb_substr(trim(str_replace("\0", '', (string) ($v ?? ''))), 0, $max);
    }

    public static function num(mixed $v): float
    {
        $n = is_numeric($v) ? (float) $v : 0.0;

        return $n >= 0 ? min(round($n, 2), 1e11) : 0.0;
    }

    public function registar(Request $request): JsonResponse
    {
        $d = $request->json()->all();
        $c = is_array($d['cliente'] ?? null) ? $d['cliente'] : [];

        $nome = self::txt($c['nome'] ?? '', 120);
        $email = strtolower(self::txt($c['email'] ?? '', 254));
        $telefone = self::txt($c['telefone'] ?? $c['tel'] ?? '', 30);

        $itens = [];
        foreach (array_slice(is_array($d['itens'] ?? null) ? $d['itens'] : [], 0, 30) as $i) {
            if (! is_array($i)) {
                continue;
            }
            $descricao = self::txt($i['descricao'] ?? '', 200);
            if ($descricao === '') {
                continue;
            }
            $itens[] = [
                'descricao' => $descricao,
                'valor' => self::num($i['valor'] ?? 0),
                'detalhes' => array_map(fn ($x) => self::txt($x, 120), array_slice(is_array($i['detalhes'] ?? null) ? $i['detalhes'] : [], 0, 6)),
            ];
        }
        $total = self::num($d['total'] ?? 0) ?: array_sum(array_column($itens, 'valor'));

        if (mb_strlen($nome) < 2 || ! $itens || ! $total) {
            return response()->json(['erro' => 'pedido inválido'], 400);
        }
        if (! preg_match(self::EMAIL, $email) && strlen(preg_replace('/\D/', '', $telefone)) < 9) {
            return response()->json(['erro' => 'contacto inválido'], 400);
        }

        // limite por dia e por IP (guardado só como hash)
        $chaveLimite = 'pedido:ip:'.substr(hash('sha256', $request->ip().Carbon::now()->format('Y-m-d')), 0, 24);
        Cache::add($chaveLimite, 0, now()->addDay());
        if (Cache::increment($chaveLimite) > config('ecomulti.limite_pedidos_ip_dia')) {
            return response()->json(['erro' => 'limite diário'], 429);
        }

        $id = preg_match('/^ECO-\d{6}-\d{4}$/', (string) ($d['numero'] ?? '')) ? $d['numero'] : Catalogo::numeroPedido();
        $existente = Venda::find($id);
        if ($existente && $existente->estado !== 'em_aberto') {
            return response()->json(['ok' => true, 'numero' => $id]);   // nunca altera vendas já tratadas
        }

        $emailValido = (bool) preg_match(self::EMAIL, $email);
        $venda = Venda::updateOrCreate(['id' => $id], [
            'data' => $existente?->data ?? Carbon::now(),
            'cliente' => [
                'nome' => $nome, 'tipo' => ($c['tipo'] ?? '') === 'Empresa' ? 'Empresa' : 'Particular', 'telefone' => $telefone,
                'email' => $emailValido ? $email : '', 'nif' => strtoupper(self::txt($c['nif'] ?? '', 20)), 'morada' => self::txt($c['morada'] ?? '', 200),
            ],
            'cliente_id' => $existente?->cliente_id ?? '',
            'itens' => $itens, 'total' => $total, 'metodo' => self::txt($d['metodo'] ?? '', 30),
            'estado' => 'em_aberto', 'pago_em' => null, 'canal' => 'orcamento-site', 'notas' => self::txt($d['notas'] ?? '', 600),
        ]);

        // e-mail de reserva (compra pendente, a aguardar confirmação do pagamento) — só na 1.ª vez
        $emailEnviado = false;
        if (! $existente && $emailValido) {
            try {
                Mail::to($email)->bcc(config('ecomulti.email_equipa'))->send(new MensagemPedido($venda, 'reserva'));
                $emailEnviado = true;
            } catch (\Throwable $e) {
                Log::warning('[pedido] e-mail de reserva não enviado: '.$e->getMessage());
            }
        }

        return response()->json(['ok' => true, 'numero' => $id, 'emailEnviado' => $emailEnviado]);
    }
}
