param(
    [ValidateSet('Build', 'Start', 'Browsers', 'Soak', 'Inspect', 'Stop', 'Status')]
    [string]$Action = 'Status',
    [ValidateRange(1, 120)][int]$Minutes = 30,
    [ValidatePattern('^[a-z0-9-]+$')][string]$Label = 'evaluation'
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$runtime = Join-Path $projectRoot '.runtime/deployment-lab'
$dockerPath = 'C:\Users\cituk\AppData\Local\Programs\DockerDesktop\resources\bin\docker.exe'
if (-not (Test-Path -LiteralPath $dockerPath)) { $dockerPath = (Get-Command docker.exe -ErrorAction Stop).Source }
foreach ($folder in @('results', 'control', 'tls')) { New-Item -ItemType Directory -Path (Join-Path $runtime $folder) -Force | Out-Null }
$environmentFile = Join-Path $runtime 'laboratory.env'
if (-not (Test-Path -LiteralPath $environmentFile)) {
    $passwordBytes = [byte[]]::new(32)
    [Security.Cryptography.RandomNumberGenerator]::Fill($passwordBytes)
    $taskPassword = [Convert]::ToHexString($passwordBytes).ToLowerInvariant()
    [IO.File]::WriteAllText($environmentFile, "LAB_PASSWORD=$taskPassword`nLAB_RUNTIME=$($runtime.Replace('\', '/'))`n")
}
$compose = @('compose', '--project-name', 'laravel-sso-deployment', '--env-file', $environmentFile, '-f', (Join-Path $projectRoot 'conformance/deployment/compose.yml'))
function Invoke-LabDocker {
    param([string[]]$Arguments)
    & $dockerPath @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Deployment laboratory command returned $LASTEXITCODE." }
}
if ($Action -eq 'Stop') { Invoke-LabDocker -Arguments ($compose + @('down')); return }
if ($Action -eq 'Status') { Invoke-LabDocker -Arguments ($compose + @('ps', '-a')); return }
if ($Action -eq 'Build') { Invoke-LabDocker -Arguments ($compose + @('build', 'fpm', 'apache', 'browser')); return }
if ($Action -eq 'Start') {
    $env:OPENSSL_CONF = 'C:\Users\cituk\.config\herd\bin\php83\extras\ssl\openssl.cnf'
    & 'C:\Users\cituk\.config\herd\bin\php83\php.exe' (Join-Path $projectRoot 'tools/deployment-prepare.php') tls
    if ($LASTEXITCODE -ne 0) { throw 'TLS preparation failed.' }
    Invoke-LabDocker -Arguments @('volume', 'create', 'laravel-sso-deployment-application')
    $archive = Join-Path $runtime 'application.tar'
    & tar.exe -cf $archive --exclude=vendor/laravel-sso --exclude=vendor/orchestra/testbench-core/laravel/vendor --exclude=examples/*/vendor --exclude=examples/*/storage --exclude=examples/*/database/*.sqlite --exclude=examples/*/.env --exclude=examples/*/bootstrap/cache -C $projectRoot vendor packages tests tools composer.lock examples conformance/deployment
    if ($LASTEXITCODE -ne 0) { throw 'Snapshot failed.' }
    $extract = 'set -eu; tar -xf /snapshot.tar -C /app; mkdir -p /app/vendor/laravel-sso; for taskPackage in server client contracts; do test -e /app/vendor/laravel-sso/$taskPackage || ln -s /app/packages/$taskPackage /app/vendor/laravel-sso/$taskPackage; done'
    Invoke-LabDocker -Arguments @('run', '--rm', '--network', 'none', '-v', 'laravel-sso-deployment-application:/app', '-v', ($archive.Replace('\', '/') + ':/snapshot.tar:ro'), 'laravel-sso-deployment-fpm:local', 'sh', '-c', $extract)
    Invoke-LabDocker -Arguments ($compose + @('up', '-d', '--wait', 'mysql', 'postgres'))
    Invoke-LabDocker -Arguments ($compose + @('run', '--rm', '--user', 'root', 'runner', '/app/tools/deployment-prepare.php', 'applications'))
    Invoke-LabDocker -Arguments ($compose + @('up', '-d', 'fpm', 'apache', 'gateway'))
    Invoke-LabDocker -Arguments ($compose + @('exec', '-T', 'gateway', 'nginx', '-s', 'reload'))
    Invoke-LabDocker -Arguments ($compose + @('run', '--rm', 'runner', '/app/tools/deployment-control.php', 'inspect'))
    return
}
if ($Action -eq 'Browsers') { Invoke-LabDocker -Arguments ($compose + @('run', '--rm', 'browser', $Label)); return }
if ($Action -eq 'Inspect') { Invoke-LabDocker -Arguments ($compose + @('run', '--rm', 'runner', '/app/tools/deployment-control.php', 'inspect')); return }
if ($Action -eq 'Soak') {
    $statsFile = Join-Path $runtime "results/$Label-stats.jsonl"
    $monitor = Start-Job -ScriptBlock {
        param($docker, $outputFile)
        while ($true) {
            $rows = & $docker stats --no-stream --format '{{json .}}' laravel-sso-deployment-fpm-1 laravel-sso-deployment-apache-1 laravel-sso-deployment-gateway-1 laravel-sso-deployment-mysql-1 laravel-sso-deployment-postgres-1
            foreach ($row in $rows) { @{checked_at_utc=[DateTime]::UtcNow.ToString('o');stats=($row | ConvertFrom-Json)} | ConvertTo-Json -Compress | Add-Content -LiteralPath $outputFile -Encoding utf8NoBOM }
            Start-Sleep -Seconds 30
        }
    } -ArgumentList $dockerPath, $statsFile
    try {
        Invoke-LabDocker -Arguments ($compose + @('run', '--rm', 'runner', '/app/tools/deployment-soak.php', ($Minutes * 60).ToString(), $Label))
    } finally {
        Stop-Job -Job $monitor
        Receive-Job -Job $monitor
        Remove-Job -Job $monitor
    }
}
