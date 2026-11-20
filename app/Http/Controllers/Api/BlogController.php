<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AutorizadoApi;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GET    /api/blog          — público: artigos publicados (+ "lápides" dos ocultados)
 * GET    /api/blog?todos=1  — painel: todos, incluindo rascunhos
 * POST   /api/blog          — painel: cria ou actualiza um artigo (pelo slug)
 * DELETE /api/blog?slug=x   — painel: apaga um artigo
 */
class BlogController extends Controller
{
    private const ESTADOS = ['publicado', 'rascunho', 'arquivado'];

    private const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function listar(Request $request): JsonResponse
    {
        if ($request->query('todos') === '1') {
            // só com sessão (o middleware não corre nesta rota, que também é pública)
            if (! AutorizadoApi::permitido($request)) {
                return AutorizadoApi::negar();
            }

            return response()->json(['posts' => Post::orderByDesc('data')->get()->map->paraApi()->values()]);
        }

        // público: só o que está publicado; os restantes vão só como lápide (escondem o exemplo com o mesmo slug)
        $posts = Post::all()->map(fn (Post $p) => $p->estado === 'publicado' ? $p->paraApi() : ['slug' => $p->slug, 'estado' => $p->estado])->values();

        return response()->json(['posts' => $posts], 200, ['Cache-Control' => 'public, max-age=60, stale-while-revalidate=300']);
    }

    public function guardar(Request $request): JsonResponse
    {
        $d = $request->json()->all();
        $slug = strtolower(PedidoController::txt($d['slug'] ?? '', 90));
        if (! preg_match(self::SLUG, $slug)) {
            return response()->json(['erro' => 'O endereço (slug) só pode ter letras minúsculas, números e hífens.'], 400);
        }
        $estado = in_array($d['estado'] ?? '', self::ESTADOS, true) ? $d['estado'] : 'rascunho';
        $titulo = PedidoController::txt($d['titulo'] ?? '', 160);
        if ($estado !== 'arquivado' && mb_strlen($titulo) < 5) {
            return response()->json(['erro' => 'O título é obrigatório (mínimo 5 letras).'], 400);
        }
        $imagem = PedidoController::txt($d['imagem'] ?? '', 500);
        if ($imagem !== '' && ! preg_match('#^(https://|assets/img/)#', $imagem)) {
            return response()->json(['erro' => 'A imagem deve ser um endereço https:// ou uma imagem em assets/img/.'], 400);
        }
        $ts = strtotime((string) ($d['data'] ?? ''));
        $data = $ts ? date('Y-m-d', $ts) : Carbon::now()->format('Y-m-d');

        $post = Post::updateOrCreate(['slug' => $slug], [
            'estado' => $estado, 'titulo' => $titulo,
            'resumo' => PedidoController::txt($d['resumo'] ?? '', 320),
            'conteudo' => PedidoController::txt($d['conteudo'] ?? '', 60000),
            'categoria' => PedidoController::txt($d['categoria'] ?? '', 40) ?: 'Notícias',
            'tags' => array_slice(array_values(array_filter(array_map(fn ($t) => PedidoController::txt($t, 30), is_array($d['tags'] ?? null) ? $d['tags'] : []))), 0, 8),
            'autor' => PedidoController::txt($d['autor'] ?? '', 60) ?: 'Equipa ECOMULTI',
            'imagem' => $imagem, 'data' => $data, 'destaque' => ! empty($d['destaque']),
        ]);

        return response()->json(['ok' => true, 'post' => $post->fresh()->paraApi()]);
    }

    public function apagar(Request $request): JsonResponse
    {
        $slug = PedidoController::txt($request->query('slug'), 90);
        if (! preg_match(self::SLUG, $slug)) {
            return response()->json(['erro' => 'slug inválido'], 400);
        }
        Post::where('slug', $slug)->delete();

        return response()->json(['ok' => true]);
    }
}
