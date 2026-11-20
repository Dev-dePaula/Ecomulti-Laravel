<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Autenticação do dashboard administrativo.
 *  - O utilizador é o da tabela users (criado por "php artisan db:seed"); a senha fica em hash.
 *  - A sessão é um token assinado (HMAC com a APP_KEY) válido 8 horas; mudar a senha invalida todas as sessões.
 *  - Máximo de 5 tentativas falhadas por IP em 15 minutos.
 */
class AdminAuth
{
    private const DURACAO_S = 8 * 3600;

    private const JANELA_S = 15 * 60;

    private const MAX_FALHAS = 5;

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
        return hash('sha256', 'admin-token|'.config('app.key'), true);
    }

    /** devolve o utilizador se o e-mail e a senha estiverem certos; senão null */
    public static function verificarLogin(?string $email, ?string $senha): ?User
    {
        $u = User::where('email', strtolower(trim((string) $email)))->first();
        // compara sempre um hash (tempo de resposta semelhante com utilizador inexistente)
        $ok = Hash::check((string) $senha, $u?->password ?? Hash::make('x'));

        return $u && $ok ? $u : null;
    }

    /** @return array{token: string, expira: int} expira em ms desde 1970 */
    public static function emitirToken(User $u): array
    {
        $x = (time() + self::DURACAO_S) * 1000;
        $corpo = self::b64(json_encode(['e' => $u->email, 'x' => $x, 'v' => $u->token_version]));
        $sig = self::b64(hash_hmac('sha256', $corpo, self::segredo(), true));

        return ['token' => "$corpo.$sig", 'expira' => $x];
    }

    /** @return array{email: string, senhaInicial: bool}|null */
    public static function validarToken(?string $token): ?array
    {
        $partes = explode('.', (string) $token);
        if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === '') {
            return null;
        }
        $esperado = hash_hmac('sha256', $partes[0], self::segredo(), true);
        $dada = self::b64d($partes[1]);
        if ($dada === false || ! hash_equals($esperado, $dada)) {
            return null;
        }
        $p = json_decode((string) self::b64d($partes[0]), true);
        if (! is_array($p) || ($p['x'] ?? 0) < time() * 1000) {
            return null;
        }
        $u = User::where('email', $p['e'] ?? '')->first();
        if (! $u || (int) $u->token_version !== (int) ($p['v'] ?? 0)) {
            return null;
        }

        return ['email' => $u->email, 'senhaInicial' => (bool) $u->senha_inicial];
    }

    public static function alterarSenha(User $u, string $nova): User
    {
        $u->forceFill([
            'password' => Hash::make($nova),
            'token_version' => $u->token_version + 1,
            'senha_inicial' => false,
        ])->save();

        return $u;
    }

    /* limite de tentativas falhadas por IP */
    private static function chaveIp(string $ip): string
    {
        return 'admin:falhas:'.substr(hash('sha256', $ip), 0, 24);
    }

    public static function bloqueado(string $ip): bool
    {
        return (int) Cache::get(self::chaveIp($ip), 0) >= self::MAX_FALHAS;
    }

    public static function registarFalha(string $ip): void
    {
        $k = self::chaveIp($ip);
        Cache::add($k, 0, self::JANELA_S);
        Cache::increment($k);
    }

    public static function limparFalhas(string $ip): void
    {
        Cache::forget(self::chaveIp($ip));
    }
}
