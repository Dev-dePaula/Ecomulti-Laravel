<?php

namespace App\Exceptions;

/** Erro com mensagem para o utilizador e o código HTTP a devolver. */
class ErroApi extends \RuntimeException
{
    public function __construct(string $mensagem, public int $estado = 400)
    {
        parent::__construct($mensagem);
    }
}
