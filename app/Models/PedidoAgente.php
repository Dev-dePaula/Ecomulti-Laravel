<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pedido registado pelo assistente (site ou WhatsApp). */
class PedidoAgente extends Model
{
    protected $table = 'pedidos_agente';

    protected $guarded = [];

    protected $casts = ['cliente' => 'array', 'itens' => 'array', 'totais' => 'array'];

    public function paraApi(): array
    {
        return [
            'numero' => $this->numero,
            'data' => $this->created_at?->toIso8601ZuluString(),
            'canal' => $this->canal,
            'sessao' => $this->sessao,
            'cliente' => $this->cliente,
            'itens' => $this->itens,
            'metodo' => $this->metodo,
            'totais' => $this->totais,
            'texto' => $this->texto,
        ];
    }
}
