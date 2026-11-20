<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->administrador();
        $this->artigosIniciais();
    }

    /** Utilizador do dashboard: ADMIN_EMAIL / ADMIN_SENHA do .env (a senha deve ser alterada no primeiro acesso) */
    private function administrador(): void
    {
        $email = strtolower(trim((string) config('ecomulti.admin.email')));
        $senha = (string) config('ecomulti.admin.senha');
        if ($email === '' || $senha === '') {
            $this->command?->warn('ADMIN_EMAIL / ADMIN_SENHA não definidos no .env: administrador não criado.');

            return;
        }
        if (! User::where('email', $email)->exists()) {
            User::create(['name' => 'Administrador', 'email' => $email, 'password' => Hash::make($senha), 'senha_inicial' => true]);
        }
    }

    /** Artigos de exemplo do blog (database/data/posts.json), só se ainda não existirem */
    private function artigosIniciais(): void
    {
        $f = database_path('data/posts.json');
        if (! is_file($f)) {
            return;
        }
        foreach (json_decode((string) file_get_contents($f), true)['posts'] ?? [] as $p) {
            Post::firstOrCreate(['slug' => $p['slug']], [
                'estado' => $p['estado'] ?? 'publicado', 'titulo' => $p['titulo'] ?? '', 'resumo' => $p['resumo'] ?? '',
                'conteudo' => $p['conteudo'] ?? '', 'categoria' => $p['categoria'] ?? 'Notícias', 'tags' => $p['tags'] ?? [],
                'autor' => $p['autor'] ?? 'Equipa ECOMULTI', 'imagem' => $p['imagem'] ?? '', 'data' => $p['data'] ?? now()->format('Y-m-d'),
                'destaque' => (bool) ($p['destaque'] ?? false),
            ]);
        }
    }
}
