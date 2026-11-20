<?php

namespace App\Services\Agente;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Núcleo do agente de atendimento ECOMULTI (Claude + ferramentas).
 * Usado pelo chat do site e pelo WhatsApp. Recebe o "estado" da conversa e actualiza-o.
 */
class Nucleo
{
    public static function activo(): bool
    {
        return (string) config('ecomulti.agente.chave_api') !== '';
    }

    /* ---------------------------- ferramentas ---------------------------- */
    public static function ferramentas(): array
    {
        return [
            [
                'name' => 'verificar_dominio',
                'description' => 'Verifica em tempo real se um nome de domínio está livre nas extensões indicadas e devolve o preço anual de cada uma. Indicativo: a equipa confirma junto do registador antes do registo.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'nome' => ['type' => 'string', 'description' => "Nome sem extensão, ex.: 'padariaesperanca'. Só letras, números e hífen."],
                        'extensoes' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => Catalogo::extensoes()], 'description' => 'Extensões a verificar. Omitir = todas.'],
                    ],
                    'required' => ['nome'],
                ],
            ],
            [
                'name' => 'definir_orcamento',
                'description' => 'Define o orçamento actual do cliente com a lista COMPLETA de serviços que ele quer (substitui a anterior). Calcula preços, descontos e IVA com a tabela oficial e devolve o resumo. No site, o orçamento aparece também no carrinho.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'itens' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'servico' => ['type' => 'string', 'enum' => ['dominio', 'hospedagem', 'email', 'website', 'vps', 'automacao_ia']],
                                    'plano' => ['type' => 'string', 'description' => 'hospedagem: '.implode('|', array_keys(Catalogo::hospedagem())).'; email: '.implode('|', array_keys(Catalogo::email())).'; website: '.implode('|', array_keys(Catalogo::website())).'. Não usar em dominio/vps/automacao_ia.'],
                                    'dominio' => ['type' => 'string', 'description' => 'Domínio completo, ex.: minhaempresa.ao. Obrigatório para dominio, hospedagem e email.'],
                                    'anos' => ['type' => 'integer', 'enum' => [1, 2, 3], 'description' => 'Período (domínio, hospedagem, e-mail). Por omissão 1.'],
                                    'paginas_extra' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 30, 'description' => 'Só website.'],
                                    'extras' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(Catalogo::extras())], 'description' => 'Só website.'],
                                    'prioritario' => ['type' => 'boolean', 'description' => 'Só website: prazo prioritário.'],
                                    'descricao' => ['type' => 'string', 'description' => 'Só vps/automacao_ia: necessidade do cliente em poucas palavras.'],
                                ],
                                'required' => ['servico'],
                            ],
                        ],
                    ],
                    'required' => ['itens'],
                ],
            ],
            [
                'name' => 'registar_pedido',
                'description' => 'Regista o pedido com o orçamento actual e os dados do cliente, gera o número do pedido (ECO-...) e avisa a equipa. Só usar depois de o cliente ver o resumo e confirmar explicitamente.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'nome' => ['type' => 'string', 'description' => 'Nome completo ou nome da empresa'],
                        'tipo_cliente' => ['type' => 'string', 'enum' => ['Empresa', 'Particular']],
                        'telefone' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'nif' => ['type' => 'string', 'description' => 'Obrigatório para empresa'],
                        'morada' => ['type' => 'string'],
                        'metodo_pagamento' => ['type' => 'string', 'enum' => ['Multicaixa Express', 'Pagamento por referência', 'Transferência bancária']],
                        'observacoes' => ['type' => 'string'],
                        'cliente_confirmou' => ['type' => 'boolean', 'description' => 'true só se o cliente confirmou o resumo e os dados'],
                    ],
                    'required' => ['nome', 'tipo_cliente', 'telefone', 'metodo_pagamento', 'cliente_confirmou'],
                ],
            ],
            [
                'name' => 'chamar_humano',
                'description' => 'Passa a conversa para a equipa humana (pedido do cliente, cliente actual com problema, reclamação, desconto especial, ou dúvida que não sabes responder com segurança).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'motivo' => ['type' => 'string', 'enum' => ['pedido_do_cliente', 'suporte_cliente_actual', 'reclamacao', 'negociacao', 'duvida_sem_resposta', 'outro']],
                        'resumo' => ['type' => 'string', 'description' => 'Resumo curto para a equipa: quem é, o que quer, o que já foi dito.'],
                    ],
                    'required' => ['motivo', 'resumo'],
                ],
            ],
        ];
    }

    /** validação mínima do que o modelo envia */
    private static function validar(string $nome, mixed $input): ?string
    {
        if (! is_array($input)) {
            return 'input inválido';
        }
        foreach (self::ferramentas() as $f) {
            if ($f['name'] === $nome) {
                foreach ($f['input_schema']['required'] as $campo) {
                    if (! array_key_exists($campo, $input) || $input[$campo] === '' || $input[$campo] === null) {
                        return "falta o campo $campo";
                    }
                }

                return null;
            }
        }

        return 'ferramenta desconhecida';
    }

    private static function resumoOrcamento(array $linhas): array
    {
        $t = Catalogo::totais($linhas);

        return [
            'itens' => array_map(fn ($l) => [
                'servico' => $l['servicoLabel'], 'plano' => $l['plano'], 'detalhes' => $l['detalhes'],
                'total' => $l['total'] ? Catalogo::kz($l['total']) : 'sob consulta',
            ], $linhas),
            'subtotal' => Catalogo::kz($t['sub']),
            'iva' => Catalogo::kz($t['iva']),
            'total_estimado' => Catalogo::kz($t['total']),
        ];
    }

    /**
     * Executa uma ferramenta. $ctx: estado (por referência), canal, sessao, contacto, pausaHumano,
     * aoAccao(array), guardarPedido(array), avisarEquipa(array)
     */
    private static function executar(string $nome, array $input, array &$ctx): array
    {
        $canal = $ctx['canal'];
        $empresa = Catalogo::empresa();

        switch ($nome) {
            case 'verificar_dominio':
                $base = preg_replace('/\.(co\.ao|it\.ao|edu\.ao|org\.ao|ao|com|net|org)$/i', '', (string) $input['nome']);
                $d = Catalogo::dividirDominio($base.'.ao');
                if (! $d) {
                    return ['erro' => 'Nome inválido: use só letras, números e hífen (sem espaços, acentos, nem hífen no início/fim).'];
                }
                $pedidas = is_array($input['extensoes'] ?? null) && $input['extensoes'] ? $input['extensoes'] : Catalogo::extensoes();
                $exts = array_values(array_filter($pedidas, fn ($e) => in_array($e, Catalogo::extensoes(), true)));
                $resultados = array_map(function ($e) use ($d) {
                    $r = Dominios::verificar($d['nome'], $e);
                    $r['preco_ano'] = Catalogo::kz($r['preco_ano_kz']);

                    return $r;
                }, $exts);

                return ['resultados' => $resultados, 'nota' => 'Verificação indicativa; a equipa confirma junto do registador antes de registar.'];

            case 'definir_orcamento':
                if (! is_array($input['itens'])) {
                    return ['erro' => 'itens deve ser uma lista'];
                }
                if (count($input['itens']) > 15) {
                    return ['erro' => 'Máximo de 15 itens.'];
                }
                $linhas = [];
                $erros = [];
                foreach ($input['itens'] as $it) {
                    $r = Catalogo::precificar($it);
                    isset($r['erro']) ? $erros[] = $r['erro'] : $linhas[] = $r['linha'];
                }
                if ($erros) {
                    return ['erro' => implode(' ', $erros)];
                }
                $ctx['estado']['orcamento'] = $linhas;
                $ctx['estado']['pedido'] = null;   // o orçamento mudou: um pedido anterior deixa de corresponder
                $r = self::resumoOrcamento($linhas);
                if (isset($ctx['aoAccao'])) {
                    // para o carrinho do site vão as linhas originais (totais numéricos), não as formatadas
                    ($ctx['aoAccao'])(['tipo' => 'orcamento', 'itens' => $linhas, 'subtotal' => $r['subtotal'], 'iva' => $r['iva'], 'total_estimado' => $r['total_estimado']]);
                }

                return $r;

            case 'registar_pedido':
                if (! $ctx['estado']['orcamento']) {
                    return ['erro' => 'O orçamento está vazio. Use definir_orcamento primeiro.'];
                }
                if (($input['cliente_confirmou'] ?? null) !== true) {
                    return ['erro' => 'Mostre o resumo ao cliente e peça confirmação antes de registar.'];
                }
                $tel = preg_replace('/[^\d+]/', '', (string) ($input['telefone'] ?? ''));
                if (strlen(preg_replace('/\D/', '', $tel)) < 9) {
                    return ['erro' => 'Telefone inválido — peça um número com pelo menos 9 dígitos.'];
                }
                $email = trim((string) ($input['email'] ?? ''));
                if ($email !== '' && ! preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $email)) {
                    return ['erro' => 'E-mail inválido — confirme com o cliente.'];
                }
                $nif = trim((string) ($input['nif'] ?? ''));
                if (($input['tipo_cliente'] ?? '') === 'Empresa' && ! preg_match('/^[0-9A-Z]{9,14}$/i', $nif)) {
                    return ['erro' => 'Para empresa o NIF é obrigatório (9 a 14 caracteres) — peça-o ao cliente.'];
                }
                if ($ctx['estado']['pedido']) {
                    return ['numero' => $ctx['estado']['pedido']['numero'], 'nota' => 'Este pedido já estava registado.'];
                }

                $numero = Catalogo::numeroPedido();
                $cliente = [
                    'nome' => mb_substr(trim((string) $input['nome']), 0, 120),
                    'tipo' => $input['tipo_cliente'] === 'Empresa' ? 'Empresa' : 'Particular',
                    'telefone' => $tel, 'email' => $email, 'nif' => $nif,
                    'morada' => mb_substr(trim((string) ($input['morada'] ?? '')), 0, 200),
                ];
                $metodo = (string) $input['metodo_pagamento'];
                $texto = Catalogo::textoPedido($numero, $cliente, $ctx['estado']['orcamento'], $metodo, mb_substr((string) ($input['observacoes'] ?? ''), 0, 500), $canal);
                $pedido = [
                    'numero' => $numero, 'canal' => $canal, 'sessao' => $ctx['sessao'] ?? null, 'cliente' => $cliente,
                    'itens' => $ctx['estado']['orcamento'], 'metodo' => $metodo, 'totais' => Catalogo::totais($ctx['estado']['orcamento']), 'texto' => $texto,
                ];
                $ctx['estado']['pedido'] = ['numero' => $numero];
                isset($ctx['guardarPedido']) && ($ctx['guardarPedido'])($pedido);
                isset($ctx['avisarEquipa']) && ($ctx['avisarEquipa'])(['tipo' => 'pedido', 'numero' => $numero, 'texto' => $texto, 'canal' => $canal, 'contacto' => $tel, 'whatsapp' => $ctx['contacto'] ?? null]);
                isset($ctx['aoAccao']) && ($ctx['aoAccao'])(['tipo' => 'pedido', 'numero' => $numero, 'texto' => $texto]);

                return [
                    'numero' => $numero,
                    'proximos_passos' => $canal === 'whatsapp'
                        ? 'Pedido registado e equipa avisada. Diga o número ao cliente e explique: confirmamos a disponibilidade, enviamos a factura proforma e activamos após o pagamento.'
                        : "Pedido registado. O chat mostra agora um botão 'Enviar pedido pelo WhatsApp' — peça ao cliente para carregar nele para a equipa receber e enviar a proforma.",
                ];

            case 'chamar_humano':
                $ctx['estado']['humano'] = ['motivo' => $input['motivo'], 'desde' => Armazem::agoraMs()];
                $resumo = mb_substr((string) $input['resumo'], 0, 800);
                isset($ctx['avisarEquipa']) && ($ctx['avisarEquipa'])(['tipo' => 'humano', 'motivo' => $input['motivo'], 'texto' => $resumo, 'canal' => $canal, 'whatsapp' => $ctx['contacto'] ?? null]);
                isset($ctx['aoAccao']) && ($ctx['aoAccao'])(['tipo' => 'humano', 'texto' => "Olá {$empresa['nome']}! Vim do assistente do site. $resumo"]);
                if ($canal !== 'whatsapp') {
                    return ['ok' => true, 'nota' => 'O chat mostra um botão para falar com a equipa no WhatsApp.'];
                }

                return ! empty($ctx['pausaHumano'])
                    ? ['ok' => true, 'nota' => 'Equipa avisada. Diga ao cliente que uma pessoa da equipa continua esta conversa aqui em breve (no horário de atendimento); o assistente fica em pausa.']
                    : ['ok' => true, 'nota' => "Equipa avisada com o contacto do cliente. Diga-lhe que a equipa o vai contactar em breve (no horário de atendimento) e que, se preferir, pode falar já com uma pessoa em wa.me/{$empresa['whatsapp']}."];
        }

        return ['erro' => 'ferramenta desconhecida'];
    }

    /**
     * O histórico guardado em JSON perde a distinção entre {} e []: o campo "input" de um
     * tool_use tem de ser sempre um objecto para a API o aceitar.
     */
    private static function prepararMensagens(array $mensagens): array
    {
        foreach ($mensagens as &$m) {
            if (is_array($m['content'] ?? null)) {
                foreach ($m['content'] as &$b) {
                    if (($b['type'] ?? '') === 'tool_use' && empty($b['input'])) {
                        $b['input'] = new \stdClass;
                    }
                }
                unset($b);
            }
        }
        unset($m);

        return $mensagens;
    }

    private static function chamarApi(string $canal, array $mensagens): array
    {
        $r = Http::withHeaders([
            'x-api-key' => (string) config('ecomulti.agente.chave_api'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(55)->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
            ->post(rtrim(config('ecomulti.agente.api_url'), '/').'/v1/messages', [
                'model' => config('ecomulti.agente.modelo'),
                'max_tokens' => 4096,
                'system' => [['type' => 'text', 'text' => Prompt::instrucoes($canal), 'cache_control' => ['type' => 'ephemeral']]],
                'tools' => self::ferramentas(),
                'messages' => self::prepararMensagens($mensagens),
            ]);

        if (! $r->successful()) {
            throw new \RuntimeException('API do assistente '.$r->status().': '.mb_substr($r->body(), 0, 300));
        }

        return $r->json();
    }

    /**
     * Um turno: junta a mensagem do cliente, conversa com o Claude (com as ferramentas) até haver
     * resposta final e devolve o texto. $ctx['estado'] é actualizado por referência; o chamador só
     * deve gravá-lo se a função terminar sem erro (o histórico nunca fica com um tool_use sem resposta).
     * $ctx['aoTexto'](string) é chamado com cada pedaço de texto assim que existe.
     */
    public static function executarTurno(string $texto, array &$ctx): string
    {
        $canal = $ctx['canal'];
        $ctx['estado']['mensagens'][] = ['role' => 'user', 'content' => $texto];
        $ctx['estado']['turnos']++;
        $partes = [];
        $max = (int) config('ecomulti.agente.max_voltas', 8);

        for ($volta = 0; $volta < $max; $volta++) {
            $msg = self::chamarApi($canal, $ctx['estado']['mensagens']);
            $conteudo = $msg['content'] ?? [];
            $ctx['estado']['mensagens'][] = ['role' => 'assistant', 'content' => $conteudo];

            $textos = trim(implode('', array_map(fn ($b) => $b['text'], array_filter($conteudo, fn ($b) => ($b['type'] ?? '') === 'text'))));
            if ($textos !== '') {
                $partes[] = $textos;
                isset($ctx['aoTexto']) && ($ctx['aoTexto'])($textos);
            }
            $usos = array_values(array_filter($conteudo, fn ($b) => ($b['type'] ?? '') === 'tool_use'));
            $parou = $msg['stop_reason'] ?? '';

            if ($parou === 'pause_turn') {
                continue;
            }
            if ($parou === 'refusal') {
                if ($usos) {
                    $ctx['estado']['mensagens'][] = ['role' => 'user', 'content' => array_map(fn ($u) => ['type' => 'tool_result', 'tool_use_id' => $u['id'], 'is_error' => true, 'content' => 'interrompido'], $usos)];
                }
                if ($textos === '') {
                    $aviso = 'Peço desculpa, não consigo ajudar com isso por aqui. Posso pô-lo em contacto com a nossa equipa?';
                    $partes[] = $aviso;
                    isset($ctx['aoTexto']) && ($ctx['aoTexto'])($aviso);
                }
                break;
            }
            if ($parou !== 'tool_use' || ! $usos) {
                // ex.: max_tokens a meio de uma ferramenta — não executar, mas fechar os tool_use
                if ($usos) {
                    $ctx['estado']['mensagens'][] = ['role' => 'user', 'content' => array_map(fn ($u) => ['type' => 'tool_result', 'tool_use_id' => $u['id'], 'is_error' => true, 'content' => 'interrompido'], $usos)];
                }
                break;
            }

            $resultados = [];
            foreach ($usos as $u) {
                $problema = self::validar($u['name'], $u['input'] ?? null);
                if ($problema) {
                    $resultados[] = ['type' => 'tool_result', 'tool_use_id' => $u['id'], 'is_error' => true, 'content' => json_encode(['erro' => $problema], JSON_UNESCAPED_UNICODE)];

                    continue;
                }
                try {
                    $r = self::executar($u['name'], $u['input'], $ctx);
                    $resultados[] = ['type' => 'tool_result', 'tool_use_id' => $u['id'], 'is_error' => ! empty($r['erro']), 'content' => json_encode($r, JSON_UNESCAPED_UNICODE)];
                } catch (\Throwable $e) {
                    report($e);
                    $resultados[] = ['type' => 'tool_result', 'tool_use_id' => $u['id'], 'is_error' => true, 'content' => json_encode(['erro' => 'falha interna: '.$e->getMessage()], JSON_UNESCAPED_UNICODE)];
                }
            }
            $ctx['estado']['mensagens'][] = ['role' => 'user', 'content' => $resultados];
        }

        return implode("\n\n", $partes);
    }
}
