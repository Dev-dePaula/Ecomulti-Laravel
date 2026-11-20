<?php

namespace App\Services\Agente;

use Illuminate\Support\Carbon;

/**
 * Catálogo e cálculo de preços do assistente.
 * Usa os MESMOS preços e regras das calculadoras do site: tudo vem de config/ecomulti.php.
 */
class Catalogo
{
    public static function empresa(): array
    {
        return config('ecomulti.empresa');
    }

    public static function fiscal(): array
    {
        return config('ecomulti.fiscal');
    }

    public static function preco(string $chave): float|int
    {
        return config('ecomulti.precos')[$chave];
    }

    public static function kz(float|int|null $v): string
    {
        return number_format((float) round($v ?? 0), 0, ',', '.').' Kz';
    }

    public static function extensoes(): array
    {
        return config('ecomulti.catalogo.extensoes');
    }

    public static function hospedagem(): array
    {
        return config('ecomulti.catalogo.hospedagem');
    }

    public static function email(): array
    {
        return config('ecomulti.catalogo.email');
    }

    public static function website(): array
    {
        return config('ecomulti.catalogo.website');
    }

    public static function extras(): array
    {
        return config('ecomulti.catalogo.extras');
    }

    private static function descontoAnos(int $anos): float|int
    {
        return match ($anos) {
            2 => self::preco('regra.desconto_2anos'),
            3 => self::preco('regra.desconto_3anos'),
            default => 0,
        };
    }

    /* ---------- validações ---------- */

    /** "www.exemplo.co.ao" => ['nome'=>'exemplo','ext'=>'.co.ao','dominio'=>'exemplo.co.ao'] ou null */
    public static function dividirDominio(?string $raw): ?array
    {
        $d = strtolower(trim((string) $raw));
        $d = preg_replace(['#^https?://#', '#^www\.#', '#/.*$#'], '', $d);

        $exts = self::extensoes();
        usort($exts, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($exts as $ext) {
            if (str_ends_with($d, $ext)) {
                $nome = substr($d, 0, -strlen($ext));
                if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $nome)) {
                    return ['nome' => $nome, 'ext' => $ext, 'dominio' => $nome.$ext];
                }

                return null;
            }
        }

