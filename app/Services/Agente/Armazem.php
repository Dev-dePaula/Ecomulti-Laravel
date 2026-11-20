<?php

namespace App\Services\Agente;

use App\Models\Aviso;
use App\Models\Conversa;
use App\Models\PedidoAgente;
use App\Models\Venda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** Persistência do assistente: conversas, pedidos, avisos e contadores de uso (tudo na base de dados). */
class Armazem
{
    private const DIAS_CONVERSA = 7;

    public static function estadoNovo(): array
    {
        return ['mensagens' => [], 'orcamento' => [], 'pedido' => null, 'humano' => null, 'turnos' => 0, 'criado' => self::agoraMs()];
    }

    public static function agoraMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /* -------- conversas -------- */
    public static function lerConversa(string $id): array
    {
        $c = Conversa::find($id);
        if (! $c || self::agoraMs() - (int) $c->actualizado > self::DIAS_CONVERSA * 86_400_000) {
            return self::estadoNovo();
        }

        return $c->estado;
    }

    public static function gravarConversa(string $id, array $estado): void
    {
        $estado['actualizado'] = self::agoraMs();
        Conversa::updateOrCreate(['id' => $id], ['estado' => $estado, 'actualizado' => $estado['actualizado']]);
    }

    /* -------- pedidos (painel da equipa + vendas "em aberto" do dashboard) -------- */
    public static function guardarPedido(array $p): void
    {
        PedidoAgente::create([
            'numero' => $p['numero'],
            'canal' => $p['canal'],
            'sessao' => $p['sessao'] ?? null,
            'cliente' => $p['cliente'],
            'itens' => $p['itens'],
            'metodo' => $p['metodo'] ?? null,
            'totais' => $p['totais'],
            'texto' => $p['texto'],
        ]);

        // o mesmo pedido aparece no dashboard como venda "em aberto"
        $itens = array_map(fn ($i) => [
            'descricao' => mb_substr(($i['servicoLabel'] ?? $i['servico'] ?? 'Serviço').(! empty($i['plano']) ? ' — '.$i['plano'] : ''), 0, 200),
            'valor' => (float) ($i['total'] ?? 0),
        ], $p['itens']);
        $total = (float) ($p['totais']['total'] ?? array_sum(array_column($itens, 'valor')));
        if ($total > 0 && ! empty($p['cliente']['nome'])) {
            Venda::updateOrCreate(['id' => $p['numero']], [
                'data' => Carbon::now(),
                'cliente' => $p['cliente'],
                'cliente_id' => '',
                'itens' => $itens,
                'total' => round($total, 2),
                'metodo' => mb_substr((string) ($p['metodo'] ?? ''), 0, 40),
                'estado' => 'em_aberto',
                'canal' => $p['canal'],
            ]);
        }
    }

    public static function registarAviso(array $a): void
    {
        Aviso::create([
            'tipo' => $a['tipo'],
            'numero' => $a['numero'] ?? null,
            'motivo' => $a['motivo'] ?? null,
            'canal' => $a['canal'] ?? null,
            'contacto' => $a['contacto'] ?? null,
            'whatsapp' => $a['whatsapp'] ?? null,
            'texto' => $a['texto'],
        ]);
    }

    /* -------- limites de uso (protecção de custos) -------- */
    public static function contarUsoDiario(): int
    {
        $chave = 'agente:uso:'.Carbon::now()->format('Y-m-d');
        Cache::add($chave, 0, now()->addDays(2));

        return (int) Cache::increment($chave);
    }
}
