<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_todas_as_paginas_respondem(): void
    {
        foreach (SiteController::PAGINAS as $p) {
            $url = $p === 'index' ? '/' : "/$p.html";
            $this->get($url)->assertStatus($p === '404' ? 404 : 200);
        }
    }

    public function test_pagina_inexistente_mostra_o_404_do_site(): void
    {
        $this->get('/nao-existe')->assertNotFound()->assertSee('ECOMULTI', false);
    }

    public function test_paginas_reservadas_nao_sao_indexadas(): void
    {
        $this->get('/admin.html')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_config_js_sai_da_configuracao_do_laravel(): void
    {
        config(['ecomulti.precos.dominio.ao' => 1]);
        $r = $this->get('/assets/js/config.js')->assertOk();
        $r->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $corpo = $r->getContent();
        $this->assertStringContainsString('window.ECOMULTI', $corpo);
        $this->assertStringContainsString('"hospedagem.starter": 3325', $corpo);
        $this->assertStringContainsString('/api/agente', $corpo);
    }

    public function test_nada_no_site_depende_de_servicos_externos_de_hospedagem(): void
    {
        $txt = '';
        foreach (['/', '/privacidade.html', '/assets/js/config.js'] as $u) {
            $txt .= $this->get($u)->getContent();
        }
        $this->assertStringNotContainsStringIgnoringCase('netlify', $txt);
    }
}
