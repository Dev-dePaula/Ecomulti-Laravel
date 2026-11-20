<?php

namespace App\Mail;

use App\Models\Venda;
use App\Services\Agente\Catalogo;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * E-mails transaccionais de um pedido:
 *  - "reserva": pedido recebido, a aguardar confirmação do pagamento
 *  - "confirmacao": pagamento confirmado + credenciais + tempo de subscrição
 */
class MensagemPedido extends Mailable
{
    public function __construct(
        public Venda $venda,
        public string $tipo = 'reserva',
        public string $credenciais = '',
    ) {}

    private function dominio(): string
    {
        $itens = array_filter($this->venda->itens ?? [], fn ($i) => preg_match('/dom[ií]nio/iu', $i['descricao'] ?? ''));
        $d = implode(', ', array_map(fn ($i) => preg_replace('/^.*?—\s*/u', '', $i['descricao']), $itens));

        return $d !== '' ? $d : '—';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->tipo === 'reserva'
                ? "Reserva {$this->venda->id} — a aguardar confirmação de pagamento"
                : "Pagamento confirmado — pedido {$this->venda->id}",
        );
    }

    public function content(): Content
    {
        $v = $this->venda;
        $itens = array_map(fn ($i) => $i['descricao'].' — '.Catalogo::kz($i['valor'] ?? 0), $v->itens ?? []);

        return new Content(
            view: 'emails.pedido',
            text: 'emails.pedido-texto',
            with: [
                'venda' => $v, 'tipo' => $this->tipo, 'cliente' => $v->cliente, 'dominio' => $this->dominio(),
                'itens' => $itens, 'total' => Catalogo::kz($v->total), 'credenciais' => trim($this->credenciais),
                'empresa' => Catalogo::empresa(),
            ],
        );
    }
}
