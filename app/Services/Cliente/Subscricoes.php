<?php

namespace App\Services\Cliente;

use App\Exceptions\ErroApi;
use App\Models\Subscricao;
use App\Models\Venda;
use Illuminate\Support\Carbon;

/**
 * Subscrições dos clientes (domínios, hospedagem, e-mail…), criadas a partir das vendas pagas.
 *  - Cada serviço de uma venda paga passa a ser uma subscrição (id = <venda>-<n.º do serviço>).
 *  - Serviços recorrentes (domínio, hospedagem/alojamento, e-mail, VPS) têm data de renovação
 *    (1 ano por omissão, ou "2 anos"/"3 anos" se estiver na descrição); os restantes ficam como pagamento único.
 *  - A equipa pode corrigir no painel; depois disso a sincronização já não altera essa subscrição.
 *  - O cliente só vê as subscrições do seu e-mail, de vendas pagas e não canceladas.
 */
class Subscricoes
{
    private const DOMINIO_RE = '/\b((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:co\.ao|it\.ao|edu\.ao|org\.ao|ao|com|net|org|pt))\b/i';

    private const RECORRENTE_RE = '/dom\S{0,2}nio|domain|hosped|aloj|e-?mail|correio|vps|servidor|manuten/iu';

    public static function somarAnos(string $iso, int $anos): string
    {
        return Carbon::parse($iso)->utc()->addYearsNoOverflow($anos)->format('Y-m-d');
    }

    private static function nova(Venda $venda, array $item, int $i): array
    {
        $desc = (string) ($item['descricao'] ?? '');
        $recorrente = (bool) preg_match(self::RECORRENTE_RE, $desc);
        preg_match('/(\d+)\s*anos?\b/i', $desc, $a);
        $anos = min(max((int) ($a[1] ?? 1), 1), 10);
        preg_match(self::DOMINIO_RE, $desc, $d);
        $dominio = strtolower($d[1] ?? (preg_match('/hosped|aloj/i', $desc) ? ($venda->vintei['dominio'] ?? '') : ''));
        $inicio = ($venda->pago_em ?? $venda->data ?? now())->copy()->utc()->format('Y-m-d');

        return [
            'id' => $venda->id.'-'.($i + 1), 'venda_id' => $venda->id,
            'email' => strtolower((string) ($venda->cliente['email'] ?? '')), 'cliente_nome' => (string) ($venda->cliente['nome'] ?? ''),
            'servico' => mb_substr($desc, 0, 200), 'dominio' => $dominio, 'tipo' => $recorrente ? 'recorrente' : 'unico',
            'inicio' => $inicio, 'renovacao' => $recorrente ? self::somarAnos($inicio, $anos) : '',
            'valor' => (float) ($item['valor'] ?? 0), 'estado' => 'activa', 'editada' => false,
        ];
    }

    /** cria (ou actualiza, se nunca foi editada) as subscrições de uma venda paga */
    public static function criarDaVenda(?Venda $venda): void
    {
        if (! $venda || $venda->estado !== 'pago') {
            return;
        }
        foreach (array_values($venda->itens ?? []) as $i => $item) {
            $nova = self::nova($venda, $item, $i);
            $antiga = Subscricao::find($nova['id']);
            if ($antiga?->editada) {
                continue;
            }
            if ($antiga) {   // mantém datas e estado; só acompanha o cliente e o serviço da venda
                $antiga->fill([
                    'email' => $nova['email'], 'cliente_nome' => $nova['cliente_nome'], 'servico' => $nova['servico'],
                    'dominio' => $antiga->dominio ?: $nova['dominio'], 'valor' => $nova['valor'],
                ])->save();

                continue;
            }
            Subscricao::create($nova);
        }
    }

    /** garante que todas as vendas pagas têm subscrições (inclui as vendas antigas) */
    public static function sincronizar(): void
    {
        foreach (Venda::where('estado', 'pago')->get() as $v) {
            self::criarDaVenda($v);
        }
    }

    public static function situacao(Subscricao $s): array
    {
        if ($s->estado === 'cancelada') {
            return ['situacao' => 'cancelada', 'dias' => null];
        }
        if (! $s->renovacao) {
            return ['situacao' => 'unico', 'dias' => null];
        }
        $dias = (int) ceil((Carbon::parse($s->renovacao.' 23:59:59', 'UTC')->timestamp - time()) / 86400);

        return ['situacao' => $dias < 0 ? 'expirada' : ($dias <= 30 ? 'a_expirar' : 'activa'), 'dias' => $dias];
    }

