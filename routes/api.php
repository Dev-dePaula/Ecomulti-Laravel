<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AgenteController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ContactoController;
use App\Http\Controllers\Api\NewsletterController;
use App\Http\Controllers\Api\PainelController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\WhatsAppController;
use Illuminate\Support\Facades\Route;

// Chat do site (IA)
Route::get('/agente', [AgenteController::class, 'estado']);
Route::post('/agente', [AgenteController::class, 'conversar']);

// Área do cliente (conta, confirmação do e-mail por código, subscrições)
Route::post('/cliente', ClienteController::class);

// "Enviar por e-mail" dos formulários: o servidor envia a mensagem para a caixa da ECOMULTI
Route::post('/contacto', ContactoController::class);

// Pedidos de orçamento feitos no site
Route::post('/pedido', [PedidoController::class, 'registar']);

// Blog (a listagem completa exige sessão; verificado no controller)
Route::get('/blog', [BlogController::class, 'listar']);
Route::post('/blog', [BlogController::class, 'guardar'])->middleware('autorizado');
Route::delete('/blog', [BlogController::class, 'apagar'])->middleware('autorizado');

// Newsletter
Route::post('/newsletter', [NewsletterController::class, 'subscrever']);
Route::get('/newsletter', [NewsletterController::class, 'listar'])->middleware('autorizado');
Route::delete('/newsletter', [NewsletterController::class, 'remover'])->middleware('autorizado');

// Dashboard administrativo e painel da equipa
Route::post('/admin', AdminController::class);
Route::get('/painel', PainelController::class);

// Webhook do WhatsApp Business (Meta)
Route::get('/whatsapp', [WhatsAppController::class, 'verificar']);
Route::post('/whatsapp', [WhatsAppController::class, 'receber']);
