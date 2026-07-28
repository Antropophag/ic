[CmdletBinding()]
param(
    [switch]$InstallPrerequisites
)

$ErrorActionPreference = 'Stop'
$ProjectDirectory = Split-Path -Parent $PSScriptRoot
if (Test-Path (Join-Path $PSScriptRoot 'compose.demo.yaml')) {
    $ProjectDirectory = $PSScriptRoot
}

function Test-Command([string]$Name) {
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function New-HexSecret([int]$Bytes) {
    $buffer = [byte[]]::new($Bytes)
    [Security.Cryptography.RandomNumberGenerator]::Fill($buffer)
    return [Convert]::ToHexString($buffer).ToLowerInvariant()
}

if (-not (Test-Command 'docker')) {
    if (-not $InstallPrerequisites) {
        throw 'Docker Desktop не найден. Повторите команду с -InstallPrerequisites в PowerShell от имени администратора.'
    }
    if (-not (Test-Command 'winget')) {
        throw 'winget не найден. Установите App Installer из корпоративного Software Center или Microsoft Store.'
    }

    Write-Host 'Устанавливается WSL...'
    & wsl.exe --install --no-distribution
    Write-Host 'Устанавливается Docker Desktop...'
    & winget install --exact --id Docker.DockerDesktop --accept-package-agreements --accept-source-agreements
    Write-Host 'Установка завершена. Перезагрузите Windows и повторно запустите demo-up.ps1.'
    exit 0
}

$dockerReady = $false
docker info *> $null
if ($LASTEXITCODE -eq 0) { $dockerReady = $true }
if (-not $dockerReady) {
    $dockerDesktop = Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'
    if (Test-Path $dockerDesktop) {
        Start-Process $dockerDesktop
    }
    Write-Host 'Ожидание запуска Docker Desktop...'
    $ready = $false
    foreach ($attempt in 1..60) {
        Start-Sleep -Seconds 2
        docker info *> $null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    }
    if (-not $ready) { throw 'Docker Desktop не запустился за 2 минуты.' }
}

$envFile = Join-Path $ProjectDirectory '.env'
if (-not (Test-Path $envFile)) {
    $template = Get-Content (Join-Path $ProjectDirectory '.env.example') -Raw
    $template = $template.Replace('replace-with-at-least-32-random-characters', (New-HexSecret 32))
    $template = $template.Replace('change-me', (New-HexSecret 24))
    $template = $template.Replace('change-root-password', (New-HexSecret 24))
    [IO.File]::WriteAllText($envFile, $template, [Text.UTF8Encoding]::new($false))
}

$bundle = Join-Path $ProjectDirectory 'demo-images.tar'
$demoCompose = Join-Path $ProjectDirectory 'compose.demo.yaml'
if (Test-Path $bundle) {
    $checksumFile = Join-Path $ProjectDirectory 'SHA256SUMS'
    if (Test-Path $checksumFile) {
        $expectedHash = ((Get-Content $checksumFile -Raw) -split '\s+')[0]
        $actualHash = (Get-FileHash $bundle -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($actualHash -ne $expectedHash.ToLowerInvariant()) {
            throw 'Контрольная сумма demo-images.tar не совпадает. Перенесите комплект повторно.'
        }
    }
    Write-Host 'Загрузка автономных Docker-образов...'
    docker load --input $bundle
    $composeFile = $demoCompose
    $buildArguments = @()
} else {
    Write-Host 'Автономный бандл не найден; будет выполнена онлайн-сборка.'
    $composeFile = Join-Path $ProjectDirectory 'compose.yaml'
    $buildArguments = @('--build')
}

docker compose --file $composeFile up --detach @buildArguments
docker compose --file $composeFile run --rm backend ./yii migrate/up
docker compose --file $composeFile run --rm backend ./yii dev/seed

$readyUrl = 'http://localhost:8080/health/ready'
foreach ($attempt in 1..30) {
    try {
        $response = Invoke-RestMethod -Uri $readyUrl -TimeoutSec 3
        if ($response.status -eq 'ready') { break }
    } catch {
        if ($attempt -eq 30) { throw "Приложение не прошло readiness: $readyUrl" }
        Start-Sleep -Seconds 2
    }
}

Write-Host 'Портал готов: http://localhost:8080' -ForegroundColor Green
