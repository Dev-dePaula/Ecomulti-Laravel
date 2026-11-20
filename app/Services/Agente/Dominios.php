<?php

namespace App\Services\Agente;

use Illuminate\Support\Facades\Http;

/**
 * Verificação de domínios no servidor (mesma lógica de assets/js/dominio.js).
 * .com/.net/.org → RDAP oficial; .ao/.co.ao → DNS público (indicativo).
 */
class Dominios
{
    private const RDAP = [
        '.com' => 'https://rdap.verisign.com/com/v1/domain/',
        '.net' => 'https://rdap.verisign.com/net/v1/domain/',
        '.org' => 'https://rdap.publicinterestregistry.org/rdap/domain/',
    ];

    public const ESTADOS = [
        'free' => 'disponível',
        'probable' => 'provavelmente disponível (confirmação final pela equipa)',
        'taken' => 'já registado',
        'unknown' => 'não foi possível confirmar — a equipa confirma',
        'error' => 'não foi possível confirmar — a equipa confirma',
    ];

    private static function viaRdap(string $dom, string $ext): string
    {
        $r = Http::timeout(8)->withHeaders(['Accept' => 'application/rdap+json'])->get(self::RDAP[$ext].rawurlencode($dom));
        if ($r->status() === 404) {
            return 'free';
        }
        if ($r->successful()) {
            return 'taken';
        }
        throw new \RuntimeException('rdap '.$r->status());
    }

    private static function viaDns(string $dom): string
    {
        $r = Http::timeout(8)->withHeaders(['Accept' => 'application/dns-json'])
            ->get('https://cloudflare-dns.com/dns-query', ['name' => $dom, 'type' => 'NS']);
        if (! $r->successful()) {
            throw new \RuntimeException('dns '.$r->status());
        }
        $j = $r->json();
        if (($j['Status'] ?? null) === 3) {
            return 'probable';
        }
        if (! empty($j['Answer'])) {
            return 'taken';
        }

        return 'unknown';
    }

    public static function verificar(string $nome, string $ext): array
    {
        $dom = $nome.$ext;
        try {
            if (isset(self::RDAP[$ext])) {
                try {
                    $estado = self::viaRdap($dom, $ext);
                } catch (\Throwable) {
                    $estado = self::viaDns($dom);
                }
            } else {
                $estado = self::viaDns($dom);
            }
        } catch (\Throwable) {
            $estado = 'error';
        }

        return ['dominio' => $dom, 'estado' => $estado, 'situacao' => self::ESTADOS[$estado], 'preco_ano_kz' => Catalogo::preco('dominio'.$ext)];
    }
}
