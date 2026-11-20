# Gera o pacote pronto a enviar para o servidor (sem Node no servidor).
# Uso:  .\deploy.ps1
# Resultado: ..\_publicar\laravel\ecomulti-laravel.zip   (nada e enviado para lado nenhum)
$ErrorActionPreference = 'Continue'
function Verificar($passo) { if ($LASTEXITCODE -ne 0) { throw "Falhou: $passo" } }
Set-Location $PSScriptRoot

$composer = "$env:USERPROFILE\composer\composer.phar"
$stage = Join-Path $PSScriptRoot 'storage\deploy-stage'
$outDir = Join-Path $PSScriptRoot '..\_publicar\laravel'
$zip = Join-Path $outDir 'ecomulti-laravel.zip'

Write-Host '1/4 Compilar CSS/JS (Node, so local)...'
npm install --no-audit --no-fund 2>&1 | Out-Host; Verificar 'npm install'
npm run build 2>&1 | Out-Host; Verificar 'npm run build'

Write-Host '2/4 Instalar dependencias PHP de producao...'
php $composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | Out-Host; Verificar 'composer install'

Write-Host '3/4 Copiar ficheiros para o pacote...'
if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
New-Item -ItemType Directory -Force $stage | Out-Null
robocopy $PSScriptRoot $stage /E /XD (Join-Path $PSScriptRoot 'node_modules') (Join-Path $PSScriptRoot '.git') (Join-Path $PSScriptRoot 'tests') $stage /XF .env '*.sqlite' deploy.ps1 phpunit.xml /NFL /NDL /NJH /NJS | Out-Null

# caches e registos locais nao vao para o servidor
foreach ($d in 'storage\framework\cache', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs', 'bootstrap\cache') {
  $p = Join-Path $stage $d
  if (Test-Path $p) { Get-ChildItem $p -Recurse -File | Where-Object { $_.Name -ne '.gitignore' } | Remove-Item -Force }
}
# pastas vazias que o Laravel precisa
foreach ($d in 'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs', 'bootstrap\cache') {
  New-Item -ItemType Directory -Force (Join-Path $stage $d) | Out-Null
}

Write-Host '4/4 Criar o zip...'
New-Item -ItemType Directory -Force $outDir | Out-Null
if (Test-Path $zip) { Remove-Item $zip -Force }
Compress-Archive -Path (Join-Path $stage '*') -DestinationPath $zip
Remove-Item $stage -Recurse -Force

# repor as dependencias de desenvolvimento (testes, formatador)
php $composer install --no-interaction 2>&1 | Out-Null

Write-Host "Pronto: $zip"
Write-Host 'No servidor: extrair; apontar o dominio para a pasta public/; copiar .env.example para .env e preencher; php artisan key:generate; php artisan migrate --force --seed; php artisan config:cache; criar o cron "php artisan schedule:run" (a cada minuto).'
