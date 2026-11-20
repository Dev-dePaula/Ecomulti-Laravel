<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venda extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'vendas';

    protected $guarded = [];

    protected $casts = [
        'cliente' => 'array',
        'itens' => 'array',
        'total' => 'float',
        'data' => 'datetime',
        'pago_em' => 'datetime',
        'vintei' => 'array',
        'vintei_dominios' => 'array',
    ];

    public function paraApi(): array
    {
        $extra = [];
        if ($this->vintei) {
            $extra['vintei'] = $this->vintei;
        }
        if ($this->vintei_dominios) {
            $extra['vinteiDominios'] = $this->vintei_dominios;
        }

        return $extra + [
            'id' => $this->id,
            'data' => $this->data?->toIso8601ZuluString(),
            'cliente' => $this->cliente,
            'clienteId' => $this->cliente_id,
            'itens' => $this->itens,
            'total' => $this->total,
            'metodo' => $this->metodo,
            'estado' => $this->estado,
            'pagoEm' => $this->pago_em?->toIso8601ZuluString() ?? '',
            'canal' => $this->canal,
            'notas' => (string) $this->notas,
            'criadoEm' => $this->created_at?->toIso8601ZuluString(),
            'actualizado' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
