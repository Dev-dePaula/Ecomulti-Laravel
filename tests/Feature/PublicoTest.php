<?php

namespace Tests\Feature;

use App\Mail\MensagemPedido;
use App\Models\Aviso;
use App\Models\Lead;
use App\Models\PedidoAgente;
use App\Models\Post;
use App\Models\Venda;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Pedido de orçamento, newsletter, blog e painel da equipa. */
class PublicoTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = ['Authorization' => 'Bearer chave-do-painel-1234'];

    private function pedido(array $extra = []): array
    {
        return $extra + [
            'cliente' => ['nome' => 'João Costa', 'email' => 'joao@exemplo.ao', 'telefone' => '923000111', 'tipo' => 'Particular'],
            'itens' => [['descricao' => 'Registo de domínio — joao.ao', 'valor' => 35000, 'detalhes' => ['1 ano']]],
            'total' => 39900, 'metodo' => 'Multicaixa Express',
        ];
    }

    /* ----------------------------- pedido de orçamento ----------------------------- */
    public function test_pedido_cria_venda_em_aberto_e_envia_email_de_reserva(): void
    {
        Mail::fake();
        $r = $this->postJson('/api/pedido', $this->pedido())->assertOk()->assertJson(['ok' => true, 'emailEnviado' => true]);

        $venda = Venda::find($r->json('numero'));
        $this->assertSame('em_aberto', $venda->estado);
        $this->assertSame('orcamento-site', $venda->canal);
        Mail::assertSent(MensagemPedido::class, fn ($m) => $m->tipo === 'reserva' && $m->hasTo('joao@exemplo.ao'));
    }

    public function test_reenviar_o_mesmo_pedido_nao_duplica_nem_reenvia_email(): void
    {
        Mail::fake();
        $n = $this->postJson('/api/pedido', $this->pedido(['numero' => 'ECO-261120-1234']))->json('numero');
        $this->postJson('/api/pedido', $this->pedido(['numero' => $n]))->assertOk();

        $this->assertSame(1, Venda::count());
        Mail::assertSentCount(1);
    }

    public function test_pedido_ja_pago_nunca_e_alterado(): void
    {
        Venda::create(['id' => 'ECO-261120-9999', 'data' => now(), 'cliente' => ['nome' => 'Original'], 'itens' => [], 'total' => 1000, 'estado' => 'pago', 'pago_em' => now()]);

        $this->postJson('/api/pedido', $this->pedido(['numero' => 'ECO-261120-9999']))->assertOk();

        $this->assertSame('Original', Venda::find('ECO-261120-9999')->cliente['nome']);
    }

    public function test_pedido_invalido(): void
    {
        $this->postJson('/api/pedido', ['cliente' => ['nome' => 'A'], 'itens' => []])->assertStatus(400);
        $this->postJson('/api/pedido', $this->pedido(['cliente' => ['nome' => 'João Costa']]))->assertStatus(400);   // sem contacto
    }

    public function test_limite_diario_de_pedidos_por_ip(): void
    {
        Mail::fake();
        config(['ecomulti.limite_pedidos_ip_dia' => 2]);
        $this->postJson('/api/pedido', $this->pedido())->assertOk();
        $this->postJson('/api/pedido', $this->pedido())->assertOk();
        $this->postJson('/api/pedido', $this->pedido())->assertStatus(429);
    }

    /* -------------------------------- newsletter ---------------------------------- */
    public function test_subscrever_newsletter_e_juntar_interesses(): void
    {
        $dados = ['email' => 'Ana@Exemplo.ao', 'nome' => 'Ana', 'whatsapp' => '923 000 000', 'consentimento' => true, 'interesses' => ['Hospedagem', 'Invalido'], 'origem' => 'blog'];
        $this->postJson('/api/newsletter', $dados)->assertOk()->assertJson(['ok' => true, 'jaExistia' => false]);
        $this->postJson('/api/newsletter', ['email' => 'ana@exemplo.ao', 'consentimento' => true, 'interesses' => ['VPS'], 'origem' => 'site'])
            ->assertOk()->assertJson(['jaExistia' => true]);

        $this->assertSame(1, Lead::count());
        $l = Lead::first();
        $this->assertSame('ana@exemplo.ao', $l->email);
        $this->assertSame('+244923000000', $l->whatsapp);
        $this->assertEqualsCanonicalizing(['Hospedagem', 'VPS'], $l->interesses);
        $this->assertSame('blog', $l->origem);   // fica a primeira origem
    }

    public function test_newsletter_exige_consentimento_e_email_valido(): void
    {
        $this->postJson('/api/newsletter', ['email' => 'ana@exemplo.ao'])->assertStatus(400);
        $this->postJson('/api/newsletter', ['email' => 'inválido', 'consentimento' => true])->assertStatus(400);
        $this->postJson('/api/newsletter', ['email' => 'ana@exemplo.ao', 'consentimento' => true, 'whatsapp' => '12345'])->assertStatus(400);
    }

    public function test_newsletter_campo_isco_finge_sucesso_sem_guardar(): void
    {
        $this->postJson('/api/newsletter', ['email' => 'robo@exemplo.ao', 'consentimento' => true, 'website' => 'http://spam'])->assertOk();
        $this->assertSame(0, Lead::count());
    }

    public function test_lista_de_subscritores_so_com_autorizacao(): void
    {
        Lead::create(['email' => 'a@exemplo.ao', 'interesses' => []]);
        $this->getJson('/api/newsletter')->assertStatus(401);
        $this->getJson('/api/newsletter', self::CHAVE)->assertOk()->assertJsonCount(1, 'subscritores');
        $this->deleteJson('/api/newsletter?email=a@exemplo.ao', [], self::CHAVE)->assertOk();
        $this->assertSame(0, Lead::count());
    }

    /* ----------------------------------- blog ------------------------------------- */
    public function test_blog_publico_so_mostra_publicados_e_lapides(): void
    {
        $this->seed(DatabaseSeeder::class);
        Post::create(['slug' => 'rascunho-x', 'estado' => 'rascunho', 'titulo' => 'Segredo', 'conteudo' => 'x', 'tags' => [], 'data' => '2026-11-01']);

        $posts = collect($this->getJson('/api/blog')->assertOk()->json('posts'));

        $this->assertNotEmpty($posts->firstWhere('estado', 'publicado'));
        $this->assertSame(['slug' => 'rascunho-x', 'estado' => 'rascunho'], $posts->firstWhere('slug', 'rascunho-x'));
    }

    public function test_blog_todos_exige_autorizacao(): void
    {
        $this->getJson('/api/blog?todos=1')->assertStatus(401);
        $this->getJson('/api/blog?todos=1', self::CHAVE)->assertOk();
    }

    public function test_blog_criar_actualizar_e_apagar_artigo(): void
    {
        $base = ['slug' => 'novo-artigo', 'titulo' => 'Um novo artigo', 'estado' => 'publicado', 'conteudo' => 'Olá **mundo**', 'tags' => ['a', 'b']];
        $this->postJson('/api/blog', $base)->assertStatus(401);

        $this->postJson('/api/blog', $base, self::CHAVE)->assertOk()->assertJsonPath('post.titulo', 'Um novo artigo');
        $this->postJson('/api/blog', ['titulo' => 'Título alterado'] + $base, self::CHAVE)->assertOk();
        $this->assertSame(1, Post::count());
        $this->assertSame('Título alterado', Post::first()->titulo);

        $this->deleteJson('/api/blog?slug=novo-artigo', [], self::CHAVE)->assertOk();
        $this->assertSame(0, Post::count());
    }

    public function test_blog_valida_slug_titulo_e_imagem(): void
    {
        $this->postJson('/api/blog', ['slug' => 'Mau Slug', 'titulo' => 'Título válido'], self::CHAVE)->assertStatus(400);
        $this->postJson('/api/blog', ['slug' => 'ok', 'titulo' => 'abc'], self::CHAVE)->assertStatus(400);
        $this->postJson('/api/blog', ['slug' => 'ok', 'titulo' => 'Título válido', 'imagem' => 'http://inseguro/x.png'], self::CHAVE)->assertStatus(400);
    }

    /* --------------------------------- painel ------------------------------------- */
    public function test_painel_lista_pedidos_e_avisos_com_a_chave(): void
    {
        PedidoAgente::create(['numero' => 'ECO-261120-1111', 'canal' => 'site', 'cliente' => ['nome' => 'X'], 'itens' => [], 'totais' => ['total' => 1], 'texto' => 't']);
        Aviso::create(['tipo' => 'humano', 'motivo' => 'outro', 'canal' => 'site', 'texto' => 't']);

        $this->getJson('/api/painel')->assertStatus(401);
        $this->getJson('/api/painel', ['Authorization' => 'Bearer errada-errada-12'])->assertStatus(401);
        $this->getJson('/api/painel', self::CHAVE)->assertOk()->assertJsonCount(1, 'pedidos')->assertJsonCount(1, 'avisos')
            ->assertJsonPath('pedidos.0.numero', 'ECO-261120-1111');
    }

    public function test_painel_sem_chave_configurada_fica_indisponivel(): void
    {
        config(['ecomulti.admin.painel_chave' => '']);
        $this->getJson('/api/painel', self::CHAVE)->assertStatus(503);
    }
}
