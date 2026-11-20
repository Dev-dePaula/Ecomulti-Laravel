<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Conversas do assistente (site e WhatsApp): o "estado" completo vai em JSON
        Schema::create('conversas', function (Blueprint $t) {
            $t->string('id', 80)->primary();            // "site:<sessão>" ou "wa:<telefone>"
            $t->longText('estado');
            $t->unsignedBigInteger('actualizado');       // ms desde 1970
            $t->index('actualizado');
        });

        // Pedidos registados pelo assistente (para o painel da equipa)
        Schema::create('pedidos_agente', function (Blueprint $t) {
            $t->id();
            $t->string('numero', 20)->unique();
            $t->string('canal', 20);
            $t->string('sessao', 80)->nullable();
            $t->json('cliente');
            $t->json('itens');
            $t->string('metodo', 40)->nullable();
            $t->json('totais');
            $t->text('texto');
            $t->timestamps();
        });

        // Avisos à equipa (pedido novo / atendimento humano)
        Schema::create('avisos', function (Blueprint $t) {
            $t->id();
            $t->string('tipo', 20);
            $t->string('numero', 20)->nullable();
            $t->string('motivo', 40)->nullable();
            $t->string('canal', 20)->nullable();
            $t->string('contacto', 40)->nullable();
            $t->string('whatsapp', 40)->nullable();
            $t->text('texto');
            $t->timestamps();
        });

        Schema::create('clientes', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('nome', 120);
            $t->string('tipo', 12)->default('Particular');
            $t->string('telefone', 30)->default('');
            $t->string('email', 254)->default('');
            $t->string('nif', 20)->default('');
            $t->string('morada', 200)->default('');
            $t->text('notas')->nullable();
            $t->timestamps();
        });

        Schema::create('vendas', function (Blueprint $t) {
            $t->string('id', 40)->primary();             // ECO-AAMMDD-XXXX
            $t->dateTime('data');
            $t->json('cliente');
            $t->string('cliente_id', 40)->default('');
            $t->json('itens');
            $t->decimal('total', 14, 2);
            $t->string('metodo', 40)->default('');
            $t->string('estado', 20)->default('em_aberto');   // em_aberto | pago | cancelado
            $t->dateTime('pago_em')->nullable();
            $t->string('canal', 30)->default('manual');
            $t->text('notas')->nullable();
            $t->timestamps();
            $t->index('estado');
            $t->index('data');
        });

        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->string('email', 254)->unique();
            $t->string('nome', 80)->default('');
            $t->string('whatsapp', 20)->default('');
            $t->json('interesses');
            $t->string('origem', 30)->default('site');
            $t->string('pagina', 120)->default('');
            $t->boolean('consentimento')->default(false);
            $t->dateTime('consentido_em')->nullable();
            $t->string('estado', 20)->default('novo');       // novo | contactado | convertido | descartado
            $t->text('notas')->nullable();
            $t->timestamps();
        });

        Schema::create('posts', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 90)->unique();
            $t->string('estado', 20)->default('rascunho');   // publicado | rascunho | arquivado
            $t->string('titulo', 160)->default('');
            $t->string('resumo', 320)->default('');
            $t->longText('conteudo');
            $t->string('categoria', 40)->default('Notícias');
            $t->json('tags');
            $t->string('autor', 60)->default('Equipa ECOMULTI');
            $t->string('imagem', 500)->default('');
            $t->date('data');
            $t->boolean('destaque')->default(false);
            $t->timestamps();
        });

        // Administração: o utilizador é o do próprio Laravel (tabela users)
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedInteger('token_version')->default(1);
            $t->boolean('senha_inicial')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['token_version', 'senha_inicial']);
        });
        foreach (['posts', 'leads', 'vendas', 'clientes', 'avisos', 'pedidos_agente', 'conversas'] as $tabela) {
            Schema::dropIfExists($tabela);
        }
    }
};
