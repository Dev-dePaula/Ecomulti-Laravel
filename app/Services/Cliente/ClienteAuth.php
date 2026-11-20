<?php

namespace App\Services\Cliente;

use App\Exceptions\ErroApi;
use App\Models\ClienteCodigo;
use App\Models\ClienteConta;
use App\Services\Email;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Contas da área do cliente (separadas da conta do dashboard).
 *  - Senha em hash; e-mail confirmado por código de 6 dígitos (30 min, máx. 5 tentativas; só o hash do código é guardado).
 *  - Sessão: token assinado (HMAC) válido 12 h; mudar a senha invalida as sessões antigas.
 *  - As respostas nunca revelam se um e-mail já tem conta.
 */
class ClienteAuth
{
    private const SESSAO_S = 12 * 3600;

    private const CODIGO_MS = 30 * 60 * 1000;

    private const MAX_TENTATIVAS_CODIGO = 5;

    private const REENVIO_MS = 60 * 1000;

    private const EMAIL_RE = '/^[^\s@]{1,64}@[^\s@]{1,190}\.[^\s@]{2,}$/';

    public const RESPOSTA_CODIGO = ['ok' => true, 'mensagem' => 'Se os dados estiverem correctos, enviámos um código de 6 dígitos para o seu e-mail.'];

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64d(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }

    private static function segredo(): string
    {
        return hash('sha256', 'cliente-token|'.config('app.key'), true);
    }

    private static function agoraMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    public static function normalizarEmail(mixed $e): string
    {
        return mb_substr(strtolower(trim((string) $e)), 0, 254);
    }

    private static function validarSenha(mixed $s): string
    {
        $s = (string) $s;
        if (mb_strlen($s) < 8) {
            throw new ErroApi('A senha deve ter pelo menos 8 caracteres.');
        }
        if (mb_strlen($s) > 200) {
            throw new ErroApi('Senha demasiado longa.');
        }

        return $s;
    }

    /** limite por IP (registo, entrada, códigos) */
    public static function limitar(string $ip, string $acao, int $max, int $janelaS): void
    {
        $k = "cliente:lim:$acao:".substr(hash('sha256', $ip), 0, 24);
        Cache::add($k, 0, $janelaS);
        if ((int) Cache::get($k) >= $max) {
            throw new ErroApi('Demasiadas tentativas. Aguarde alguns minutos e tente novamente.', 429);
        }
        Cache::increment($k);
    }

    /* ---------- códigos de 6 dígitos ---------- */
    private static function hashCodigo(string $chave, string $codigo): string
    {
        return hash_hmac('sha256', $chave.'|'.$codigo, self::segredo());
    }

    private static function enviarCodigo(ClienteConta $conta, string $motivo): void
    {
        $chave = $motivo.':'.$conta->email;
        $antigo = ClienteCodigo::find($chave);
        if ($antigo && self::agoraMs() - (int) $antigo->enviado_em < self::REENVIO_MS) {
            return;   // evita enviar vários seguidos
        }
        $codigo = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        ClienteCodigo::updateOrCreate(['chave' => $chave], [
            'hash' => self::hashCodigo($chave, $codigo),
            'expira' => self::agoraMs() + self::CODIGO_MS,
            'tentativas' => 0,
            'enviado_em' => self::agoraMs(),
        ]);
        $m = Email::codigo((string) $conta->nome, $codigo, $motivo);
        Email::enviar($conta->email, $m['assunto'], $m['texto'], $m['html']);
    }

    private static function verificarCodigo(string $email, string $motivo, mixed $codigo): void
    {
        $chave = $motivo.':'.$email;
        $c = ClienteCodigo::find($chave);
        $invalido = fn () => new ErroApi('Código inválido ou expirado. Peça um novo código.');
        if (! $c || self::agoraMs() > (int) $c->expira || $c->tentativas >= self::MAX_TENTATIVAS_CODIGO) {
            throw $invalido();
        }
        $dado = preg_replace('/\D/', '', (string) $codigo);
        if (strlen($dado) !== 6 || ! hash_equals($c->hash, self::hashCodigo($chave, $dado))) {
            $c->increment('tentativas');
            throw $invalido();
        }
        $c->delete();
    }

    /* ---------- sessão ---------- */
    public static function emitirToken(ClienteConta $conta): array
    {
        $exp = time() + self::SESSAO_S;
        $corpo = self::b64(json_encode(['e' => $conta->email, 'x' => $exp, 'v' => $conta->versao]));

        return ['token' => $corpo.'.'.self::b64(hash_hmac('sha256', $corpo, self::segredo(), true)), 'expira' => $exp * 1000];
    }

    public static function validarSessao(?string $token): ?ClienteConta
    {
        $partes = explode('.', (string) $token);
        if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === '') {
            return null;
        }
        [$corpo, $sig] = $partes;
        if (! hash_equals(self::b64(hash_hmac('sha256', $corpo, self::segredo(), true)), $sig)) {
            return null;
        }
        $p = json_decode((string) self::b64d($corpo), true);
        if (! is_array($p) || empty($p['x']) || $p['x'] < time()) {
            return null;
        }
        $c = ClienteConta::find($p['e'] ?? '');

