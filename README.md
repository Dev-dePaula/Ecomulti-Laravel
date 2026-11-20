# ECOMULTI — site e sistema em Laravel

Site institucional e de vendas da ECOMULTI (domínios, hospedagem, e-mail, websites, VPS e automação) com:

- **assistente de IA** (Claude) no site e no WhatsApp, que monta o orçamento e regista o pedido (`ECO-AAMMDD-XXXX`);
- **dashboard** da equipa (`/admin.html`): vendas, clientes, leads, blog e conta;
- **painel rápido** (`/painel.html`) com os pedidos e avisos do assistente;
- **área do cliente** (`/cliente.html`): conta com e-mail confirmado por código, subscrições e datas de renovação;
- ligação à **20i** (revenda): activar a hospedagem e registar domínios a partir de uma venda paga (dashboard);
- formulários do site que enviam a mensagem por e-mail (`/api/contacto`);
- **blog** e **newsletter**.

É 100 % **PHP + MySQL**. Não precisa de Node.js no servidor (o Node só compila o CSS/JS no seu computador) e **não depende de nenhum serviço de hospedagem específico**.

## Correr localmente

Requisitos: PHP 8.3+, Composer, Node 20+ (só para compilar).

```powershell
composer install
npm install
copy .env.example .env          # se ainda não tiver .env
php artisan key:generate
php artisan migrate --seed      # cria as tabelas, o administrador e os artigos de exemplo
npm run build                   # compila o CSS/JS do Laravel (opcional: o site usa os ficheiros em public/assets)
php artisan serve               # http://127.0.0.1:8000
```

Localmente a base de dados é **SQLite** (`database/database.sqlite`), sem instalar nada. Em produção usa-se MySQL.

| Endereço | O que é |
|---|---|
| `/` e `/*.html` | Páginas do site (views em `resources/views/site`) |
| `/admin.html` | Dashboard (login com `ADMIN_EMAIL` / `ADMIN_SENHA` do `.env`; peça-lhe para alterar a senha no primeiro acesso) |
| `/painel.html` | Painel rápido (precisa de `PAINEL_CHAVE` no `.env`, mínimo 12 caracteres) |
| `/api/*` | API usada pelo próprio site (agente, pedido, blog, newsletter, admin, whatsapp) |

## Testes

```powershell
php artisan test
```

73 testes cobrem preços e descontos, o fluxo completo do assistente (com a API da Anthropic **simulada**, sem custos), o webhook do WhatsApp (com a API da Meta simulada), pedidos, newsletter, blog, painel e dashboard.

## Onde se altera cada coisa

| O quê | Onde |
|---|---|
| **Preços**, contactos, dados bancários, IVA, planos VPS | `config/ecomulti.php` (única fonte; `assets/js/config.js` é gerado a partir dele) |
| O que cada plano inclui (para o assistente) | `config/ecomulti.php` → `catalogo` |
| Tom e regras de venda do assistente | `app/Services/Agente/Prompt.php` |
| Ferramentas e lógica do assistente | `app/Services/Agente/Nucleo.php` |
| Páginas do site | `resources/views/site/*.blade.php` |
| CSS / JS do site | `public/assets/` |
| E-mails enviados (reserva, pagamento confirmado) | `resources/views/emails/` |

## Variáveis do `.env`

| Variável | Para quê |
|---|---|
| `DB_*` | Ligação à base de dados MySQL |
| `SITE_URL` | Endereço usado nos links do assistente |
| `ANTHROPIC_API_KEY` | Activa a IA. **Vazia = o chat funciona em modo guiado, sem IA** |
| `AGENTE_MODELO` | Modelo (por omissão `claude-opus-5-5`; `claude-sonnet-5-5` custa cerca de metade) |
| `AGENTE_LIMITE_DIARIO`, `AGENTE_LIMITE_CONVERSA`, `AGENTE_LIMITE_CONVERSA_WA` | Travões de custo |
| `ADMIN_EMAIL`, `ADMIN_SENHA` | Administrador criado por `php artisan db:seed` |
| `PAINEL_CHAVE` | Chave de acesso ao painel e à API do blog/newsletter |
| `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN` | Ligação ao WhatsApp Business (Meta) |
| `EQUIPA_WHATSAPP`, `WHATSAPP_MODELO_AVISO`, `WHATSAPP_MODELO_IDIOMA`, `WHATSAPP_PAUSA_HUMANO_HORAS` | Avisos à equipa |
| `MAIL_*`, `EMAIL_EQUIPA` | Envio de e-mails (SMTP): códigos da área do cliente, formulários e confirmações. Localmente `MAIL_MAILER=log` grava em `storage/logs` |
| `CONTACTO_EMAIL`, `CONTACTO_LIMITE_IP` | Destino das mensagens dos formulários e limite de envios por IP/hora |
| `VINTEI_API_KEY` ou `VINTEI_URL` + `VINTEI_PROXY_CHAVE` | 20i: directa (IP autorizado no My20i) ou pelo proxy PHP com IP fixo (`ferramentas/proxy-20i/`) |

Webhook do WhatsApp (Meta): `https://SEU-DOMINIO/api/whatsapp`, com o mesmo `WHATSAPP_VERIFY_TOKEN`.

## Fila e tarefas agendadas

O WhatsApp responde através de uma **fila** (`QUEUE_CONNECTION=database`) e há uma limpeza diária de conversas antigas. Tudo corre a partir de **um único cron por minuto**, sem processos permanentes:

```
* * * * * cd /caminho/do/projeto && php artisan schedule:run >> /dev/null 2>&1
```

Localmente (`QUEUE_CONNECTION=sync`) as mensagens são processadas na hora.

## Gerar o pacote para publicar

```powershell
.\deploy.ps1
```

Cria `..\_publicar\laravel\ecomulti-laravel.zip` com tudo pronto (dependências PHP de produção e CSS/JS compilado). **Não envia nada** para nenhum servidor. Para publicar:

1. Enviar e extrair o zip no servidor; apontar o domínio para a pasta `public/`.
2. Copiar `.env.example` para `.env` e preencher (base de dados, chaves, SMTP).
3. Por SSH: `php artisan key:generate`, `php artisan migrate --force --seed`, `php artisan config:cache`.
4. Criar o cron acima.
5. Requisitos do PHP: 8.3 ou superior, com `curl`, `mbstring`, `openssl`, `pdo_mysql`, `fileinfo`, `zip`, `intl`.
