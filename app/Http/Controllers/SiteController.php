<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class SiteController extends Controller
{
    /** páginas do site: cada uma é uma view em resources/views/site */
    public const PAGINAS = [
        'index', 'dominios', 'hospedagem-ecomulti', 'email-corporativo-ecomulti', 'desenvolvimento-ecomulti',
        'orcamento-ecomulti', 'cliente', 'loja', 'vps', 'automacao', 'blog', 'artigo', 'privacidade', '404',
        'painel', 'admin', 'blog-admin', 'dominios-ecomulti', 'ecomulti-pagamento',
    ];

    /** páginas que nunca devem ser guardadas em cache nem indexadas */
    private const RESERVADAS = ['painel', 'admin', 'blog-admin', 'cliente'];

    public function pagina(string $pagina): Response
    {
        $r = response()->view("site.$pagina", [], $pagina === '404' ? 404 : 200);
        if (in_array($pagina, self::RESERVADAS, true)) {
            $r->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store');
        }

        return $r;
    }

    /** window.precoDe(chave) — os preços de config/ecomulti.php (mesma fonte do config.js) */
    public function catalogo(): Response
    {
        $valores = config('ecomulti.precos');
        $js = "(function () {
  \"use strict\";
  var valores = ".json_encode($valores).";
"
            ."  window.precoDe = function (chave) {
    if (!(chave in valores)) throw new Error(\"Preço desconhecido: \" + chave);
    return valores[chave];
  };
"
            ."  window.precoUnidade = function (chave) { return /^regra\.(desconto|prioritario)/.test(chave) ? \"%\" : \"Kz\"; };
})();
";

        return response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=300']);
    }

    /** window.ECOMULTI — contactos, preços e endereços da API (todos no próprio site) */
    public function config(): Response
    {
        $c = config('ecomulti');
        $dados = [
            'empresa' => $c['empresa'],
            'pagamento' => $c['pagamento'],
            'agente' => ['endpoint' => '/api/agente'],
            'blog' => ['endpoint' => '/api/blog', 'ficheiro' => 'blog/posts.json'],
            'newsletter' => ['endpoint' => '/api/newsletter'],
            'admin' => ['endpoint' => '/api/admin'],
            'pedidos' => ['endpoint' => '/api/pedido'],
            'contacto' => ['endpoint' => '/api/contacto'],
            'cliente' => ['endpoint' => '/api/cliente'],
            'vps' => $c['vps'],
            'fiscal' => $c['fiscal'],
            'precos' => $c['precos'],
        ];
        $js = "/* Gerado a partir de config/ecomulti.php — não editar. */\nwindow.ECOMULTI = "
            .json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION).";\n";

        return response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=300']);
    }
}
