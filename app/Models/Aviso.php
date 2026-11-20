<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Aviso à equipa: pedido novo ou cliente a pedir atendimento humano. */
class Aviso extends Model
{
    protected $table = 'avisos';

    protected $guarded = [];

    public function paraApi(): array
    {
        return [
            'tipo' => $this->tipo,
            'numero' => $this->numero,
            'motivo' => $this->motivo,
            'canal' => $this->canal,
            'contacto' => $this->contacto,
            'whatsapp' => $this->whatsapp,
            'texto' => $this->texto,
            'data' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
