<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Subscritor da newsletter / lead. */
class Lead extends Model
{
    protected $table = 'leads';

    protected $guarded = [];

    protected $casts = ['interesses' => 'array', 'consentimento' => 'boolean', 'consentido_em' => 'datetime'];

    public function paraApi(): array
    {
        return [
            'email' => $this->email,
            'nome' => $this->nome,
            'whatsapp' => $this->whatsapp,
            'interesses' => $this->interesses ?? [],
            'origem' => $this->origem,
            'pagina' => $this->pagina,
            'consentimento' => $this->consentimento,
            'consentidoEm' => $this->consentido_em?->toIso8601ZuluString() ?? '',
            'data' => $this->created_at?->toIso8601ZuluString(),
            'actualizado' => $this->updated_at?->toIso8601ZuluString(),
            'estado' => $this->estado,
            'notas' => (string) $this->notas,
        ];
    }
}
