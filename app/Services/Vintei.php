<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * API de revenda da 20i (https://api.20i.com). A 20i só aceita pedidos de IPs autorizados no My20i.
 * Dois modos:
 *  • Directo: VINTEI_API_KEY (a "General API Key"; o IP do servidor tem de estar autorizado).
 *  • Via proxy PHP com IP fixo (ferramentas/proxy-20i/proxy-20i.php): VINTEI_URL (termina em "?p=") + VINTEI_PROXY_CHAVE.
 */
class Vintei
{
    private static function chave(): string
    {
        return trim((string) config('ecomulti.vintei.chave'));
    }

    private static function chaveProxy(): string
    {
        return trim((string) config('ecomulti.vintei.proxy_chave'));
    }

    public static function configurado(): bool
    {
        return self::chave() !== '' || ((string) config('ecomulti.vintei.url') !== '' && self::chaveProxy() !== '');
    }

    private static function base(): string
    {
        return rtrim((string) (config('ecomulti.vintei.url') ?: 'https://api.20i.com'), '/');
    }

    public static function pedir(string $caminho, string $metodo = 'GET', ?array $corpo = null, int $timeout = 10): mixed
    {
        if (! self::configurado()) {
            throw new \RuntimeException('20i: defina VINTEI_API_KEY, ou VINTEI_URL + VINTEI_PROXY_CHAVE');
        }
        $cab = ['Accept' => 'application/json'];
        if (self::chave() !== '') {
            $cab['Authorization'] = 'Bearer '.base64_encode(self::chave());
        }
        if (self::chaveProxy() !== '') {
            $cab['X-Proxy-Chave'] = self::chaveProxy();
        }
        $req = Http::withHeaders($cab)->timeout($timeout);
        $url = self::base().$caminho;
        $r = $corpo !== null ? $req->asJson()->send($metodo, $url, ['json' => $corpo]) : $req->send($metodo, $url);
        $j = $r->json() ?? $r->body();
        if (! $r->successful()) {
            $msg = is_array($j) ? ($j['error']['message'] ?? $j['message'] ?? $j['error'] ?? null) : null;
            $msg ??= $r->status() === 401 ? 'chave inválida ou IP não autorizado' : 'erro';
            throw new \RuntimeException('20i '.$r->status().': '.(is_string($msg) ? $msg : json_encode($msg)));
        }

        return $j === '' ? null : $j;
    }

    /** dados da conta de revenda — útil para testar a ligação */
    public static function contaRevenda(): mixed
    {
        return self::pedir('/reseller');
    }

    /** tipos de pacote de alojamento da conta de revenda (os planos criados no My20i) */
    public static function tiposPacote(): array
    {
        $r = self::pedir('/reseller/*/packageTypes');
        $lista = is_array($r) ? (array_is_list($r) ? $r : (isset($r['result']) && is_array($r['result']) ? $r['result'] : array_values($r))) : [];

        return array_values(array_map(fn ($t) => [
            'id' => (string) $t['id'], 'nome' => (string) ($t['label'] ?? $t['name'] ?? 'Pacote '.$t['id']),
            'plataforma' => (string) ($t['platform'] ?? $t['type'] ?? ''),
        ], array_filter($lista, fn ($t) => is_array($t) && isset($t['id']))));
    }

    /** cria um pacote de alojamento na 20i; devolve o ID do pacote */
    public static function criarHospedagem(string $tipo, string $dominio): string
    {
        $r = self::pedir('/reseller/*/addWeb', 'POST', ['type' => $tipo, 'domain_name' => $dominio, 'extra_domain_names' => []], 30);
        $id = is_array($r) ? ($r['result'] ?? $r['id'] ?? $r['package_id'] ?? null) : $r;
        if ($id === null || $id === false || is_array($id)) {
            throw new \RuntimeException('20i: resposta sem o número do pacote ('.mb_substr(json_encode($r), 0, 200).')');
        }

        return (string) $id;
    }

    /** telefone no formato dos registos de domínios (EPP): +244.923000000 */
    public static function telefoneEPP(?string $tel, string $indicativo = '244'): string
    {
        $bruto = trim((string) $tel);
        if (preg_match('/^\+\d{1,3}\.\d{4,}$/', $bruto)) {
            return $bruto;
        }
        $d = preg_replace('/[^\d+]/', '', $bruto);
        if (str_starts_with($d, '+')) {
            $d = substr($d, 1);
        } elseif (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        } elseif (strlen($d) <= 9) {
            $d = $indicativo.$d;
        }
        $dois = explode(' ', '20 27 30 31 32 33 34 36 39 40 41 43 44 45 46 47 48 49 51 52 53 54 55 56 57 58 60 61 62 63 64 65 66 81 82 84 86 90 91 92 93 94 95 98');
        $ind = str_starts_with($d, $indicativo) ? $indicativo
            : (in_array($d[0] ?? '', ['1', '7'], true) ? $d[0] : (in_array(substr($d, 0, 2), $dois, true) ? substr($d, 0, 2) : substr($d, 0, 3)));

        return '+'.$ind.'.'.substr($d, strlen($ind));
    }

    /** regista um domínio na 20i (COMPRA REAL, cobrada no saldo da revenda e irreversível) */
    public static function registarDominio(string $dominio, int $anos, array $c): string
    {
        $corpo = [
            'name' => $dominio, 'years' => $anos,
            'contact' => [
                'name' => $c['nome'] ?? '', 'organisation' => $c['organizacao'] ?? '', 'address' => $c['morada'] ?? '', 'city' => $c['cidade'] ?? '',
                'sp' => ($c['provincia'] ?? '') ?: ($c['cidade'] ?? ''), 'pc' => ($c['codigoPostal'] ?? '') ?: '0000',
                'cc' => strtoupper($c['pais'] ?? 'AO'), 'telephone' => self::telefoneEPP($c['telefone'] ?? ''), 'email' => $c['email'] ?? '',
            ],
        ];
        $r = self::pedir('/reseller/*/addDomain', 'POST', $corpo, 60);
        $id = is_array($r) ? ($r['result'] ?? $r['id'] ?? $r['domain_id'] ?? null) : $r;
        if ($id === null || $id === false || $id === []) {
            throw new \RuntimeException('20i: resposta sem confirmação do registo ('.mb_substr(json_encode($r), 0, 200).')');
        }

        return is_array($id) ? mb_substr(json_encode($id), 0, 80) : (string) $id;
    }

    /** disponibilidade pela pesquisa da 20i: "free", "taken" ou null (sem informação) */
    public static function disponibilidade(string $dominio): ?string
    {
        $dom = strtolower($dominio);
        $r = self::pedir('/domain-search/'.rawurlencode($dom), 'GET', null, 8);
        $lista = is_array($r) ? (array_is_list($r) ? $r : (isset($r['results']) && is_array($r['results']) ? $r['results'] : [$r])) : [];
        foreach ($lista as $x) {
            if (is_array($x) && strtolower((string) ($x['name'] ?? $x['domain'] ?? '')) === $dom) {
                if (($x['available'] ?? null) === true || ($x['can'] ?? '') === 'register') {
                    return 'free';
                }
                if (($x['available'] ?? null) === false || preg_match('/transfer|renew|taken|unavailable/i', (string) ($x['can'] ?? ''))) {
                    return 'taken';
                }

                return null;
            }
        }

        return null;
    }
}
