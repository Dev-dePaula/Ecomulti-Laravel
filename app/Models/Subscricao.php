<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscricao extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'subscricoes';

    protected $guarded = [];

    protected $casts = ['valor' => 'float', 'editada' => 'boolean'];
}
