<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\MensagemPedido;
use App\Models\Cliente;
use App\Models\Lead;
use App\Models\Venda;
use App\Services\AdminAuth;
use App\Exceptions\ErroApi;
use App\Services\Agente\Catalogo;
use App\Services\Cliente\Subscricoes;
use App\Services\Vintei;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * POST /api/admin   corpo: { acao: "...", ...dados }
 * Só "login" é público; as restantes acções exigem a sessão (Authorization: Bearer <token>).
 */
class AdminController extends Controller
{
    private const ESTADOS_VENDA = ['em_aberto', 'pago', 'cancelado'];

    private const ESTADOS_LEAD = ['novo', 'contactado', 'convertido', 'descartado'];

    private const METODOS = ['Multicaixa Express', 'Referência', 'Transferência', 'Numerário', 'Outro'];

    private const EMAIL = '/^[^\s@]{1,64}@[^\s@]{1,190}\.[^\s@]{2,}$/';

    private static function txt(mixed $v, int $max): string
    {
        return PedidoController::txt($v, $max);
    }

    private static function erro(string $m, int $estado = 400): never
    {
        throw new class($m, $estado) extends \RuntimeException
        {
            public function __construct(string $m, public int $estado)
            {
                parent::__construct($m);
            }
        };
    }

    private static function idSeguro(mixed $s): bool
    {
        return is_string($s) && preg_match('/^[A-Za-z0-9_-]{3,40}$/', $s);
    }

    private static function digitos(?string $s): string
    {
        return preg_replace('/\D/', '', (string) $s);
    }

    private static function data(mixed $v, ?Carbon $def = null): Carbon
    {
        try {
            return $v ? Carbon::parse($v) : ($def ?? Carbon::now());
        } catch (\Throwable) {
            return $def ?? Carbon::now();
        }
    }

