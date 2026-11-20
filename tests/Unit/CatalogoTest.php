<?php

namespace Tests\Unit;

use App\Services\Agente\Catalogo;
use Tests\TestCase;

class CatalogoTest extends TestCase
{
    public function test_formata_kwanzas(): void
    {
        $this->assertSame('35.000 Kz', Catalogo::kz(35000));
        $this->assertSame('0 Kz', Catalogo::kz(null));
    }

    public function test_divide_dominios(): void
    {
        $this->assertSame(['nome' => 'padaria', 'ext' => '.co.ao', 'dominio' => 'padaria.co.ao'], Catalogo::dividirDominio('https://www.Padaria.co.ao/x'));
        $this->assertNull(Catalogo::dividirDominio('padaria.xyz'));
        $this->assertNull(Catalogo::dividirDominio('-mau-.ao'));
    }

    public function test_dominio_por_anos(): void
    {
        $r = Catalogo::precificar(['servico' => 'dominio', 'dominio' => 'loja.ao', 'anos' => 2]);
        $this->assertSame(70000, $r['linha']['total']);
        $this->assertArrayHasKey('erro', Catalogo::precificar(['servico' => 'dominio', 'dominio' => 'loja.xyz']));
    }

    public function test_hospedagem_aplica_desconto_de_dois_anos(): void
    {
        $r = Catalogo::precificar(['servico' => 'hospedagem', 'plano' => 'business', 'dominio' => 'loja.ao', 'anos' => 2]);
        // 6.660 × 24 meses = 159.840, menos 10% = 143.856
        $this->assertSame(143856, $r['linha']['total']);
        $this->assertContains('Desconto de 10% incluído', $r['linha']['detalhes']);
    }

    public function test_hospedagem_exige_dominio_e_plano_validos(): void
    {
        $this->assertArrayHasKey('erro', Catalogo::precificar(['servico' => 'hospedagem', 'plano' => 'business']));
        $this->assertArrayHasKey('erro', Catalogo::precificar(['servico' => 'hospedagem', 'plano' => 'ouro', 'dominio' => 'a.ao']));
    }

    public function test_website_com_extras_paginas_e_prazo_prioritario(): void
    {
        $r = Catalogo::precificar(['servico' => 'website', 'plano' => 'institucional', 'paginas_extra' => 2, 'extras' => ['seo', 'logo'], 'prioritario' => true]);
        // 280.000 + 2×15.000 + 45.000 + 35.000 = 390.000; +20% = 468.000
        $this->assertSame(468000, $r['linha']['total']);
        $this->assertArrayHasKey('erro', Catalogo::precificar(['servico' => 'website', 'plano' => 'landing', 'extras' => ['inexistente']]));
    }

    public function test_vps_precisa_de_descricao_e_fica_sob_consulta(): void
    {
        $this->assertArrayHasKey('erro', Catalogo::precificar(['servico' => 'vps']));
        $this->assertSame(0, Catalogo::precificar(['servico' => 'vps', 'descricao' => 'ERP'])['linha']['total']);
    }

    public function test_iva_e_acrescentado(): void
    {
        $t = Catalogo::totais([['total' => 100000], ['total' => 0]]);
        $this->assertEqualsWithDelta(100000, $t['sub'], 0.01);
        $this->assertEqualsWithDelta(14000, $t['iva'], 0.01);
        $this->assertEqualsWithDelta(114000, $t['total'], 0.01);
    }

    public function test_numero_do_pedido_tem_o_formato_esperado(): void
    {
        $this->assertMatchesRegularExpression('/^ECO-\d{6}-\d{4}$/', Catalogo::numeroPedido());
    }
}
