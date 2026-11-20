<?php

/*
|--------------------------------------------------------------------------
| ECOMULTI — configuração central
|--------------------------------------------------------------------------
| Única fonte de contactos, dados da empresa e PREÇOS. O site (JavaScript) e o
| assistente de IA leem daqui: assets/js/config.js é gerado a partir deste ficheiro.
*/

return [
    'site_url' => rtrim(env('SITE_URL', 'https://www.ecomulti.ao'), '/'),

    'empresa' => [
        'nome' => 'ECOMULTI',
        'email' => 'suporte@ecomulti.ao',
        'whatsapp' => '244923340895',          // só números, com indicativo
        'telefoneVisivel' => '+244 923 340 895',
        'morada' => '',
        'nif' => '',
        'horario' => 'Segunda a sexta, 08h00–18h00',
        'redes' => ['facebook' => '', 'instagram' => '', 'linkedin' => ''],
    ],

    // Dados para pagamento por transferência (conta da MARKDECOR). Campos vazios aparecem como "A indicar".
    'pagamento' => ['titular' => 'Markdecor', 'banco' => '', 'iban' => '', 'conta' => ''],

    'fiscal' => ['taxaIVA' => 0.14, 'precosIncluemIVA' => false],

    'vps' => [
        'ciclos' => [
            'mensal' => ['meses' => 1, 'desc' => 0, 'nome' => 'Mensal'],
            'trimestral' => ['meses' => 3, 'desc' => 0.05, 'nome' => 'Trimestral', 'rotulo' => '-5%'],
            'anual' => ['meses' => 12, 'desc' => 0.15, 'nome' => 'Anual', 'rotulo' => '-15%'],
        ],
        'planos' => [
            'start' => ['nome' => 'VPS Start', 'preco' => 35000, 'cpu' => '2 vCPU', 'ram' => '4 GB RAM', 'disco' => '60 GB NVMe', 'banda' => '2 TB',
                'itens' => ['Linux à escolha (Ubuntu, Debian, AlmaLinux)', 'IP dedicado IPv4', 'Acesso root total', 'Backup semanal', 'Suporte por WhatsApp']],
            'pro' => ['nome' => 'VPS Pro', 'preco' => 65000, 'destaque' => true, 'cpu' => '4 vCPU', 'ram' => '8 GB RAM', 'disco' => '120 GB NVMe', 'banda' => '4 TB',
                'itens' => ['Linux ou Windows Server', 'IP dedicado IPv4', 'Acesso root / administrador', 'Backup diário automático', 'Firewall e protecção DDoS', 'Suporte prioritário 24/7']],
            'elite' => ['nome' => 'VPS Elite', 'preco' => 120000, 'cpu' => '8 vCPU', 'ram' => '16 GB RAM', 'disco' => '240 GB NVMe', 'banda' => '8 TB',
                'itens' => ['Linux ou Windows Server', '2 IPs dedicados', 'Acesso root / administrador', 'Backup diário + snapshots', 'Firewall, DDoS e monitorização', 'Migração gratuita assistida', 'Gestor de conta dedicado']],
        ],
    ],

    // Tabela de preços (Kz)
    'precos' => [
        'hospedagem.starter' => 3325,
        'hospedagem.business' => 6660,
        'hospedagem.premium' => 15900,
        'vps.desde' => 35000,
        'automacao.desde' => 75000,
        'email.essencial' => 1500,
        'email.profissional' => 4500,
        'email.empresarial' => 9000,
        'desenvolvimento.landing' => 120000,
        'desenvolvimento.institucional' => 280000,
        'desenvolvimento.loja' => 650000,
        'regra.pagina_extra' => 15000,
        'extra.blog' => 40000,
        'extra.idiomas' => 50000,
        'extra.seo' => 45000,
        'extra.logo' => 35000,
        'extra.formacao' => 20000,
        'regra.prioritario' => 20,       // %
        'dominio.ao' => 35000,
        'dominio.co.ao' => 40000,
        'dominio.it.ao' => 32400,
        'dominio.edu.ao' => 32400,
        'dominio.org.ao' => 32400,
        'dominio.com' => 32000,
        'dominio.net' => 32000,
        'dominio.org' => 32000,
        'regra.desconto_2anos' => 10,    // %
        'regra.desconto_3anos' => 15,    // %
    ],

    // O que cada plano inclui (usado pelo assistente de IA)
    'catalogo' => [
        'hospedagem' => [
            'starter' => ['nome' => 'Starter', 'chave' => 'hospedagem.starter', 'inclui' => ['5 GB SSD', '1 website', '5 contas de e-mail', 'SSL', 'painel de gestão'], 'para' => 'pequenos sites e projectos pessoais'],
            'business' => ['nome' => 'Business', 'chave' => 'hospedagem.business', 'inclui' => ['10 GB SSD', 'até 5 websites', '20 contas de e-mail', 'SSL', 'cópias de segurança diárias'], 'para' => 'empresas com presença profissional (o mais popular)'],
            'premium' => ['nome' => 'Premium', 'chave' => 'hospedagem.premium', 'inclui' => ['10 GB SSD', 'até 15 websites', '50 contas de e-mail', 'SSL', 'cópias de segurança e suporte prioritário'], 'para' => 'projectos com mais tráfego e muitos sites'],
        ],
        'email' => [
            'essencial' => ['nome' => 'Essencial', 'chave' => 'email.essencial', 'inclui' => ['1 conta', '10 GB', 'domínio próprio', 'webmail', 'filtro básico de spam'], 'para' => 'profissionais e pequenos negócios'],
            'profissional' => ['nome' => 'Profissional', 'chave' => 'email.profissional', 'inclui' => ['até 5 contas', '10 GB por conta', 'webmail e aplicações', 'filtro de spam reforçado'], 'para' => 'equipas (o mais escolhido)'],
            'empresarial' => ['nome' => 'Empresarial', 'chave' => 'email.empresarial', 'inclui' => ['até 15 contas', '10 GB por conta', 'endereços por departamento', 'suporte prioritário'], 'para' => 'equipas maiores'],
        ],
        'website' => [
            'landing' => ['nome' => 'Landing Page', 'chave' => 'desenvolvimento.landing', 'dias' => 7, 'inclui' => ['1 página optimizada', 'design responsivo', 'formulário de contacto', 'botão de WhatsApp']],
            'institucional' => ['nome' => 'Site Institucional', 'chave' => 'desenvolvimento.institucional', 'dias' => 15, 'inclui' => ['até 8 páginas', 'design personalizado', 'formulários e mapa', 'SEO básico']],
            'loja' => ['nome' => 'Loja Online', 'chave' => 'desenvolvimento.loja', 'dias' => 30, 'inclui' => ['catálogo e carrinho', 'painel de encomendas', 'pagamentos locais', 'design responsivo e SEO']],
        ],
        'extras' => [
            'blog' => ['nome' => 'Blog ou secção de notícias', 'chave' => 'extra.blog'],
            'idiomas' => ['nome' => 'Site em dois idiomas', 'chave' => 'extra.idiomas'],
            'seo' => ['nome' => 'SEO avançado', 'chave' => 'extra.seo'],
            'logo' => ['nome' => 'Criação de logótipo', 'chave' => 'extra.logo'],
            'formacao' => ['nome' => 'Formação para gerir o site', 'chave' => 'extra.formacao'],
        ],
        'extensoes' => ['.ao', '.co.ao', '.it.ao', '.edu.ao', '.org.ao', '.com', '.net', '.org'],
    ],

    'agente' => [
        'modelo' => env('AGENTE_MODELO', 'claude-opus-5-5'),
        'chave_api' => env('ANTHROPIC_API_KEY'),
        'limite_diario' => (int) env('AGENTE_LIMITE_DIARIO', 600),
        'limite_conversa' => (int) env('AGENTE_LIMITE_CONVERSA', 40),
        'limite_conversa_wa' => (int) env('AGENTE_LIMITE_CONVERSA_WA', 80),
        'max_voltas' => 8,
        'api_url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com'),
    ],

    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'versao' => env('WHATSAPP_API_VERSAO', 'v23.0'),
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'equipa' => env('EQUIPA_WHATSAPP'),
        'modelo_aviso' => env('WHATSAPP_MODELO_AVISO'),
        'modelo_idioma' => env('WHATSAPP_MODELO_IDIOMA', 'pt_PT'),
        'pausa_humano_horas' => (int) env('WHATSAPP_PAUSA_HUMANO_HORAS', 0),
    ],

    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'senha' => env('ADMIN_SENHA'),
        'painel_chave' => env('PAINEL_CHAVE'),
    ],

    // Ligação à 20i (revenda): directa (VINTEI_API_KEY) ou pelo proxy PHP com IP fixo (VINTEI_URL + VINTEI_PROXY_CHAVE)
    'vintei' => [
        'chave' => env('VINTEI_API_KEY'),
        'url' => env('VINTEI_URL'),
        'proxy_chave' => env('VINTEI_PROXY_CHAVE'),
    ],

    // "Enviar por e-mail" dos formulários do site
    'contacto' => [
        'destino' => env('CONTACTO_EMAIL', 'suporte@ecomulti.ao'),
        'limite_ip_hora' => (int) env('CONTACTO_LIMITE_IP', 10),
    ],

    'email_equipa' => env('EMAIL_EQUIPA', 'suporte@ecomulti.ao'),
    'limite_pedidos_ip_dia' => (int) env('PEDIDO_LIMITE_IP', 20),
    'limite_newsletter_ip_dia' => (int) env('NEWSLETTER_LIMITE_IP', 15),
];
