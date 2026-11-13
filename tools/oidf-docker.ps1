param(
    [ValidateSet('Status', 'Build', 'Start', 'Run', 'Stop')]
    [string]$Action = 'Status',
    [string]$PhpPath = '',
    [ValidateSet('strict', 'compatibility')]
    [string]$Mode = 'strict',
    [ValidatePattern('^[a-z0-9-]+$')]
    [string]$Label = 'evaluation'
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$suitePath = Join-Path $projectRoot '.runtime\oidf-suite'
$descriptor = Get-Content -LiteralPath (Join-Path $projectRoot 'conformance\oidf-suite.json') -Raw | ConvertFrom-Json
$dockerCommand = Get-Command docker.exe -ErrorAction SilentlyContinue
$dockerPath = if ($dockerCommand) { $dockerCommand.Source } else { Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker.exe' }
if (-not (Test-Path -LiteralPath $dockerPath)) {
    throw 'Docker CLI not found. Install Docker Desktop before running this script.'
}
function Invoke-Docker {
    param([string[]]$DockerArguments)
    & $dockerPath @DockerArguments
    if ($LASTEXITCODE -ne 0) { throw "Docker returned exit code $LASTEXITCODE." }
}
Invoke-Docker -DockerArguments @('--version')
Invoke-Docker -DockerArguments @('compose', 'version')
Invoke-Docker -DockerArguments @('info', '--format', '{{.ServerVersion}}')
if ($Action -eq 'Status') { return }
$revision = (& git -C $suitePath rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or $revision -ne $descriptor.revision) {
    throw 'Official suite revision does not match conformance/oidf-suite.json.'
}
$suiteChanges = & git -C $suitePath status --porcelain
if ($suiteChanges) { throw 'Official suite checkout has modifications; review them before running.' }
$composeArguments = @('compose', '--project-name', 'laravel-sso-oidf', '-f', (Join-Path $suitePath 'docker-compose.yml'), '-f', (Join-Path $projectRoot 'conformance\docker.local.override.yml'))
if ($Action -eq 'Stop') {
    Invoke-Docker -DockerArguments ($composeArguments + @('down'))
    $pidFile = Join-Path $projectRoot '.runtime\conformance\op.pid'
    if (Test-Path -LiteralPath $pidFile) {
        $opProcessId = [int](Get-Content -LiteralPath $pidFile)
        $opProcess = Get-CimInstance Win32_Process -Filter "ProcessId=$opProcessId" -ErrorAction SilentlyContinue
        if ($opProcess -and $opProcess.Name -eq 'php.exe' -and $opProcess.CommandLine.Contains((Join-Path $projectRoot '.runtime\conformance\op'))) {
            Stop-Process -Id $opProcessId
            Remove-Item -LiteralPath $pidFile
        }
    }
    return
}
if ($Action -eq 'Run') {
    Invoke-Docker -DockerArguments ($composeArguments + @('exec', '-T', 'identity-php', 'php', '/app/tools/oidf-prepare.php', $Mode))
    $runDirectory = 'official-' + $Label + '-' + [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')
    & $dockerPath @composeArguments exec -T -e "OIDF_RUN_DIRECTORY=$runDirectory" identity-php php /app/tools/oidf-run.php $Label
    $runExit = $LASTEXITCODE
    $containerId = (& $dockerPath @composeArguments ps -q identity-php).Trim()
    if (-not $containerId) { throw 'Private OP container is unavailable.' }
    Invoke-Docker -DockerArguments @('cp', ($containerId + ':/app/.runtime/conformance/' + $runDirectory), (Join-Path $projectRoot '.runtime\conformance'))
    Write-Output ('Private evidence copied to .runtime/conformance/' + $runDirectory)
    exit $runExit
}
if ($Action -eq 'Build' -or -not (Test-Path -LiteralPath (Join-Path $suitePath 'target\fapi-test-suite.jar'))) {
    $env:MAVEN_CACHE = Join-Path $projectRoot '.runtime\maven-cache'
    New-Item -ItemType Directory -Path $env:MAVEN_CACHE -Force | Out-Null
    # Maven repeatedly scans thousands of source paths. Keep the verified source tree
    # on Linux storage rather than compiling across the Windows bind mount.
    $buildVolume = 'laravel-sso-oidf-source-' + $revision.Substring(0, 12)
    $buildCommand = 'set -eu; if [ ! -d /build/suite/.git ]; then git -c safe.directory=/source clone --no-hardlinks /source /build/suite; fi; cd /build/suite; test "$(git rev-parse HEAD)" = "' + $revision + '"; test -z "$(git status --porcelain)"; mvn -B clean package -DskipTests=true; mkdir -p /source/target; cp target/fapi-test-suite.jar /source/target/fapi-test-suite.jar'
    Invoke-Docker -DockerArguments @('run', '--rm', '--name', 'laravel-sso-oidf-builder', '-v', ($suitePath + ':/source'), '-v', ($env:MAVEN_CACHE + ':/root/.m2'), '-v', ($buildVolume + ':/build'), 'maven:3-eclipse-temurin-21', 'sh', '-c', $buildCommand)
}
if ($Action -eq 'Start') {
    if (-not $PhpPath) {
        $herdPhp = Join-Path $env:USERPROFILE '.config\herd\bin\php83\php.exe'
        $PhpPath = if (Test-Path -LiteralPath $herdPhp) { $herdPhp } else { (Get-Command php.exe -ErrorAction Stop).Source }
    }
    if (-not $env:OPENSSL_CONF) {
        $opensslConfig = Join-Path (Split-Path -Parent $PhpPath) 'extras\ssl\openssl.cnf'
        if (Test-Path -LiteralPath $opensslConfig) { $env:OPENSSL_CONF = $opensslConfig }
    }
    & $PhpPath (Join-Path $PSScriptRoot 'oidf-prepare.php')
    if ($LASTEXITCODE -ne 0) { throw 'Private OP fixture preparation failed.' }
    Invoke-Docker -DockerArguments ($composeArguments + @('build', 'identity-php'))
    $archivePath = Join-Path $projectRoot '.runtime\conformance\op-app.tar'
    # Native tar reads NTFS once; PHP then executes from Linux storage. Only the
    # fixture's private data is copied, never the example databases or user secrets.
    & tar.exe -cf $archivePath --exclude=vendor/laravel-sso --exclude=vendor/orchestra/testbench-core/laravel/vendor --exclude=examples/identity-server/bootstrap/cache --exclude=.runtime/conformance/op/bootstrap/cache --exclude=.runtime/conformance/op/storage/framework/views --exclude=.runtime/conformance/op/storage/framework/sessions --exclude=.runtime/conformance/op/storage/logs -C $projectRoot vendor packages examples/shared examples/identity-server/artisan examples/identity-server/bootstrap examples/identity-server/routes examples/identity-server/resources examples/identity-server/database/migrations tools conformance/oidf-suite.json .runtime/conformance/op .runtime/conformance/tls .runtime/conformance/config.json
    if ($LASTEXITCODE -ne 0) { throw 'Private application snapshot failed.' }
    $extractCommand = 'set -eu; tar -xf /snapshot.tar -C /app; mkdir -p /app/vendor/laravel-sso; for taskPackage in server client contracts; do test -e /app/vendor/laravel-sso/$taskPackage || ln -s /app/packages/$taskPackage /app/vendor/laravel-sso/$taskPackage; done; cd /app/.runtime/conformance/op; mkdir -p bootstrap/cache storage/framework/views storage/framework/sessions storage/framework/cache/data storage/logs; php artisan --version; php /app/tools/oidf-prepare.php strict'
    Invoke-Docker -DockerArguments @('run', '--rm', '--network', 'none', '-v', 'laravel-sso-oidf-op-app:/app', '-v', ($archivePath + ':/snapshot.tar:ro'), 'laravel-sso-oidf-identity-php:local', 'sh', '-c', $extractCommand)
    Invoke-Docker -DockerArguments ($composeArguments + @('up', '-d', '--build'))
    Invoke-Docker -DockerArguments ($composeArguments + @('restart', 'identity-php'))
    Invoke-Docker -DockerArguments ($composeArguments + @('ps'))
    Write-Output 'Official harness started locally. Starting it does not execute a conformance plan.'
}
