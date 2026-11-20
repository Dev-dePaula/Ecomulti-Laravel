<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Conta da área do cliente (não confundir com Cliente, o registo comercial do dashboard). */
class ClienteConta extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'email';

    protected $table = 'cliente_contas';

    protected $guarded = [];

    protected $hidden = ['senha'];

    protected $casts = ['verificado' => 'boolean', 'verificado_em' => 'datetime'];
}
