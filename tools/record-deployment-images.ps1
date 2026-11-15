$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$dockerPath = 'C:\Users\cituk\AppData\Local\Programs\DockerDesktop\resources\bin\docker.exe'
if (-not (Test-Path -LiteralPath $dockerPath)) { $dockerPath = (Get-Command docker.exe -ErrorAction Stop).Source }
$record = [ordered]@{checked_at_utc=[DateTime]::UtcNow.ToString('o');images=[ordered]@{}}
$template = '{"id":{{json .Id}},"repo_digests":{{json .RepoDigests}},"os":{{json .Os}},"architecture":{{json .Architecture}}}'
foreach ($reference in @('laravel-sso-deployment-fpm:local', 'laravel-sso-deployment-apache:local', 'laravel-sso-deployment-browsers:local', 'nginx:1.28', 'mysql:8.4', 'postgres:18')) {
    $metadata = & $dockerPath image inspect $reference --format $template
    if ($LASTEXITCODE -ne 0) { throw "Cannot inspect laboratory image $reference." }
    $record.images[$reference] = $metadata | ConvertFrom-Json
}
$record.engine = (& $dockerPath version --format '{{.Server.Version}}').Trim()
$record.compose = (& $dockerPath compose version --short).Trim()
$record.nginx = (& $dockerPath exec laravel-sso-deployment-gateway-1 nginx -v 2>&1 | Out-String).Trim()
$record.apache = (& $dockerPath exec laravel-sso-deployment-apache-1 apache2 -v | Out-String).Trim()
$outputPath = Join-Path $projectRoot '.runtime/deployment-lab/results/images.json'
$record | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $outputPath -Encoding utf8NoBOM
Write-Output 'Recorded image identities and web server versions without container environment variables.'