    private static function ordenar($lista): array
    {
        return $lista->sortBy(fn ($s) => $s['renovacao'] ?: '9999')->values()->all();
    }

    private static function paraAdmin(Subscricao $s, string $vendaEstado = ''): array
    {
        return [
            'id' => $s->id, 'vendaId' => $s->venda_id, 'email' => $s->email, 'clienteNome' => $s->cliente_nome,
            'servico' => $s->servico, 'dominio' => $s->dominio, 'tipo' => $s->tipo, 'inicio' => $s->inicio,
            'renovacao' => $s->renovacao, 'valor' => $s->valor, 'estado' => $s->estado, 'editada' => $s->editada,
            'vendaEstado' => $vendaEstado,
        ] + self::situacao($s);
    }

    /** painel admin: todas, com o estado da venda */
    public static function listarTodas(): array
    {
        self::sincronizar();
        $estados = Venda::pluck('estado', 'id');

        return self::ordenar(Subscricao::all()->map(fn (Subscricao $s) => self::paraAdmin($s, $estados[$s->venda_id] ?? 'apagada')));
    }

    /** área do cliente: só as do e-mail, de vendas pagas e não canceladas */
    public static function listarDoCliente(string $email): array
    {
        $email = strtolower($email);
        if ($email === '') {
            return [];
        }
        self::sincronizar();
        $pagas = Venda::where('estado', 'pago')->pluck('id')->all();

        return self::ordenar(Subscricao::where('email', $email)->where('estado', '!=', 'cancelada')->get()
            ->filter(fn ($s) => in_array($s->venda_id, $pagas, true))
            ->map(fn (Subscricao $s) => [
                'id' => $s->id, 'servico' => $s->servico, 'dominio' => $s->dominio, 'tipo' => $s->tipo,
                'inicio' => $s->inicio, 'renovacao' => $s->renovacao,
            ] + self::situacao($s)));
    }

    /** domínio registado na 20i → a subscrição desse domínio passa a renovar na data do registo + anos */
    public static function dominioRegistado(Venda $venda, string $dominio, int $anos): void
    {
        self::criarDaVenda($venda);
        $iso = now()->utc()->toIso8601String();
        foreach (Subscricao::where('venda_id', $venda->id)->where('dominio', $dominio)->get() as $s) {
            if ($s->editada || ! preg_match('/dom\S{0,2}nio|domain/iu', $s->servico)) {
                continue;
            }
            $s->update(['tipo' => 'recorrente', 'inicio' => substr($iso, 0, 10), 'renovacao' => self::somarAnos($iso, $anos)]);
        }
    }

    /** painel admin: corrigir uma subscrição */
    public static function guardar(array $d): array
    {
        $s = Subscricao::find((string) ($d['id'] ?? '')) ?? throw new ErroApi('Subscrição não encontrada.', 404);
        $txt = fn ($v, int $max) => mb_substr(trim((string) ($v ?? '')), 0, $max);
        $email = strtolower($txt($d['email'] ?? '', 254));
        if ($email !== '' && ! preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $email)) {
            throw new ErroApi('E-mail inválido.');
        }
        foreach (['inicio', 'renovacao'] as $k) {
            if (! empty($d[$k]) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d[$k])) {
                throw new ErroApi('Data inválida.');
            }
        }
        $unico = ($d['tipo'] ?? '') === 'unico';
        $s->update([
            'servico' => $txt($d['servico'] ?? '', 200) ?: $s->servico, 'dominio' => strtolower($txt($d['dominio'] ?? '', 253)),
            'email' => $email ?: $s->email, 'inicio' => ($d['inicio'] ?? '') ?: $s->inicio,
            'renovacao' => $unico ? '' : (($d['renovacao'] ?? '') ?: $s->renovacao),
            'tipo' => $unico ? 'unico' : 'recorrente', 'estado' => ($d['estado'] ?? '') === 'cancelada' ? 'cancelada' : 'activa',
            'editada' => true,
        ]);

        return self::paraAdmin($s, (string) Venda::where('id', $s->venda_id)->value('estado'));
    }
}
