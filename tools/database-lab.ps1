param(
    [ValidateSet('Start', 'Test', 'Stop', 'Status')]
    [string]$Action = 'Test',
    [ValidateSet('mysql', 'pgsql', 'both')]
    [string]$Engine = 'both',
    [ValidatePattern('^[a-z0-9-]+$')]
    [string]$Label = 'evaluation',
    [string]$Filter = ''
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$runtime = Join-Path $projectRoot '.runtime\database-lab'
$environmentFile = Join-Path $runtime 'laboratory.env'
$resultsDirectory = Join-Path $runtime 'results'
New-Item -ItemType Directory -Path $resultsDirectory -Force | Out-Null
$dockerCommand = Get-Command docker.exe -ErrorAction SilentlyContinue
$dockerPath = if ($dockerCommand) { $dockerCommand.Source } else { Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker.exe' }
if (-not (Test-Path -LiteralPath $environmentFile)) {
    $passwordBytes = [byte[]]::new(32)
    [Security.Cryptography.RandomNumberGenerator]::Fill($passwordBytes)
    $laboratoryPassword = [Convert]::ToHexString($passwordBytes).ToLowerInvariant()
    $resultsMount = $resultsDirectory.Replace('\', '/')
    [IO.File]::WriteAllText($environmentFile, "LAB_PASSWORD=$laboratoryPassword`nLAB_RESULTS=$resultsMount`n")
}
$composeArguments = @('compose', '--project-name', 'laravel-sso-db-lab', '--env-file', $environmentFile, '-f', (Join-Path $projectRoot 'conformance\database\compose.yml'))
function Invoke-LaboratoryDocker {
    param([string[]]$Arguments)
    & $dockerPath @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Database laboratory command returned $LASTEXITCODE." }
}
if ($Action -eq 'Stop') {
    Invoke-LaboratoryDocker -Arguments ($composeArguments + @('down'))
    return
}
if ($Action -eq 'Status') {
    Invoke-LaboratoryDocker -Arguments ($composeArguments + @('ps', '-a'))
    return
}
if ($Action -eq 'Start') {
    Invoke-LaboratoryDocker -Arguments ($composeArguments + @('build', 'tests'))
    Invoke-LaboratoryDocker -Arguments @('volume', 'create', 'laravel-sso-db-lab_application')
    $archivePath = Join-Path $runtime 'application.tar'
    & tar.exe -cf $archivePath --exclude=vendor/laravel-sso --exclude=vendor/orchestra/testbench-core/laravel/vendor -C $projectRoot vendor packages tests tools composer.lock phpunit.xml phpunit.database.xml conformance/database
    if ($LASTEXITCODE -ne 0) { throw 'Database laboratory snapshot failed.' }
    $extract = 'set -eu; for taskDirectory in /app/packages /app/tests /app/tools; do if [ -d "$taskDirectory" ]; then find "$taskDirectory" -type f -delete; fi; done; tar -xf /snapshot.tar -C /app; mkdir -p /app/vendor/laravel-sso; for taskPackage in server client contracts; do test -e /app/vendor/laravel-sso/$taskPackage || ln -s /app/packages/$taskPackage /app/vendor/laravel-sso/$taskPackage; done'
    Invoke-LaboratoryDocker -Arguments @('run', '--rm', '--network', 'none', '-v', 'laravel-sso-db-lab_application:/app', '-v', ($archivePath + ':/snapshot.tar:ro'), 'laravel-sso-database-php:local', 'sh', '-c', $extract)
    Invoke-LaboratoryDocker -Arguments ($composeArguments + @('up', '-d', '--wait', '--wait-timeout', '180', 'mysql', 'postgres'))
    return
}
$run = $Label + '-' + [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')
$engines = if ($Engine -eq 'both') { @('mysql', 'pgsql') } else { @($Engine) }
$failed = $false
foreach ($databaseEngine in $engines) {
    $logPath = Join-Path $resultsDirectory ($run + '-' + $databaseEngine + '.log')
    $testArguments = @('run', '--rm', '-T', '-e', "SSO_TEST_DRIVER=$databaseEngine", '-e', "SSO_LAB_RUN=$run-$databaseEngine", 'tests', 'php', 'vendor/phpunit/phpunit/phpunit', '--configuration', 'phpunit.database.xml', '--colors=never', '--log-junit', "/results/$run-$databaseEngine.junit.xml")
    if ($Filter) { $testArguments += @('--filter', $Filter) }
    & $dockerPath @composeArguments @testArguments 2>&1 | Tee-Object -FilePath $logPath
    if ($LASTEXITCODE -ne 0) { $failed = $true }
}
$images = foreach ($imageTag in @('mysql:8.4', 'postgres:18', 'laravel-sso-database-php:local')) {
    $metadata = & $dockerPath image inspect --format '{{json .}}' $imageTag | ConvertFrom-Json
    if ($LASTEXITCODE -ne 0) { throw 'Cannot record laboratory image identity.' }
    @{ tag = $imageTag; id = $metadata.Id; repo_digests = @($metadata.RepoDigests) }
}
[IO.File]::WriteAllText((Join-Path $resultsDirectory ($run + '-images.json')), (ConvertTo-Json -InputObject @($images) -Depth 5))
if ($failed) { throw 'Database tests failed. Original logs and JUnit reports were preserved in .runtime/database-lab/results.' }
