<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Estado de uma conversa do assistente (site: "site:<sessão>", WhatsApp: "wa:<telefone>"). */
class Conversa extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'conversas';

    protected $guarded = [];

    protected $casts = ['estado' => 'array'];
}
