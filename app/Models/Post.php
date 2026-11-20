<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $table = 'posts';

    protected $guarded = [];

    protected $casts = ['tags' => 'array', 'destaque' => 'boolean', 'data' => 'date:Y-m-d'];

    public function paraApi(): array
    {
        return [
            'slug' => $this->slug,
            'estado' => $this->estado,
            'titulo' => $this->titulo,
            'resumo' => $this->resumo,
            'conteudo' => $this->conteudo,
            'categoria' => $this->categoria,
            'tags' => $this->tags ?? [],
            'autor' => $this->autor,
            'imagem' => $this->imagem,
            'data' => $this->data?->format('Y-m-d'),
            'destaque' => $this->destaque,
            'actualizado' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
