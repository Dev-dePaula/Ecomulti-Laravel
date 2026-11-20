<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'clientes';

    protected $guarded = [];

    public function paraApi(): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'tipo' => $this->tipo,
            'telefone' => $this->telefone,
            'email' => $this->email,
            'nif' => $this->nif,
            'morada' => $this->morada,
            'notas' => (string) $this->notas,
            'criadoEm' => $this->created_at?->toIso8601ZuluString(),
            'actualizado' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
