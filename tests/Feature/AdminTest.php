<?php

namespace Tests\Feature;

use App\Mail\MensagemPedido;
use App\Models\Cliente;
use App\Models\Lead;
use App\Models\Venda;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function entrar(): string
    {
        return $this->postJson('/api/admin', ['acao' => 'login', 'email' => 'admin@teste.ao', 'senha' => 'senha-de-teste-123'])
            ->assertOk()->json('token');
    }

    private function admin(string $token, string $acao, array $dados = [])
    {
        return $this->postJson('/api/admin', ['acao' => $acao] + $dados, ['Authorization' => "Bearer $token"]);
    }

    public function test_login_certo_devolve_token_e_senha_inicial(): void
    {
        $this->postJson('/api/admin', ['acao' => 'login', 'email' => 'ADMIN@teste.ao', 'senha' => 'senha-de-teste-123'])
            ->assertOk()->assertJson(['ok' => true, 'email' => 'admin@teste.ao', 'senhaInicial' => true])->assertJsonStructure(['token', 'expira']);
    }

    public function test_login_errado_e_recusado_e_bloqueia_apos_5_falhas(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/admin', ['acao' => 'login', 'email' => 'admin@teste.ao', 'senha' => 'errada'])->assertStatus(401);
        }
        $this->postJson('/api/admin', ['acao' => 'login', 'email' => 'admin@teste.ao', 'senha' => 'senha-de-teste-123'])->assertStatus(429);
    }

    public function test_acoes_exigem_sessao(): void
    {
        $this->postJson('/api/admin', ['acao' => 'resumo'])->assertStatus(401);
        $this->postJson('/api/admin', ['acao' => 'resumo'], ['Authorization' => 'Bearer lixo.lixo'])->assertStatus(401);
    }

    public function test_token_adulterado_e_recusado(): void
    {
        $t = $this->entrar();
        [$corpo, $sig] = explode('.', $t);
        $this->admin($corpo.'.'.strrev($sig), 'sessao')->assertStatus(401);
        $this->admin($t, 'sessao')->assertOk()->assertJson(['email' => 'admin@teste.ao']);
    }

    public function test_mudar_a_senha_invalida_as_sessoes_antigas(): void
    {
        $antigo = $this->entrar();
        $this->admin($antigo, 'senha', ['actual' => 'senha-de-teste-123', 'nova' => 'curta1'])->assertStatus(400);
        $this->admin($antigo, 'senha', ['actual' => 'senha-de-teste-123', 'nova' => 'apenasletrasaqui'])->assertStatus(400);
        $this->admin($antigo, 'senha', ['actual' => 'errada-errada1', 'nova' => 'NovaSenha12345'])->assertStatus(403);

        $novo = $this->admin($antigo, 'senha', ['actual' => 'senha-de-teste-123', 'nova' => 'NovaSenha12345'])->assertOk()->json('token');

        $this->admin($antigo, 'sessao')->assertStatus(401);
        $this->admin($novo, 'sessao')->assertOk()->assertJson(['senhaInicial' => false]);
        $this->postJson('/api/admin', ['acao' => 'login', 'email' => 'admin@teste.ao', 'senha' => 'NovaSenha12345'])->assertOk();
    }

    public function test_criar_venda_manual_e_marcar_como_paga_cria_o_cliente_e_envia_email(): void
    {
        Mail::fake();
        $t = $this->entrar();
        $venda = $this->admin($t, 'vendas.guardar', [
            'cliente' => ['nome' => 'Maria Silva', 'email' => 'maria@exemplo.ao', 'telefone' => '923111222', 'tipo' => 'Particular'],
            'itens' => [['descricao' => 'Registo de domínio — maria.ao', 'valor' => 35000]],
        ])->assertOk()->json('venda');

        $this->assertSame('em_aberto', $venda['estado']);
        $this->assertEquals(35000, $venda['total']);
        $this->assertMatchesRegularExpression('/^ECO-\d{6}-\d{4}$/', $venda['id']);

        $r = $this->admin($t, 'vendas.estado', ['id' => $venda['id'], 'estado' => 'pago', 'metodo' => 'Transferência', 'credenciais' => 'utilizador: maria'])
            ->assertOk()->json();
        $this->assertSame('pago', $r['venda']['estado']);
        $this->assertSame('enviado ao cliente', $r['email']);
        $this->assertNotSame('', $r['venda']['clienteId']);
        $this->assertSame(1, Cliente::count());
        Mail::assertSent(MensagemPedido::class, fn ($m) => $m->tipo === 'confirmacao' && $m->hasTo('maria@exemplo.ao') && str_contains($m->credenciais, 'maria'));

        // marcar paga outra vez não duplica o cliente
        $this->admin($t, 'vendas.estado', ['id' => $venda['id'], 'estado' => 'pago', 'enviarEmail' => false])->assertOk();
        $this->assertSame(1, Cliente::count());
    }

    public function test_venda_sem_valor_ou_sem_nome_e_recusada(): void
    {
        $t = $this->entrar();
        $this->admin($t, 'vendas.guardar', ['cliente' => ['nome' => 'A'], 'itens' => [['descricao' => 'x', 'valor' => 5]]])->assertStatus(400);
        $this->admin($t, 'vendas.guardar', ['cliente' => ['nome' => 'Maria Silva'], 'itens' => []])->assertStatus(400);
    }

    public function test_resumo_soma_pagas_e_em_aberto(): void
    {
        $t = $this->entrar();
        foreach ([['x1', 'pago', 50000], ['x2', 'em_aberto', 20000], ['x3', 'cancelado', 9000]] as [$id, $estado, $valor]) {
            Venda::create(['id' => "ECO-261120-$id", 'data' => now(), 'cliente' => ['nome' => 'C'], 'itens' => [], 'total' => $valor, 'estado' => $estado, 'pago_em' => $estado === 'pago' ? now() : null]);
        }

        $r = $this->admin($t, 'resumo')->assertOk()->json();

        $this->assertEquals(50000, $r['pagoTotal']);
        $this->assertEquals(50000, $r['mes']['total']);
        $this->assertSame(1, $r['aberto']['n']);
        $this->assertEquals(20000, $r['aberto']['total']);
        $this->assertCount(6, $r['serie']);
    }

    public function test_leads_criar_actualizar_e_converter_em_cliente(): void
    {
        $t = $this->entrar();
        $this->admin($t, 'leads.criar', ['email' => 'novo@exemplo.ao', 'nome' => 'Novo'])->assertOk();
        $this->admin($t, 'leads.criar', ['email' => 'novo@exemplo.ao'])->assertStatus(400);   // duplicado

        $this->admin($t, 'leads.actualizar', ['email' => 'novo@exemplo.ao', 'estado' => 'contactado', 'notas' => 'ligar'])
            ->assertOk()->assertJsonPath('lead.estado', 'contactado');

        $this->admin($t, 'leads.converter', ['email' => 'novo@exemplo.ao'])->assertOk();
        $this->assertSame('convertido', Lead::first()->estado);
        $this->assertSame('Novo', Cliente::first()->nome);

        $this->admin($t, 'leads.apagar', ['email' => 'novo@exemplo.ao'])->assertOk();
        $this->assertSame(0, Lead::count());
    }

    public function test_clientes_guardar_listar_apagar(): void
    {
        $t = $this->entrar();
        $c = $this->admin($t, 'clientes.guardar', ['nome' => 'Empresa Lda', 'tipo' => 'Empresa', 'nif' => '5000123456'])->assertOk()->json('cliente');
        $this->assertCount(1, $this->admin($t, 'clientes.listar')->json('clientes'));
        $this->admin($t, 'clientes.guardar', ['id' => $c['id'], 'nome' => 'Empresa Lda', 'notas' => 'VIP'])->assertOk();
        $this->assertSame(1, Cliente::count());
        $this->admin($t, 'clientes.apagar', ['id' => $c['id']])->assertOk();
        $this->assertSame(0, Cliente::count());
    }

    public function test_acao_desconhecida(): void
    {
        $this->admin($this->entrar(), 'apagar.tudo')->assertStatus(400);
    }
}
