<?php

namespace Tests\Feature;

use App\Models\Aviso;
use App\Models\Conversa;
use App\Models\PedidoAgente;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgenteTest extends TestCase
{
    use RefreshDatabase;

    private const SESSAO = 'sessao-de-teste-123456';

    private function ativar(): void
    {
        config(['ecomulti.agente.chave_api' => 'chave-falsa', 'ecomulti.whatsapp.token' => null]);
    }

    private function resposta(array $conteudo, string $parou): array
    {
        return ['id' => 'msg_x', 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'content' => $conteudo, 'stop_reason' => $parou, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
    }

    private function uso(string $id, string $nome, array $input): array
    {
        return ['type' => 'tool_use', 'id' => $id, 'name' => $nome, 'input' => $input];
    }

    /** @return array<int, array> eventos NDJSON da resposta */
    private function eventos($resposta): array
    {
        $texto = $resposta->streamedContent();

        return array_values(array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", $texto))));
    }

    public function test_diz_se_a_ia_esta_activa(): void
    {
        $this->getJson('/api/agente')->assertOk()->assertJson(['ativo' => false]);
        $this->ativar();
        $this->getJson('/api/agente')->assertJson(['ativo' => true]);
    }

    public function test_sem_chave_o_chat_passa_a_modo_guiado(): void
    {
        $this->postJson('/api/agente', ['sessao' => self::SESSAO, 'mensagem' => 'olá'])->assertStatus(503)->assertJson(['modo' => 'guiado']);
    }

    public function test_pedido_invalido_e_recusado(): void
    {
        $this->ativar();
        $this->postJson('/api/agente', ['sessao' => 'curta', 'mensagem' => 'olá'])->assertStatus(400);
        $this->postJson('/api/agente', ['sessao' => self::SESSAO, 'mensagem' => str_repeat('a', 1300)])->assertStatus(400);
    }

    public function test_responde_com_texto_simples_e_guarda_a_conversa(): void
    {
        $this->ativar();
        Http::fake(['api.anthropic.com/*' => Http::response($this->resposta([['type' => 'text', 'text' => 'Olá! Como posso ajudar?']], 'end_turn'))]);

        $ev = $this->eventos($this->postJson('/api/agente', ['sessao' => self::SESSAO, 'mensagem' => 'olá', 'pagina' => '/dominios.html']));

        $this->assertSame('txt', $ev[0]['t']);
        $this->assertStringContainsString('Olá! Como posso ajudar?', $ev[0]['d']);
        $this->assertSame('fim', end($ev)['t']);

        $conversa = Conversa::find('site:'.self::SESSAO);
        $this->assertSame(1, $conversa->estado['turnos']);
        // na 1.ª mensagem vai o contexto da página
        $this->assertStringContainsString('página "Domínios"', $conversa->estado['mensagens'][0]['content']);
    }

    public function test_fluxo_completo_orcamento_e_pedido(): void
    {
        $this->ativar();
        $confirmar = [
            'nome' => 'Padaria Esperança', 'tipo_cliente' => 'Empresa', 'telefone' => '+244 923 000 111', 'email' => 'geral@padaria.ao',
            'nif' => '5000123456', 'metodo_pagamento' => 'Multicaixa Express', 'cliente_confirmou' => true,
        ];
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->resposta([$this->uso('t1', 'definir_orcamento', ['itens' => [
                ['servico' => 'dominio', 'dominio' => 'padaria.ao'],
                ['servico' => 'hospedagem', 'plano' => 'starter', 'dominio' => 'padaria.ao', 'anos' => 1],
            ]])], 'tool_use'))
            ->push($this->resposta([['type' => 'text', 'text' => 'Total: 114.000 Kz']], 'end_turn'))
            ->push($this->resposta([$this->uso('t2', 'registar_pedido', $confirmar)], 'tool_use'))
            ->push($this->resposta([['type' => 'text', 'text' => 'Pedido registado.']], 'end_turn')),
        ]);

        // 1.ª mensagem: orçamento
        $ev = $this->eventos($this->postJson('/api/agente', ['sessao' => self::SESSAO, 'mensagem' => 'quero domínio e hospedagem']));
        $accao = collect($ev)->firstWhere('t', 'accao');
        $this->assertSame('orcamento', $accao['tipo']);
        $this->assertCount(2, $accao['itens']);
        // domínio 35.000 + hospedagem 3.325×12 = 39.900 → 74.900; +IVA 14% = 85.386
        $this->assertSame('74.900 Kz', $accao['subtotal']);
        $this->assertSame('85.386 Kz', $accao['total_estimado']);

        // 2.ª mensagem: confirma e regista
        $ev = $this->eventos($this->postJson('/api/agente', ['sessao' => self::SESSAO, 'mensagem' => 'confirmo']));
        $pedido = collect($ev)->firstWhere('tipo', 'pedido');
        $this->assertMatchesRegularExpression('/^ECO-\d{6}-\d{4}$/', $pedido['numero']);

        $p = PedidoAgente::where('numero', $pedido['numero'])->first();
        $this->assertSame('site', $p->canal);
        $this->assertSame('Padaria Esperança', $p->cliente['nome']);
        $this->assertSame('+244923000111', $p->cliente['telefone']);   // sem espaços

        // aparece como venda em aberto no dashboard e avisa a equipa
        $venda = Venda::find($pedido['numero']);
        $this->assertSame('em_aberto', $venda->estado);
        $this->assertEquals(85386.0, round($venda->total));
        $this->assertSame(1, Aviso::where('tipo', 'pedido')->count());
    }

    public function test_pedido_sem_confirmacao_do_cliente_nao_e_registado(): void
    {
        $this->ativar();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->resposta([$this->uso('t1', 'definir_orcamento', ['itens' => [['servico' => 'dominio', 'dominio' => 'a.ao']]])], 'tool_use'))
            ->push($this->resposta([$this->uso('t2', 'registar_pedido', ['nome' => 'X Y', 'tipo_cliente' => 'Particular', 'telefone' => '923000111', 'metodo_pagamento' => 'Multicaixa Express', 'cliente_confirmou' => false])], 'tool_use'))
            ->push($this->resposta([['type' => 'text', 'text' => 'Preciso da sua confirmação.']], 'end_turn')),
        ]);

        $this->eventos($this->postJson('/api/agente', ['sessao' => self::SESSAO, 'mensagem' => 'registe já']));

        $this->assertSame(0, PedidoAgente::count());
        $this->assertSame(0, Venda::count());
    }

    public function test_empresa_sem_nif_e_recusada(): void
    {
        $this->ativar();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->resposta([$this->uso('t1', 'definir_orcamento', ['itens' => [['servico' => 'dominio', 'dominio' => 'a.ao']]])], 'tool_use'))
            ->push($this->resposta([$this->uso('t2', 'registar_pedido', ['nome' => 'Empresa X', 'tipo_cliente' => 'Empresa', 'telefone' => '923000111', 'metodo_pagamento' => 'Multicaixa Express', 'cliente_confirmou' => true])], 'tool_use'))
            ->push($this->resposta([['type' => 'text', 'text' => 'Preciso do NIF.']], 'end_turn')),
        ]);

        $this->eventos($this->postJson('/api/agente', ['sessao' => self::SESSAO, 'mensagem' => 'confirmo']));

        $this->assertSame(0, PedidoAgente::count());
    }

    public function test_chamar_humano_avisa_a_equipa(): void
    {
        $this->ativar();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->resposta([$this->uso('t1', 'chamar_humano', ['motivo' => 'reclamacao', 'resumo' => 'Cliente zangado com a factura'])], 'tool_use'))
            ->push($this->resposta([['type' => 'text', 'text' => 'Vou chamar a equipa.']], 'end_turn')),
        ]);

        $ev = $this->eventos($this->postJson('/api/agente', ['sessao' => self::SESSAO, 'mensagem' => 'quero falar com alguém']));

        $this->assertSame('humano', collect($ev)->firstWhere('t', 'accao')['tipo']);
        $aviso = Aviso::first();
        $this->assertSame('humano', $aviso->tipo);
        $this->assertSame('reclamacao', $aviso->motivo);
    }

    public function test_erro_da_api_nao_grava_a_conversa(): void
    {
        $this->ativar();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'x'], 500)]);

        $ev = $this->eventos($this->postJson('/api/agente', ['sessao' => self::SESSAO, 'mensagem' => 'olá']));

        $this->assertSame('erro', end($ev)['t']);
        $this->assertNull(Conversa::find('site:'.self::SESSAO));
    }

    public function test_limite_de_mensagens_por_conversa(): void
    {
        $this->ativar();
        config(['ecomulti.agente.limite_conversa' => 1]);
        Http::fake(['api.anthropic.com/*' => Http::response($this->resposta([['type' => 'text', 'text' => 'ok']], 'end_turn'))]);

        $this->eventos($this->postJson('/api/agente', ['sessao' => self::SESSAO, 'mensagem' => 'um']));
        $this->postJson('/api/agente', ['sessao' => self::SESSAO, 'mensagem' => 'dois'])->assertStatus(429)->assertJson(['modo' => 'humano']);
    }

    public function test_limite_diario_global(): void
    {
        $this->ativar();
        config(['ecomulti.agente.limite_diario' => 0]);

        $this->postJson('/api/agente', ['sessao' => self::SESSAO, 'mensagem' => 'um'])->assertStatus(429)->assertJson(['modo' => 'guiado']);
    }

    public function test_historico_com_ferramenta_sem_argumentos_continua_valido(): void
    {
        // guardado em JSON, "input": {} volta como [] — a API exige objecto
        $this->ativar();
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->resposta([$this->uso('t1', 'chamar_humano', ['motivo' => 'outro', 'resumo' => 'x'])], 'tool_use'))
            ->push($this->resposta([['type' => 'text', 'text' => 'ok']], 'end_turn'))
            ->push($this->resposta([['type' => 'text', 'text' => 'ok de novo']], 'end_turn')),
        ]);
        $this->eventos($this->postJson('/api/agente', ['sessao' => self::SESSAO, 'mensagem' => 'um']));
        $c = Conversa::find('site:'.self::SESSAO);
        $estado = $c->estado;
        $estado['mensagens'][1]['content'][0]['input'] = [];   // simula {} → []
        $c->update(['estado' => $estado]);

        $this->eventos($this->postJson('/api/agente', ['sessao' => self::SESSAO, 'mensagem' => 'dois']));

        $ultimo = Http::recorded()->last()[0];
        $this->assertStringContainsString('"input":{}', $ultimo->body());
    }
}
