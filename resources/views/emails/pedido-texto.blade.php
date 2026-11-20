@if ($tipo === 'reserva')
Olá {{ $cliente['nome'] }},

Recebemos o seu pedido {{ $venda->id }}. A compra está PENDENTE, a aguardar a confirmação do pagamento por transferência bancária.

Detalhes da reserva:
Domínio solicitado: {{ $dominio }}
Nome: {{ $cliente['nome'] }}
NIF: {{ $cliente['nif'] ?: '—' }}
Telemóvel: {{ $cliente['telefone'] ?: '—' }}
Endereço: {{ $cliente['morada'] ?: '—' }}

Serviços:
@foreach ($itens as $t)
{{ $t }}
@endforeach
Total estimado (com IVA): {{ $total }}

Assim que o pagamento for confirmado, enviamos as credenciais de acesso.

ECOMULTI
@else
Olá {{ $cliente['nome'] }},

O pagamento do pedido {{ $venda->id }} foi confirmado. Obrigado!

Domínio: {{ $dominio }}
@if ($credenciais !== '')

Credenciais de acesso:
{{ $credenciais }}
@else

Em breve receberá as credenciais de acesso.
@endif

Recomendamos alterar as palavras-passe no primeiro acesso.

ECOMULTI
@endif
