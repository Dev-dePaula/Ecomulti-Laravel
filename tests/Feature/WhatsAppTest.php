<?php

namespace Tests\Feature;

use App\Models\Aviso;
use App\Models\Conversa;
use App\Models\PedidoAgente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private function assinar(string $corpo): array
    {
        return ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $corpo, 'segredo-teste')];
    }

    private function mensagem(string $id, string $texto, ?int $quando = null): string
    {
        return json_encode(['entry' => [['changes' => [['value' => [
            'contacts' => [['wa_id' => '244923555666', 'profile' => ['name' => 'Rui']]],
            'messages' => [['id' => $id, 'from' => '244923555666', 'timestamp' => (string) ($quando ?? time()), 'type' => 'text', 'text' => ['body' => $texto]]],
        ]]]]]]);
    }

    private function enviar(string $corpo, ?array $cabecalhos = null)
    {
        return $this->call('POST', '/api/whatsapp', [], [], [], $this->transformHeadersToServerVars(($cabecalhos ?? $this->assinar($corpo)) + ['Content-Type' => 'application/json']), $corpo);
    }

    private function resposta(array $conteudo, string $parou = 'end_turn'): array
    {
        return ['id' => 'm', 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'content' => $conteudo, 'stop_reason' => $parou, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
    }

    /** @return array<int, string> textos enviados ao cliente pela API da Meta */
    private function enviados(): array
    {
        return collect(Http::recorded())
            ->map(fn ($par) => $par[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com') && ($r->data()['type'] ?? '') === 'text')
            ->map(fn (Request $r) => $r->data()['text']['body'])->values()->all();
    }

    public function test_verificacao_do_webhook(): void
    {
        $this->get('/api/whatsapp?hub.mode=subscribe&hub.verify_token=verifica-teste&hub.challenge=abc123')->assertOk()->assertSee('abc123');
        $this->get('/api/whatsapp?hub.mode=subscribe&hub.verify_token=errado&hub.challenge=abc123')->assertForbidden();
    }

    public function test_assinatura_invalida_e_recusada(): void
    {
        Http::fake();
        $this->enviar($this->mensagem('wamid.1', 'olá'), ['X-Hub-Signature-256' => 'sha256=00'])->assertStatus(401);
        $this->enviar($this->mensagem('wamid.1', 'olá'), [])->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_estados_de_entrega_sao_ignorados(): void
    {
        Http::fake();
        $corpo = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'x', 'status' => 'delivered']]]]]]]]);
        $this->enviar($corpo)->assertOk();
        Http::assertNothingSent();
    }

    public function test_sem_ia_responde_com_o_menu_de_links(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'ok']]])]);

        $this->enviar($this->mensagem('wamid.menu', 'olá'))->assertOk();

        $texto = implode("\n", $this->enviados());
        $this->assertStringContainsString('/dominios.html', $texto);
        $this->assertStringContainsString('wa.me/244923340895', $texto);
    }

    public function test_responde_com_a_ia_e_guarda_a_conversa(): void
    {
        config(['ecomulti.agente.chave_api' => 'chave-falsa']);
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->resposta([['type' => 'text', 'text' => 'Olá Rui! Em que posso ajudar?']])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'ok']]]),
        ]);

        $this->enviar($this->mensagem('wamid.ia', 'preciso de um site'))->assertOk();

        $this->assertSame(['Olá Rui! Em que posso ajudar?'], $this->enviados());
        $c = Conversa::find('wa:244923555666');
        $this->assertSame(1, $c->estado['turnos']);
        $this->assertStringContainsString('Nome no perfil: Rui', $c->estado['mensagens'][0]['content']);
    }

    public function test_mensagem_repetida_pela_meta_so_e_processada_uma_vez(): void
    {
        config(['ecomulti.agente.chave_api' => 'chave-falsa']);
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->resposta([['type' => 'text', 'text' => 'Olá!']])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'ok']]]),
        ]);

        $this->enviar($this->mensagem('wamid.dup', 'olá'))->assertOk();
        $this->enviar($this->mensagem('wamid.dup', 'olá'))->assertOk();

        $this->assertCount(1, $this->enviados());
    }

    public function test_mensagens_antigas_sao_ignoradas(): void
    {
        config(['ecomulti.agente.chave_api' => 'chave-falsa']);
        Http::fake();
        $this->enviar($this->mensagem('wamid.velha', 'olá', time() - 7200))->assertOk();
        Http::assertNothingSent();
    }

    public function test_mensagem_que_nao_e_texto_recebe_pedido_de_texto(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'ok']]])]);
        $corpo = json_encode(['entry' => [['changes' => [['value' => ['messages' => [['id' => 'wamid.img', 'from' => '244923555666', 'timestamp' => (string) time(), 'type' => 'image']]]]]]]]);

        $this->enviar($corpo)->assertOk();

        $this->assertStringContainsString('mensagens de texto', implode(' ', $this->enviados()));
    }

    public function test_pedido_pelo_whatsapp_avisa_a_equipa_com_o_contacto_do_cliente(): void
    {
        config(['ecomulti.agente.chave_api' => 'chave-falsa', 'ecomulti.whatsapp.equipa' => '244900000000']);
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->resposta([['type' => 'tool_use', 'id' => 't1', 'name' => 'definir_orcamento', 'input' => ['itens' => [['servico' => 'dominio', 'dominio' => 'rui.ao']]]]], 'tool_use'))
                ->push($this->resposta([['type' => 'tool_use', 'id' => 't2', 'name' => 'registar_pedido', 'input' => ['nome' => 'Rui Neto', 'tipo_cliente' => 'Particular', 'telefone' => '923555666', 'metodo_pagamento' => 'Multicaixa Express', 'cliente_confirmou' => true]]], 'tool_use'))
                ->push($this->resposta([['type' => 'text', 'text' => 'Pedido registado!']])),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'ok']]]),
        ]);

        $this->enviar($this->mensagem('wamid.pedido', 'quero registar rui.ao, confirmo'))->assertOk();

        $p = PedidoAgente::first();
        $this->assertSame('whatsapp', $p->canal);
        $this->assertSame(1, Aviso::where('tipo', 'pedido')->count());

        $para = collect(Http::recorded())->map(fn ($x) => $x[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com'))
            ->map(fn (Request $r) => [$r->data()['to'] ?? '', $r->data()['text']['body'] ?? ''])->values();
        $aviso = $para->firstWhere(0, '244900000000');
        $this->assertStringContainsString($p->numero, $aviso[1]);
        $this->assertStringContainsString('wa.me/244923555666', $aviso[1]);
        $this->assertNotNull($para->firstWhere(0, '244923555666'));   // e responde ao cliente
    }

    public function test_falha_da_ia_pede_desculpa_e_avisa_a_equipa(): void
    {
        config(['ecomulti.agente.chave_api' => 'chave-falsa']);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['erro' => 'x'], 500),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'ok']]]),
        ]);

        $this->enviar($this->mensagem('wamid.falha', 'olá'))->assertOk();

        $this->assertStringContainsString('problema técnico', implode(' ', $this->enviados()));
        $this->assertSame('erro_tecnico', Aviso::first()->motivo);
        $this->assertNull(Conversa::find('wa:244923555666'));
    }
}
