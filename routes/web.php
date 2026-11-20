<?php

use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

// Configuração do site (JavaScript) gerada a partir de config/ecomulti.php: única fonte de preços e contactos
Route::get('/assets/js/config.js', [SiteController::class, 'config']);

// Preços em JavaScript (window.precoDe), lidos de config/ecomulti.php; <script src="api/catalogo.php">
Route::get('/api/catalogo.php', [SiteController::class, 'catalogo']);

// Os artigos vêm da base de dados (/api/blog); este ficheiro só existe porque blog.js o procura
Route::get('/blog/posts.json', fn () => response()->json(['posts' => []]));

// Páginas do site: servidas com e sem ".html"
Route::get('/', [SiteController::class, 'pagina'])->defaults('pagina', 'index');
foreach (SiteController::PAGINAS as $pagina) {
    Route::get("/{$pagina}.html", [SiteController::class, 'pagina'])->defaults('pagina', $pagina);
    if ($pagina !== 'index') {
        Route::get("/{$pagina}", [SiteController::class, 'pagina'])->defaults('pagina', $pagina);
    }
}
