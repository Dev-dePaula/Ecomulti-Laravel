<?php

use App\Models\Conversa;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 * Limpeza diária: apaga conversas do assistente sem actividade há mais de 7 dias.
 * Os pedidos e avisos ficam (a equipa precisa deles).
 */
Artisan::command('ecomulti:limpar', function () {
    $n = Conversa::where('actualizado', '<', (int) round((microtime(true) - 7 * 86400) * 1000))->delete();
    $this->info("Conversas antigas apagadas: $n");
})->purpose('Apaga conversas do assistente com mais de 7 dias');

Schedule::command('ecomulti:limpar')->daily();

// Fila (WhatsApp): processa o que estiver pendente a cada minuto, sem processo permanente
// (ideal para hospedagem partilhada: basta um cron a correr "php artisan schedule:run" por minuto)
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=1')->everyMinute()->withoutOverlapping();
