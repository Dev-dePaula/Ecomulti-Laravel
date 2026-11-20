<?php

namespace Tests\Feature;

use App\Models\Venda;
use App\Services\Cliente\Subscricoes;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Event;
use Illuminate\Mail\Events\MessageSent;
use Tests\TestCase;

class ClienteTest extends TestCase
{
    use RefreshDatabase;

    private array $codigos = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['mail.default' => 'array']);
        $this->codigos = [];
        Event::listen(MessageSent::class, function (MessageSent $e) {
            if (preg_match('/c[óo]digo é: (\d{6})/u', (string) $e->message->getTextBody(), $m)) {
                $this->codigos[] = $m[1];
            }
        });
    }

    private function cliente(string $acao, array $d = [], ?string $token = null)
    {
        return $this->postJson('/api/cliente', ['acao' => $acao] + $d, $token ? ['Authorization' => "Bearer $token"] : []);
    }

    private function registarEConfirmar(string $email = 'ana@exemplo.ao'): string
    {
        $this->cliente('registar', ['nome' => 'Ana', 'email' => $email, 'senha' => 'senha-segura-1'])->assertOk();
        $this->assertCount(1, $this->codigos);

        return $this->cliente('confirmar', ['email' => $email, 'senha' => 'senha-segura-1', 'codigo' => $this->codigos[0]])
            ->assertOk()->json('token');
    }

    public function test_registo_confirmacao_e_sessao(): void
    {
        $token = $this->registarEConfirmar();
        $this->cliente('sessao', [], $token)->assertOk()->assertJson(['ok' => true, 'cliente' => ['email' => 'ana@exemplo.ao']]);
        $this->cliente('entrar', ['email' => 'ANA@exemplo.ao', 'senha' => 'senha-segura-1'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_codigo_errado_e_senha_errada_sao_recusados(): void
    {
        $this->cliente('registar', ['nome' => 'Ana', 'email' => 'ana@exemplo.ao', 'senha' => 'senha-segura-1'])->assertOk();
        $this->cliente('confirmar', ['email' => 'ana@exemplo.ao', 'senha' => 'senha-segura-1', 'codigo' => '000000'])->assertStatus(400);
        $this->cliente('confirmar', ['email' => 'ana@exemplo.ao', 'senha' => 'outra-senha-xx', 'codigo' => $this->codigos[0]])->assertStatus(400);
        $this->cliente('entrar', ['email' => 'ana@exemplo.ao', 'senha' => 'senha-segura-1'])->assertOk()->assertJson(['ok' => false, 'confirmar' => true]);
    }

    public function test_entrar_com_senha_errada_e_sem_sessao(): void
    {
        $this->registarEConfirmar();
        $this->cliente('entrar', ['email' => 'ana@exemplo.ao', 'senha' => 'errada-errada'])->assertStatus(401);
        $this->cliente('subscricoes')->assertStatus(401);
        $this->cliente('sessao', [], 'lixo.lixo')->assertStatus(401);
    }

    public function test_recuperar_senha_invalida_a_sessao_antiga(): void
    {
        $token = $this->registarEConfirmar();
        $this->codigos = [];
        $this->cliente('recuperar', ['email' => 'ana@exemplo.ao'])->assertOk();
        $this->assertCount(1, $this->codigos);
        $this->cliente('redefinir', ['email' => 'ana@exemplo.ao', 'codigo' => $this->codigos[0], 'senha' => 'nova-senha-123'])->assertOk();
        $this->cliente('sessao', [], $token)->assertStatus(401);
        $this->cliente('entrar', ['email' => 'ana@exemplo.ao', 'senha' => 'nova-senha-123'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_cinco_tentativas_erradas_gastam_o_codigo(): void
    {
        $this->cliente('registar', ['nome' => 'Ana', 'email' => 'ana@exemplo.ao', 'senha' => 'senha-segura-1'])->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->cliente('confirmar', ['email' => 'ana@exemplo.ao', 'senha' => 'senha-segura-1', 'codigo' => '111111']);
        }
        $this->cliente('confirmar', ['email' => 'ana@exemplo.ao', 'senha' => 'senha-segura-1', 'codigo' => $this->codigos[0]])->assertStatus(400);
    }

    public function test_cliente_so_ve_as_suas_subscricoes_de_vendas_pagas(): void
    {
        $token = $this->registarEConfirmar();
        Venda::create(['id' => 'ECO-T-0001', 'data' => now(), 'cliente' => ['nome' => 'Ana', 'email' => 'ana@exemplo.ao'],
            'itens' => [['descricao' => 'Domínio empresa.ao — 2 anos', 'valor' => 70000], ['descricao' => 'Website Institucional', 'valor' => 280000]],
            'total' => 350000, 'estado' => 'pago', 'pago_em' => now(), 'canal' => 'manual']);
        Venda::create(['id' => 'ECO-T-0002', 'data' => now(), 'cliente' => ['nome' => 'Ana', 'email' => 'ana@exemplo.ao'],
            'itens' => [['descricao' => 'E-mail Profissional', 'valor' => 4500]], 'total' => 4500, 'estado' => 'em_aberto', 'canal' => 'manual']);
        Venda::create(['id' => 'ECO-T-0003', 'data' => now(), 'cliente' => ['nome' => 'Rui', 'email' => 'rui@exemplo.ao'],
            'itens' => [['descricao' => 'Hospedagem Business', 'valor' => 6660]], 'total' => 6660, 'estado' => 'pago', 'pago_em' => now(), 'canal' => 'manual']);

        $subs = $this->cliente('subscricoes', [], $token)->assertOk()->json('subscricoes');
        $this->assertCount(2, $subs);
        $dominio = collect($subs)->firstWhere('dominio', 'empresa.ao');
        $this->assertSame('recorrente', $dominio['tipo']);
        $this->assertSame(Subscricoes::somarAnos(now()->utc()->format('Y-m-d'), 2), $dominio['renovacao']);
        $site = collect($subs)->firstWhere('servico', 'Website Institucional');
        $this->assertSame('unico', $site['tipo']);
        $this->assertSame('', $site['renovacao']);
    }

    public function test_contacto_envia_para_a_equipa_e_filtra_robos(): void
    {
        Mail::fake();
        $this->postJson('/api/contacto', ['nome' => 'Ana', 'contacto' => 'ana@exemplo.ao', 'texto' => 'Olá, quero um site', 'assunto' => 'Pedido'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->postJson('/api/contacto', ['nome' => 'Bot', 'contacto' => 'bot@x.com', 'texto' => 'spam', 'site' => 'http://spam'])->assertOk();
        $this->postJson('/api/contacto', ['nome' => 'A', 'contacto' => '', 'texto' => ''])->assertStatus(400);
        $this->postJson('/api/contacto', ['nome' => 'Ana', 'contacto' => 'abc', 'texto' => 'x'])->assertStatus(400);
    }

    public function test_admin_gere_subscricoes_e_20i_sem_configuracao(): void
    {
        $this->seed(DatabaseSeeder::class);
        $t = $this->postJson('/api/admin', ['acao' => 'login', 'email' => 'admin@teste.ao', 'senha' => 'senha-de-teste-123'])->json('token');
        $h = ['Authorization' => "Bearer $t"];
        $this->postJson('/api/admin', ['acao' => 'vendas.guardar', 'cliente' => ['nome' => 'Ana', 'email' => 'ana@exemplo.ao'],
            'itens' => [['descricao' => 'Hospedagem Business', 'valor' => 6660]], 'total' => 6660, 'estado' => 'pago'], $h)->assertOk();

        $subs = $this->postJson('/api/admin', ['acao' => 'subs.listar'], $h)->assertOk()->json('subscricoes');
        $this->assertCount(1, $subs);
        $id = $subs[0]['id'];
        $this->postJson('/api/admin', ['acao' => 'subs.guardar', 'id' => $id, 'servico' => 'Hospedagem Business', 'dominio' => 'ana.ao',
            'tipo' => 'recorrente', 'renovacao' => '2030-01-31', 'inicio' => '2029-01-31', 'estado' => 'activa'], $h)->assertOk()->assertJsonPath('subscricao.dominio', 'ana.ao');
        $this->postJson('/api/admin', ['acao' => 'subs.guardar', 'id' => $id, 'renovacao' => '31/01/2030'], $h)->assertStatus(400);

        $this->postJson('/api/admin', ['acao' => 'vintei.tipos'], $h)->assertStatus(503);
    }

    public function test_admin_activa_hospedagem_na_20i_uma_so_vez(): void
    {
        config(['ecomulti.vintei.chave' => 'chave-de-teste']);
        Http::fake(['api.20i.com/reseller/*/addWeb' => Http::response(['result' => 98765]),
            'api.20i.com/reseller/*/packageTypes' => Http::response([['id' => 7, 'label' => 'Business']])]);
        $this->seed(DatabaseSeeder::class);
        $t = $this->postJson('/api/admin', ['acao' => 'login', 'email' => 'admin@teste.ao', 'senha' => 'senha-de-teste-123'])->json('token');
        $h = ['Authorization' => "Bearer $t"];
        $v = $this->postJson('/api/admin', ['acao' => 'vendas.guardar', 'cliente' => ['nome' => 'Ana', 'email' => 'ana@exemplo.ao'],
            'itens' => [['descricao' => 'Hospedagem Business', 'valor' => 6660]], 'total' => 6660, 'estado' => 'pago'], $h)->json('venda.id');

        $this->postJson('/api/admin', ['acao' => 'vintei.tipos'], $h)->assertOk()->assertJsonPath('tipos.0.nome', 'Business');
        $this->postJson('/api/admin', ['acao' => 'vintei.activar', 'id' => $v, 'tipo' => '7', 'dominio' => 'https://www.Ana.ao/'], $h)
            ->assertOk()->assertJsonPath('venda.vintei.pacoteId', '98765')->assertJsonPath('venda.vintei.dominio', 'ana.ao');
        $this->postJson('/api/admin', ['acao' => 'vintei.activar', 'id' => $v, 'tipo' => '7', 'dominio' => 'ana.ao'], $h)->assertStatus(409);
        // domínios .ao não passam pela 20i; sem confirmação não há compra
        $this->postJson('/api/admin', ['acao' => 'vintei.dominio', 'id' => $v, 'dominio' => 'ana.ao', 'anos' => 1, 'confirmo' => true], $h)->assertStatus(400);
        $this->postJson('/api/admin', ['acao' => 'vintei.dominio', 'id' => $v, 'dominio' => 'ana.com', 'anos' => 1], $h)->assertStatus(400);
    }

    public function test_telefone_epp(): void
    {
        $this->assertSame('+244.923340895', \App\Services\Vintei::telefoneEPP('923340895'));
        $this->assertSame('+244.923340895', \App\Services\Vintei::telefoneEPP('+244 923 340 895'));
        $this->assertSame('+351.912345678', \App\Services\Vintei::telefoneEPP('00351912345678'));
        $this->assertSame('+1.5551234567', \App\Services\Vintei::telefoneEPP('+1 555 123 4567'));
    }

    public function test_paginas_novas_e_catalogo(): void
    {
        $this->get('/cliente.html')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/loja.html')->assertOk();
        $this->get('/api/catalogo.php')->assertOk()->assertSee('window.precoDe', false)->assertSee('hospedagem.starter', false);
        $this->get('/assets/js/config.js')->assertSee('/api/cliente', false)->assertSee('/api/contacto', false);
    }
}