        return $c && $c->verificado && (int) $c->versao === (int) ($p['v'] ?? 0) ? $c : null;
    }

    public static function publico(ClienteConta $c): array
    {
        return ['email' => $c->email, 'nome' => $c->nome];
    }

    /* ---------- acções ---------- */
    public static function registar(array $d, string $ip): array
    {
        self::limitar($ip, 'registo', 10, 3600);
        $email = self::normalizarEmail($d['email'] ?? '');
        $nome = mb_substr(trim((string) ($d['nome'] ?? '')), 0, 120);
        if (mb_strlen($nome) < 2) {
            throw new ErroApi('Indique o seu nome.');
        }
        if (! preg_match(self::EMAIL_RE, $email)) {
            throw new ErroApi('Indique um e-mail válido.');
        }
        $senha = self::validarSenha($d['senha'] ?? '');
        $existente = ClienteConta::find($email);
        if ($existente?->verificado) {
            // já tem conta: não revela nada a quem está a registar; avisa o dono por e-mail
            try {
                Email::enviar($email, 'ECOMULTI — Já tem conta na área de cliente',
                    "Olá,\n\nAlguém tentou criar uma conta na área de cliente ECOMULTI com este e-mail, mas ele já tem conta.\nSe foi você e não se lembra da senha, use \"Esqueci-me da senha\" em https://www.ecomulti.ao/cliente.html\n\nECOMULTI");
            } catch (\Throwable) {
            }

            return self::RESPOSTA_CODIGO;
        }
        $conta = ClienteConta::updateOrCreate(['email' => $email], [
            'nome' => $nome, 'senha' => Hash::make($senha), 'versao' => 1, 'verificado' => false,
        ]);
        self::enviarCodigo($conta, 'confirmar');

        return self::RESPOSTA_CODIGO;
    }

    /** Confirmar exige o código (prova que tem o e-mail) E a senha escolhida no registo. */
    public static function confirmar(array $d, string $ip): array
    {
        self::limitar($ip, 'codigo', 20, 900);
        $email = self::normalizarEmail($d['email'] ?? '');
        $conta = ClienteConta::find($email);
        $invalido = fn () => new ErroApi('Código ou senha inválidos. Confirme a senha que escolheu ao criar a conta ou peça um novo código.');
        if (! $conta || $conta->verificado) {
            throw $invalido();
        }
        if (! Hash::check((string) ($d['senha'] ?? ''), $conta->senha)) {
            throw $invalido();
        }
        self::verificarCodigo($email, 'confirmar', $d['codigo'] ?? '');
        $conta->update(['verificado' => true, 'verificado_em' => now()]);

        return ['ok' => true, 'cliente' => self::publico($conta)] + self::emitirToken($conta);
    }

    public static function reenviar(array $d, string $ip): array
    {
        self::limitar($ip, 'reenvio', 10, 3600);
        $conta = ClienteConta::find(self::normalizarEmail($d['email'] ?? ''));
        if (($d['motivo'] ?? '') === 'recuperar') {
            if ($conta?->verificado) {
                self::enviarCodigo($conta, 'recuperar');
            }
        } elseif ($conta && ! $conta->verificado) {
            self::enviarCodigo($conta, 'confirmar');
        }

        return self::RESPOSTA_CODIGO;
    }

    public static function entrar(array $d, string $ip): array
    {
        self::limitar($ip, 'entrar', 10, 900);
        $conta = ClienteConta::find(self::normalizarEmail($d['email'] ?? ''));
        // tempo igual com ou sem conta
        $ok = Hash::check((string) ($d['senha'] ?? ''), $conta?->senha ?? Hash::make('sem-conta'));
        if (! $conta || ! $ok) {
            throw new ErroApi('E-mail ou senha incorrectos.', 401);
        }
        if (! $conta->verificado) {
            try {
                self::enviarCodigo($conta, 'confirmar');
            } catch (\Throwable) {
            }

            return ['ok' => false, 'confirmar' => true, 'mensagem' => 'Confirme primeiro o seu e-mail: enviámos um código de 6 dígitos.'];
        }

        return ['ok' => true, 'cliente' => self::publico($conta)] + self::emitirToken($conta);
    }

    public static function recuperar(array $d, string $ip): array
    {
        return self::reenviar(['email' => $d['email'] ?? '', 'motivo' => 'recuperar'], $ip);
    }

    public static function redefinir(array $d, string $ip): array
    {
        self::limitar($ip, 'codigo', 20, 900);
        $email = self::normalizarEmail($d['email'] ?? '');
        $senha = self::validarSenha($d['senha'] ?? '');
        $conta = ClienteConta::find($email);
        if (! $conta?->verificado) {
            throw new ErroApi('Código inválido ou expirado. Peça um novo código.');
        }
        self::verificarCodigo($email, 'recuperar', $d['codigo'] ?? '');
        $conta->update(['senha' => Hash::make($senha), 'versao' => ($conta->versao ?: 1) + 1]);

        return ['ok' => true, 'cliente' => self::publico($conta)] + self::emitirToken($conta);
    }
}