    public function __invoke(Request $request): JsonResponse
    {
        $d = $request->json()->all();
        $acao = (string) ($d['acao'] ?? '');

        try {
            if ($acao === 'login') {
                $ip = (string) $request->ip();
                if (AdminAuth::bloqueado($ip)) {
                    return response()->json(['erro' => 'Demasiadas tentativas falhadas. Aguarde 15 minutos e tente novamente.'], 429);
                }
                $u = AdminAuth::verificarLogin($d['email'] ?? '', $d['senha'] ?? '');
                if (! $u) {
                    AdminAuth::registarFalha($ip);

                    return response()->json(['erro' => 'E-mail ou senha incorretos.'], 401);
                }
                AdminAuth::limparFalhas($ip);

                return response()->json(['ok' => true, 'email' => $u->email, 'senhaInicial' => (bool) $u->senha_inicial] + AdminAuth::emitirToken($u), 200, ['Cache-Control' => 'no-store']);
            }

            $sessao = AdminAuth::validarToken((string) $request->bearerToken());
            if (! $sessao) {
                return response()->json(['erro' => 'Sessão expirada. Entre novamente.'], 401);
            }
            $metodo = match ($acao) {
                'sessao' => 'sessao', 'senha' => 'senha', 'resumo' => 'resumo',
                'vendas.listar' => 'vendasListar', 'vendas.guardar' => 'vendasGuardar', 'vendas.estado' => 'vendasEstado', 'vendas.apagar' => 'vendasApagar',
                'clientes.listar' => 'clientesListar', 'clientes.guardar' => 'clientesGuardar', 'clientes.apagar' => 'clientesApagar',
                'leads.listar' => 'leadsListar', 'leads.criar' => 'leadsCriar', 'leads.actualizar' => 'leadsActualizar', 'leads.apagar' => 'leadsApagar', 'leads.converter' => 'leadsConverter',
                'subs.listar' => 'subsListar', 'subs.guardar' => 'subsGuardar',
                'vintei.tipos' => 'vinteiTipos', 'vintei.activar' => 'vinteiActivar', 'vintei.dominio' => 'vinteiDominio',
                default => null,
            };
            if (! $metodo) {
                return response()->json(['erro' => 'acção desconhecida'], 400);
            }

            return response()->json($this->$metodo($d, $sessao), 200, ['Cache-Control' => 'no-store']);
        } catch (\RuntimeException $e) {
            if (property_exists($e, 'estado')) {
                return response()->json(['erro' => $e->getMessage()], $e->estado);
            }
            report($e);

            return response()->json(['erro' => 'Erro interno. Tente novamente.'], 500);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['erro' => 'Erro interno. Tente novamente.'], 500);
        }
    }

    /* ----------------------------------------------------------------- conta */
    private function sessao(array $d, array $s): array
    {
        return ['ok' => true, 'email' => $s['email'], 'senhaInicial' => $s['senhaInicial']];
    }

    private function senha(array $d, array $s): array
    {
        $nova = (string) ($d['nova'] ?? '');
        $actual = (string) ($d['actual'] ?? '');
        if (mb_strlen($nova) < 10) {
            self::erro('A nova senha deve ter pelo menos 10 caracteres.');
        }
        if (! preg_match('/[A-Za-z]/', $nova) || ! preg_match('/\d/', $nova)) {
            self::erro('Use letras e números na nova senha.');
        }
        $u = AdminAuth::verificarLogin($s['email'], $actual);
        if (! $u) {
            self::erro('A senha actual não está correta.', 403);
        }
        if ($nova === $actual) {
            self::erro('A nova senha deve ser diferente da actual.');
        }
        $u = AdminAuth::alterarSenha($u, $nova);

        return ['ok' => true] + AdminAuth::emitirToken($u);
    }

    /* ---------------------------------------------------------------- vendas */
    private static function limparCliente(mixed $c): array
    {
        $c = is_array($c) ? $c : [];
        $email = strtolower(self::txt($c['email'] ?? '', 254));

        return [
            'nome' => self::txt($c['nome'] ?? '', 120),
            'tipo' => ($c['tipo'] ?? '') === 'Empresa' ? 'Empresa' : 'Particular',
            'telefone' => self::txt($c['telefone'] ?? $c['tel'] ?? '', 30),
            'email' => $email !== '' && preg_match(self::EMAIL, $email) ? $email : '',
            'nif' => strtoupper(self::txt($c['nif'] ?? '', 20)),
            'morada' => self::txt($c['morada'] ?? '', 200),
        ];
    }

    private function resumo(): array
    {
        $vendas = Venda::all();
        $mes = Carbon::now()->format('Y-m');
        $semana = Carbon::now()->subDays(7);
        $pagas = $vendas->where('estado', 'pago');
        $abertas = $vendas->where('estado', 'em_aberto');
        $soma = fn ($l) => (float) $l->sum('total');
        $doMes = fn ($l, $m) => $l->filter(fn ($v) => $v->pago_em && $v->pago_em->format('Y-m') === $m);

        $serie = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = Carbon::now()->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m');
            $serie[] = ['mes' => $m, 'total' => $soma($doMes($pagas, $m))];
        }

        return [
            'mes' => ['total' => $soma($doMes($pagas, $mes)), 'n' => $doMes($pagas, $mes)->count()],
            'pagoTotal' => $soma($pagas), 'nPagas' => $pagas->count(),
            'aberto' => ['total' => $soma($abertas), 'n' => $abertas->count(), 'atrasadas' => $abertas->filter(fn ($v) => $v->data < $semana)->count()],
            'clientes' => Cliente::count(), 'leads' => Lead::count(), 'leadsNovos' => Lead::where('created_at', '>=', $semana)->count(),
            'serie' => $serie,
            'recentes' => $vendas->sortByDesc('data')->take(6)->map->paraApi()->values(),
        ];
    }

    private function vendasListar(): array
    {
        return ['vendas' => Venda::orderByDesc('data')->get()->map->paraApi()->values()];
    }

    private function vendasGuardar(array $d): array
    {
        $antigo = self::idSeguro($d['id'] ?? null) ? Venda::find($d['id']) : null;
        $cliente = self::limparCliente($d['cliente'] ?? []);
        if (mb_strlen($cliente['nome']) < 2) {
            self::erro('Indique o nome do cliente.');
        }
        $itens = [];
        foreach (array_slice(is_array($d['itens'] ?? null) ? $d['itens'] : [], 0, 30) as $i) {
            $desc = is_array($i) ? self::txt($i['descricao'] ?? '', 200) : '';
            if ($desc !== '') {
                $itens[] = ['descricao' => $desc, 'valor' => PedidoController::num($i['valor'] ?? 0)];
            }
        }
        $total = PedidoController::num($d['total'] ?? 0) ?: array_sum(array_column($itens, 'valor'));
        if (! $total) {
            self::erro('O valor total deve ser maior que zero.');
        }
        $estado = in_array($d['estado'] ?? '', self::ESTADOS_VENDA, true) ? $d['estado'] : 'em_aberto';
        $metodo = self::txt($d['metodo'] ?? '', 30);
        $id = $antigo?->id ?? (self::idSeguro($d['id'] ?? null) ? $d['id'] : Catalogo::numeroPedido());

        $pagoEm = null;
        if ($estado === 'pago') {
            $pagoEm = $antigo?->pago_em ?? self::data($d['pagoEm'] ?? null);
            if (! empty($d['pagoEm']) && strtotime((string) $d['pagoEm'])) {
                $pagoEm = self::data($d['pagoEm']);
            }
        }

        $venda = Venda::updateOrCreate(['id' => $id], [
            'data' => self::data($d['data'] ?? null, $antigo?->data),
            'cliente' => $cliente, 'cliente_id' => $antigo?->cliente_id ?? '',
            'itens' => $itens, 'total' => $total, 'metodo' => $metodo,
            'estado' => $estado, 'pago_em' => $pagoEm,
            'canal' => self::txt($d['canal'] ?? '', 30) ?: ($antigo?->canal ?? 'manual'),
            'notas' => self::txt($d['notas'] ?? '', 600),
        ]);
        if ($estado === 'pago') {
            $venda->update(['cliente_id' => $this->garantirCliente($cliente)]);
        }
        Subscricoes::criarDaVenda($venda->fresh());   // venda paga → subscrições na área do cliente

        return ['ok' => true, 'venda' => $venda->fresh()->paraApi()];
    }

    private function vendasEstado(array $d): array
    {
        if (! self::idSeguro($d['id'] ?? null) || ! in_array($d['estado'] ?? '', self::ESTADOS_VENDA, true)) {
            self::erro('Pedido inválido.');
        }
        $v = Venda::find($d['id']) ?? self::erro('Venda não encontrada.', 404);
        $v->estado = $d['estado'];
        if (! empty($d['metodo'])) {
            $v->metodo = self::txt($d['metodo'], 30);
        }
        if ($d['estado'] === 'pago') {
            $v->pago_em = self::data($d['pagoEm'] ?? null);
            $v->cliente_id = $this->garantirCliente($v->cliente);
        } else {
            $v->pago_em = null;
        }
        $v->save();
        Subscricoes::criarDaVenda($v);   // venda paga → subscrições na área do cliente

        // pagamento confirmado → e-mail ao cliente com credenciais e tempo de subscrição
        $email = '';
        if ($d['estado'] === 'pago' && ($d['enviarEmail'] ?? true) !== false) {
            if (empty($v->cliente['email'])) {
                $email = 'o cliente não tem e-mail registado';
            } else {
                try {
                    Mail::to($v->cliente['email'])->bcc(config('ecomulti.email_equipa'))->send(new MensagemPedido($v, 'confirmacao', self::txt($d['credenciais'] ?? '', 2000)));
                    $email = 'enviado ao cliente';
                } catch (\Throwable $e) {
                    $email = 'não enviado ('.$e->getMessage().')';
                }
            }
        }

        return ['ok' => true, 'venda' => $v->fresh()->paraApi(), 'email' => $email];
    }

    private function vendasApagar(array $d): array
    {
        if (! self::idSeguro($d['id'] ?? null)) {
            self::erro('Pedido inválido.');
        }
        Venda::where('id', $d['id'])->delete();

        return ['ok' => true];
    }

    /* ----------------------------------------------- subscrições (área do cliente) */
    private function subsListar(): array
    {
        return ['subscricoes' => Subscricoes::listarTodas()];
    }

    private function subsGuardar(array $d): array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{3,50}$/', (string) ($d['id'] ?? ''))) {
            self::erro('Pedido inválido.');
        }

        return ['ok' => true, 'subscricao' => Subscricoes::guardar($d)];
    }

    /* ------------------------------------------------------------------- 20i */
    private function exigirVintei(): void
    {
        if (! Vintei::configurado()) {
            self::erro('A ligação à 20i não está configurada no servidor (variáveis VINTEI_API_KEY, ou VINTEI_URL e VINTEI_PROXY_CHAVE, no .env).', 503);
        }
    }

    private static function limparDominioPedido(mixed $v): string
    {
        $d = strtolower(self::txt($v, 253));
        $d = preg_replace('#^https?://#', '', $d);
        $d = preg_replace('#^www\.#', '', $d);

        return preg_replace('#/.*$#', '', $d);
    }

    private function vinteiTipos(): array
    {
        $this->exigirVintei();
        try {
            return ['tipos' => Vintei::tiposPacote()];
        } catch (\Throwable $e) {
            self::erro('Não foi possível ler os pacotes da 20i: '.$e->getMessage(), 502);
        }
    }

    /** criar a hospedagem de uma venda paga (botão manual no painel) */
    private function vinteiActivar(array $d, array $s): array
    {
        $this->exigirVintei();
        if (! self::idSeguro($d['id'] ?? null)) {
            self::erro('Pedido inválido.');
        }
        $tipo = self::txt($d['tipo'] ?? '', 40);
        if (! preg_match('/^[A-Za-z0-9_-]{1,40}$/', $tipo)) {
            self::erro('Escolha o tipo de pacote da 20i.');
        }
        $dominio = self::limparDominioPedido($d['dominio'] ?? '');
        if (! Catalogo::dominioValido($dominio)) {
            self::erro('Indique um domínio válido (ex.: empresa.ao).');
        }
        $v = Venda::find($d['id']) ?? self::erro('Venda não encontrada.', 404);
        if ($v->estado !== 'pago') {
            self::erro('Só vendas pagas podem ser activadas na 20i.');
        }
        $act = $v->vintei ?? [];
        if (! empty($act['pacoteId'])) {
            self::erro("Esta venda já foi activada na 20i (pacote {$act['pacoteId']}).", 409);
        }
        if (! empty($act['aActivar']) && time() - strtotime($act['aActivar']) < 120) {
            self::erro('A activação desta venda já está em curso. Aguarde um momento.', 409);
        }

        $v->vintei = ['aActivar' => now()->toIso8601ZuluString(), 'tipo' => $tipo, 'dominio' => $dominio];   // trava contra duplo clique
        $v->save();
        try {
            $pacoteId = Vintei::criarHospedagem($tipo, $dominio);
            $v->vintei = ['pacoteId' => $pacoteId, 'tipo' => $tipo, 'tipoNome' => self::txt($d['tipoNome'] ?? '', 80), 'dominio' => $dominio,
                'em' => now()->toIso8601ZuluString(), 'por' => $s['email'] ?? ''];
        } catch (\Throwable $e) {
            $v->vintei = ['erro' => self::txt($e->getMessage(), 300), 'tipo' => $tipo, 'dominio' => $dominio, 'em' => now()->toIso8601ZuluString()];
            $v->save();
            self::erro('A 20i não criou a hospedagem: '.$e->getMessage(), 502);
        }
        $v->save();

        return ['ok' => true, 'venda' => $v->fresh()->paraApi()];
    }

    /** registar um domínio de uma venda paga (COMPRA REAL, irreversível) */
    private function vinteiDominio(array $d, array $s): array
    {
        $this->exigirVintei();
        if (! self::idSeguro($d['id'] ?? null)) {
            self::erro('Pedido inválido.');
        }
        $dominio = self::limparDominioPedido($d['dominio'] ?? '');
        if (! Catalogo::dominioValido($dominio)) {
            self::erro('Indique um domínio válido (ex.: empresa.com).');
        }
        if (preg_match('/\.ao$/', $dominio)) {
            self::erro('Os domínios .ao não são registados pela 20i: o registo é feito à parte, como até agora.');
        }
        $anos = (int) round((float) ($d['anos'] ?? 0));
        if ($anos < 1 || $anos > 10) {
            self::erro('Escolha entre 1 e 10 anos.');
        }
        if (($d['confirmo'] ?? null) !== true) {
            self::erro('Confirme que sabe que o registo é uma compra real na 20i.');
        }
        $c = is_array($d['contacto'] ?? null) ? $d['contacto'] : [];
        $contacto = [
            'nome' => self::txt($c['nome'] ?? '', 120), 'organizacao' => self::txt($c['organizacao'] ?? '', 120),
            'email' => strtolower(self::txt($c['email'] ?? '', 254)), 'telefone' => self::txt($c['telefone'] ?? '', 30),
            'morada' => self::txt($c['morada'] ?? '', 200), 'cidade' => self::txt($c['cidade'] ?? '', 80),
            'provincia' => self::txt($c['provincia'] ?? '', 80), 'codigoPostal' => self::txt($c['codigoPostal'] ?? '', 20),
            'pais' => strtoupper(self::txt($c['pais'] ?? 'AO', 2) ?: 'AO'),
        ];
        if (mb_strlen($contacto['nome']) < 2) {
            self::erro('Indique o nome do titular do domínio.');
        }
        if (! preg_match(self::EMAIL, $contacto['email'])) {
            self::erro('Indique um e-mail válido do titular (recebe os avisos do registo).');
        }
        if (strlen(self::digitos($contacto['telefone'])) < 9) {
            self::erro('Indique o telefone do titular.');
        }
        if (mb_strlen($contacto['morada']) < 3 || mb_strlen($contacto['cidade']) < 2) {
            self::erro('Indique a morada e a cidade do titular.');
        }
        if (! preg_match('/^[A-Z]{2}$/', $contacto['pais'])) {
            self::erro('País inválido (código de 2 letras, ex.: AO).');
        }

        $v = Venda::find($d['id']) ?? self::erro('Venda não encontrada.', 404);
        if ($v->estado !== 'pago') {
            self::erro('Só vendas pagas podem registar domínios na 20i.');
        }
        $registos = is_array($v->vintei_dominios) ? $v->vintei_dominios : [];
        foreach ($registos as $r) {
            if (($r['dominio'] ?? '') === $dominio && ! empty($r['registoId'])) {
                self::erro("O domínio {$dominio} já foi registado nesta venda.", 409);
            }
            if (($r['dominio'] ?? '') === $dominio && ! empty($r['aRegistar']) && time() - strtotime($r['aRegistar']) < 180) {
                self::erro('O registo deste domínio já está em curso. Aguarde um momento.', 409);
            }
        }

        // confirma na 20i, imediatamente antes de comprar, que o domínio está livre
        try {
            $disp = Vintei::disponibilidade($dominio);
        } catch (\Throwable $e) {
            self::erro('Não foi possível confirmar a disponibilidade na 20i: '.$e->getMessage(), 502);
        }
        if ($disp === 'taken') {
            self::erro("O domínio {$dominio} já está registado. Nada foi comprado.", 409);
        }
        if ($disp !== 'free') {
            self::erro("A 20i não confirma que {$dominio} pode ser registado (extensão não disponível na 20i?). Nada foi comprado.", 409);
        }

        $outros = array_values(array_filter($registos, fn ($r) => ($r['dominio'] ?? '') !== $dominio));
        $v->vintei_dominios = [...$outros, ['dominio' => $dominio, 'anos' => $anos, 'aRegistar' => now()->toIso8601ZuluString()]];   // trava contra duplo clique
        $v->save();
        try {
            $registoId = Vintei::registarDominio($dominio, $anos, $contacto);
            $v->vintei_dominios = [...$outros, ['dominio' => $dominio, 'anos' => $anos, 'registoId' => $registoId, 'titular' => $contacto['nome'],
                'em' => now()->toIso8601ZuluString(), 'por' => $s['email'] ?? '']];
        } catch (\Throwable $e) {
            $v->vintei_dominios = [...$outros, ['dominio' => $dominio, 'anos' => $anos, 'erro' => self::txt($e->getMessage(), 300), 'em' => now()->toIso8601ZuluString()]];
            $v->save();
            self::erro('A 20i não registou o domínio: '.$e->getMessage(), 502);
        }
        $v->save();
        try {
            Subscricoes::dominioRegistado($v->fresh(), $dominio, $anos);
        } catch (\Throwable $e) {
            report($e);
        }

        return ['ok' => true, 'venda' => $v->fresh()->paraApi()];
    }

    /* -------------------------------------------------------------- clientes */
    /** encontra (por e-mail, telefone ou NIF) ou cria o cliente a partir dos dados de uma venda */
    private function garantirCliente(array $dados): string
    {
        $tel = substr(self::digitos($dados['telefone'] ?? ''), -9);
        $achado = Cliente::all()->first(function (Cliente $c) use ($dados, $tel) {
            return (! empty($dados['email']) && $c->email === $dados['email'])
                || (strlen($tel) >= 9 && substr(self::digitos($c->telefone), -9) === $tel)
                || (! empty($dados['nif']) && $c->nif && $c->nif === $dados['nif']);
        });
        if ($achado) {
            foreach (['telefone', 'email', 'nif', 'morada'] as $k) {
                if (! $achado->$k && ! empty($dados[$k])) {
                    $achado->$k = $dados[$k];
                }
            }
            $achado->save();

            return $achado->id;
        }

        return $this->criarCliente($dados)->id;
    }

    private function criarCliente(array $d, ?Cliente $antigo = null): Cliente
    {
        $c = self::limparCliente($d);
        if (mb_strlen($c['nome']) < 2) {
            self::erro('Indique o nome do cliente.');
        }

        return Cliente::updateOrCreate(
            ['id' => $antigo?->id ?? 'c'.base_convert((string) (int) (microtime(true) * 1000), 10, 36).Str::lower(Str::random(4))],
            $c + ['notas' => self::txt($d['notas'] ?? '', 600)]
        );
    }

    private function clientesListar(): array
    {
        $vendas = Venda::all();
        $lista = Cliente::all()->map(function (Cliente $c) use ($vendas) {
            $pagas = $vendas->where('cliente_id', $c->id)->where('estado', 'pago');

            return $c->paraApi() + [
                'compras' => $pagas->count(),
                'totalComprado' => (float) $pagas->sum('total'),
                'ultimaCompra' => $pagas->max('pago_em')?->toIso8601ZuluString() ?? '',
                'emAberto' => $vendas->where('estado', 'em_aberto')->filter(fn ($v) => $v->cliente_id === $c->id || ($c->email && ($v->cliente['email'] ?? '') === $c->email))->count(),
            ];
        });

        return ['clientes' => $lista->sortByDesc(fn ($c) => $c['ultimaCompra'] ?: $c['criadoEm'])->values()];
    }

    private function clientesGuardar(array $d): array
    {
        $antigo = self::idSeguro($d['id'] ?? null) ? Cliente::find($d['id']) : null;

        return ['ok' => true, 'cliente' => $this->criarCliente($d, $antigo)->fresh()->paraApi()];
    }

    private function clientesApagar(array $d): array
    {
        if (! self::idSeguro($d['id'] ?? null)) {
            self::erro('Cliente inválido.');
        }
        Cliente::where('id', $d['id'])->delete();

        return ['ok' => true];
    }

    /* ----------------------------------------------------------------- leads */
    private function leadsListar(): array
    {
        return ['leads' => Lead::orderByDesc('created_at')->get()->map->paraApi()->values()];
    }

    private function leadsCriar(array $d): array
    {
        $email = strtolower(self::txt($d['email'] ?? '', 254));
        if (! preg_match(self::EMAIL, $email)) {
            self::erro('Indique um e-mail válido.');
        }
        if (Lead::where('email', $email)->exists()) {
            self::erro('Este e-mail já está na lista.');
        }
        $l = Lead::create([
            'email' => $email, 'nome' => self::txt($d['nome'] ?? '', 80), 'whatsapp' => self::txt($d['whatsapp'] ?? '', 20),
            'interesses' => [], 'origem' => 'manual', 'pagina' => '', 'consentimento' => false, 'estado' => 'novo', 'notas' => self::txt($d['notas'] ?? '', 600),
        ]);

        return ['ok' => true, 'lead' => $l->fresh()->paraApi()];
    }

    private function leadsActualizar(array $d): array
    {
        $l = Lead::where('email', strtolower(self::txt($d['email'] ?? '', 254)))->first() ?? self::erro('Lead não encontrado.', 404);
        if (in_array($d['estado'] ?? '', self::ESTADOS_LEAD, true)) {
            $l->estado = $d['estado'];
        }
        if (array_key_exists('notas', $d)) {
            $l->notas = self::txt($d['notas'], 600);
        }
        if (array_key_exists('nome', $d)) {
            $l->nome = self::txt($d['nome'], 80);
        }
        $l->save();

        return ['ok' => true, 'lead' => $l->fresh()->paraApi()];
    }

    private function leadsApagar(array $d): array
    {
        Lead::where('email', strtolower(self::txt($d['email'] ?? '', 254)))->delete();

        return ['ok' => true];
    }

    private function leadsConverter(array $d): array
    {
        $l = Lead::where('email', strtolower(self::txt($d['email'] ?? '', 254)))->first() ?? self::erro('Lead não encontrado.', 404);
        $id = $this->garantirCliente([
            'nome' => $l->nome ?: explode('@', $l->email)[0], 'email' => $l->email, 'telefone' => $l->whatsapp ?? '',
            'notas' => 'Convertido a partir da newsletter/leads.',
        ]);
        $l->update(['estado' => 'convertido']);

        return ['ok' => true, 'clienteId' => $id];
    }
}