        return null;
    }

    public static function dominioValido(?string $d): bool
    {
        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', (string) $d);
    }

    private static function limparDominio(?string $d): string
    {
        $d = strtolower(trim((string) $d));

        return preg_replace(['#^https?://#', '#^www\.#', '#/.*$#'], '', $d);
    }

    private static function inteiro(mixed $v, int $min, int $max, ?int $def): ?int
    {
        $n = is_int($v) ? $v : $def;

        return $n === null ? null : min($max, max($min, $n));
    }

    /**
     * Converte um item pedido pelo agente numa linha de orçamento, com o mesmo formato
     * do carrinho do site. Devolve ['linha'=>...] ou ['erro'=>'...'].
     */
    public static function precificar(mixed $item): array
    {
        $s = is_array($item) ? ($item['servico'] ?? null) : null;

        if ($s === 'dominio') {
            $d = self::dividirDominio($item['dominio'] ?? $item['plano'] ?? '');
            if (! $d) {
                return ['erro' => 'Domínio inválido: "'.($item['dominio'] ?? $item['plano'] ?? '').'". Extensões aceites: '.implode(', ', self::extensoes()).'.'];
            }
            $anos = self::inteiro($item['anos'] ?? null, 1, 3, 1);

            return ['linha' => [
                'servico' => 'dominio', 'servicoLabel' => 'Registo de domínio', 'icon' => 'globe', 'plano' => $d['dominio'],
                'detalhes' => ['Registo por '.$anos.' '.($anos === 1 ? 'ano' : 'anos'), 'Disponibilidade confirmada pela ECOMULTI antes do registo'],
                'total' => self::preco('dominio'.$d['ext']) * $anos,
            ]];
        }

        if ($s === 'hospedagem' || $s === 'email') {
            $tabela = $s === 'hospedagem' ? self::hospedagem() : self::email();
            $plano = $tabela[strtolower((string) ($item['plano'] ?? ''))] ?? null;
            if (! $plano) {
                return ['erro' => "Plano de $s inválido. Use: ".implode(', ', array_keys($tabela)).'.'];
            }
            $dominio = self::limparDominio($item['dominio'] ?? '');
            if (! self::dominioValido($dominio)) {
                return ['erro' => 'Falta o domínio (ex.: minhaempresa.ao) para '.($s === 'hospedagem' ? 'a hospedagem' : 'os e-mails').'. Pergunte ao cliente.'];
            }
            $anos = self::inteiro($item['anos'] ?? null, 1, 3, 1);
            $mensal = self::preco($plano['chave']);
            $subtotal = $mensal * 12 * $anos;
            $pct = self::descontoAnos($anos);
            $rotulo = $s === 'hospedagem' ? 'Hospedagem' : 'E-mail profissional';

            return ['linha' => [
                'servico' => $s, 'servicoLabel' => $rotulo, 'icon' => $s === 'hospedagem' ? 'server' : 'mail', 'plano' => $plano['nome'],
                'substitui' => $s.':'.$dominio,
                'detalhes' => array_values(array_filter([
                    'Domínio: '.$dominio,
                    $anos.' '.($anos === 1 ? 'ano' : 'anos').' ('.($anos * 12).' meses) × '.self::kz($mensal).'/mês',
                    $pct ? "Desconto de {$pct}% incluído" : null,
                ])),
                'total' => $subtotal - (int) round(($subtotal * $pct) / 100),
            ]];
        }

        if ($s === 'website') {
            $p = self::website()[strtolower((string) ($item['plano'] ?? ''))] ?? null;
            if (! $p) {
                return ['erro' => 'Pacote de website inválido. Use: '.implode(', ', array_keys(self::website())).'.'];
            }
            $n = self::inteiro($item['paginas_extra'] ?? null, 0, 30, 0);
            $extras = array_values(array_unique(is_array($item['extras'] ?? null) ? $item['extras'] : []));
            foreach ($extras as $e) {
                if (! is_string($e) || ! isset(self::extras()[$e])) {
                    return ['erro' => 'Extra desconhecido: '.(is_string($e) ? $e : '?').'. Use: '.implode(', ', array_keys(self::extras())).'.'];
                }
            }
            $subtotal = self::preco($p['chave']) + $n * self::preco('regra.pagina_extra')
                + array_sum(array_map(fn ($e) => self::preco(self::extras()[$e]['chave']), $extras));
            $prioritario = ! empty($item['prioritario']);
            $urg = $prioritario ? (int) round(($subtotal * self::preco('regra.prioritario')) / 100) : 0;

            return ['linha' => [
                'servico' => 'desenvolvimento', 'servicoLabel' => 'Website', 'icon' => 'code', 'plano' => $p['nome'], 'substitui' => 'desenvolvimento',
                'detalhes' => array_values(array_filter([
                    $n ? "$n página(s) adicional(is)" : null,
                    ...array_map(fn ($e) => self::extras()[$e]['nome'], $extras),
                    $prioritario ? 'Prazo prioritário' : 'Entrega estimada ≈ '.$p['dias'].' dias',
                ])),
                'total' => $subtotal + $urg,
            ]];
        }

        if ($s === 'vps' || $s === 'automacao_ia') {
            $desc = mb_substr(trim((string) ($item['descricao'] ?? '')), 0, 300);
            if ($desc === '') {
                return ['erro' => 'Para VPS/IA descreva em poucas palavras o que o cliente precisa (campo descricao).'];
            }

            return ['linha' => [
                'servico' => $s, 'servicoLabel' => $s === 'vps' ? 'Servidor VPS' : 'Automação com IA', 'icon' => $s === 'vps' ? 'server' : 'cpu',
                'plano' => 'Proposta personalizada', 'detalhes' => [$desc, 'Preço sob consulta — a equipa envia proposta'], 'total' => 0,
            ]];
        }

        return ['erro' => 'Serviço desconhecido: '.(is_scalar($s) ? $s : '?').'.'];
    }

    /** @return array{sub: float, iva: float, total: float} */
    public static function totais(array $linhas): array
    {
        $base = array_sum(array_map(fn ($l) => (float) ($l['total'] ?? 0), $linhas));
        $f = self::fiscal();
        if ($f['precosIncluemIVA']) {
            $sub = $base / (1 + $f['taxaIVA']);

            return ['sub' => $sub, 'iva' => $base - $sub, 'total' => $base];
        }

        return ['sub' => $base, 'iva' => $base * $f['taxaIVA'], 'total' => $base * (1 + $f['taxaIVA'])];
    }

    /** ECO-AAMMDD-XXXX (data de Luanda) */
    public static function numeroPedido(?Carbon $agora = null): string
    {
        $agora = ($agora ?? Carbon::now())->copy()->setTimezone('Africa/Luanda');

        return 'ECO-'.$agora->format('ymd').'-'.random_int(1000, 9999);
    }

    /** Mesmo texto que a página de orçamento envia por WhatsApp. */
    public static function textoPedido(string $numero, array $cliente, array $linhas, string $metodo, string $observacoes, string $canal): string
    {
        $t = self::totais($linhas);
        $pct = (int) round(self::fiscal()['taxaIVA'] * 100);
        $itens = implode("\n", array_map(function ($i) {
            $r = '• '.$i['servicoLabel'].' — '.$i['plano'].': '.($i['total'] ? self::kz($i['total']) : 'sob consulta');
            if (! empty($i['detalhes'])) {
                $r .= "\n   ".implode(' | ', $i['detalhes']);
            }

            return $r;
        }, $linhas));

        return "*Pedido de orçamento $numero*\n"
            .'(registado pelo assistente automático — '.($canal === 'whatsapp' ? 'WhatsApp' : 'site').")\n\n"
            ."*Cliente* ({$cliente['tipo']})\n{$cliente['nome']}\n"
            .($cliente['nif'] ? "NIF: {$cliente['nif']}\n" : '')
            .($cliente['email'] ? "E-mail: {$cliente['email']}\n" : '')
            ."Telefone: {$cliente['telefone']}\n"
            .($cliente['morada'] ? "Morada: {$cliente['morada']}\n" : '')
            ."\n*Serviços*\n$itens\n\n"
            .'Subtotal: '.self::kz($t['sub'])."\nIVA ($pct%): ".self::kz($t['iva'])."\n*Total estimado: ".self::kz($t['total'])."*\n\n"
            ."Pagamento preferido: $metodo".($observacoes ? "\n\nObservações: $observacoes" : '')
            ."\n\nPor favor, enviem a factura proforma. Obrigado!";
    }
}
