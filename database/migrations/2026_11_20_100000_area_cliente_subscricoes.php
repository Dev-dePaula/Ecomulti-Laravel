<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contas da área do cliente (separadas do utilizador administrador)
        Schema::create('cliente_contas', function (Blueprint $t) {
            $t->string('email', 254)->primary();
            $t->string('nome', 120);
            $t->string('senha');                       // hash
            $t->unsignedInteger('versao')->default(1); // mudar a senha invalida as sessões antigas
            $t->boolean('verificado')->default(false);
            $t->dateTime('verificado_em')->nullable();
            $t->timestamps();
        });

        // Códigos de 6 dígitos (só o hash fica guardado)
        Schema::create('cliente_codigos', function (Blueprint $t) {
            $t->string('chave', 300)->primary();       // "<motivo>:<email>"
            $t->string('hash', 64);
            $t->unsignedBigInteger('expira');          // ms
            $t->unsignedTinyInteger('tentativas')->default(0);
            $t->unsignedBigInteger('enviado_em');      // ms
        });

        // Subscrições criadas a partir das vendas pagas
        Schema::create('subscricoes', function (Blueprint $t) {
            $t->string('id', 50)->primary();           // <venda>-<n.º do serviço>
            $t->string('venda_id', 40)->index();
            $t->string('email', 254)->default('')->index();
            $t->string('cliente_nome', 120)->default('');
            $t->string('servico', 200)->default('');
            $t->string('dominio', 253)->default('');
            $t->string('tipo', 12)->default('unico');  // recorrente | unico
            $t->string('inicio', 10)->default('');
            $t->string('renovacao', 10)->default('');
            $t->decimal('valor', 14, 2)->default(0);
            $t->string('estado', 12)->default('activa'); // activa | cancelada
            $t->boolean('editada')->default(false);
            $t->timestamps();
        });

        // Activação na 20i
        Schema::table('vendas', function (Blueprint $t) {
            $t->json('vintei')->nullable();
            $t->json('vintei_dominios')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vendas', function (Blueprint $t) {
            $t->dropColumn(['vintei', 'vintei_dominios']);
        });
        foreach (['subscricoes', 'cliente_codigos', 'cliente_contas'] as $tabela) {
            Schema::dropIfExists($tabela);
        }
    }
};
