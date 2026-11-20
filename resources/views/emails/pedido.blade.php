<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;color:#14213d">
  <div style="background:#0a1747;color:#fff;padding:18px 24px;border-radius:12px 12px 0 0;font-size:20px;font-weight:700">ECOMULTI</div>
  <div style="border:1px solid #e3e8f5;border-top:0;padding:24px;border-radius:0 0 12px 12px">
    @if ($tipo === 'reserva')
      <h2 style="margin:0 0 14px;font-size:19px">Reserva recebida — a aguardar confirmação de pagamento</h2>
      <p>Olá {{ $cliente['nome'] }}, recebemos o seu pedido <strong>{{ $venda->id }}</strong>. A compra fica <strong>pendente</strong> até confirmarmos o pagamento por transferência bancária.</p>
      <table style="border-collapse:collapse;font-size:15px">
        @foreach (['Domínio solicitado' => $dominio, 'Nome' => $cliente['nome'] ?? '', 'NIF' => $cliente['nif'] ?? '', 'Telemóvel' => $cliente['telefone'] ?? '', 'Endereço' => $cliente['morada'] ?? ''] as $k => $val)
          @if ($val !== '')
            <tr><td style="padding:6px 12px 6px 0;color:#5f6a8a;vertical-align:top">{{ $k }}</td><td style="padding:6px 0;font-weight:600">{{ $val }}</td></tr>
          @endif
        @endforeach
      </table>
      <p style="margin-top:16px"><strong>Serviços</strong></p>
      <ul style="padding-left:18px">@foreach ($itens as $t)<li>{{ $t }}</li>@endforeach</ul>
      <p><strong>Total estimado (com IVA): {{ $total }}</strong></p>
      <p>Depois da aprovação e confirmação do pagamento, enviamos as credenciais do domínio, do e-mail e o tempo de subscrição da hospedagem.</p>
    @else
      <h2 style="margin:0 0 14px;font-size:19px">Pagamento confirmado</h2>
      <p>Olá {{ $cliente['nome'] }}, o pagamento do pedido <strong>{{ $venda->id }}</strong> foi confirmado. Obrigado!</p>
      <table style="border-collapse:collapse;font-size:15px">
        <tr><td style="padding:6px 12px 6px 0;color:#5f6a8a">Domínio</td><td style="padding:6px 0;font-weight:600">{{ $dominio }}</td></tr>
      </table>
      <p><strong>Credenciais de acesso</strong></p>
      @if ($credenciais !== '')
        <pre style="background:#f3f6ff;padding:14px;border-radius:8px;white-space:pre-wrap;font-size:14px">{{ $credenciais }}</pre>
      @else
        <p>Em breve receberá as credenciais de acesso.</p>
      @endif
      <p>Recomendamos alterar as palavras-passe no primeiro acesso.</p>
    @endif
    <p style="color:#5f6a8a;font-size:13px;margin-top:24px">{{ $empresa['nome'] }} · {{ $empresa['email'] }} · {{ $empresa['telefoneVisivel'] }}</p>
  </div>
</div>
