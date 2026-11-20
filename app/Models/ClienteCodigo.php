<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClienteCodigo extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'chave';

    protected $table = 'cliente_codigos';

    protected $guarded = [];
}
