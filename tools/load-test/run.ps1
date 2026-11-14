#Requires -Version 7.0
param(
    [ValidateRange(1000, 1000000)][int] $Total = 1000000,
    [ValidateRange(3, 24)][int] $Workers = 10,
    [ValidateRange(3000, 150000)][int] $Watermark = 18000
)

$ErrorActionPreference = 'Stop'
$docker = (Get-Command docker -CommandType Application | Select-Object -First 1).Source
$repo = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$project = 'notification-load-' + [guid]::NewGuid().ToString('N').Substring(0, 12)
$results = Join-Path $PSScriptRoot "results/$project"
New-Item -ItemType Directory -Path $results | Out-Null

# Keep the local application's .env out of the benchmark.
$envFile = Join-Path $results 'compose.env'
Set-Content -LiteralPath $envFile -Value '# Isolated benchmark configuration'
$settings = @{
    BENCH_TOTAL = "$Total"
    BENCH_WORKERS = "$Workers"
    BENCH_WATERMARK = "$Watermark"
    BENCH_CLIENTS = '4'
    BENCH_DIR = $PSScriptRoot.Replace('\', '/')
    BENCH_RESULTS = $results.Replace('\', '/')
    COMPOSE_PROFILES = ''
}
$previous = @{}
$compose = @(
    'compose', '--progress', 'plain', '--project-directory', $repo, '--project-name', $project,
    '--env-file', $envFile, '-f', (Join-Path $repo 'compose.yaml'),
    '-f', (Join-Path $PSScriptRoot 'override.yaml')
)

try {
    foreach ($name in $settings.Keys) {
        $previous[$name] = [Environment]::GetEnvironmentVariable($name)
        [Environment]::SetEnvironmentVariable($name, $settings[$name])
    }
    & $docker @compose config --quiet
    if ($LASTEXITCODE -ne 0) { throw 'Compose configuration validation failed' }
    & $docker @compose up -d --build --scale "worker=$Workers" --wait --wait-timeout 120
    if ($LASTEXITCODE -ne 0) { throw 'Compose startup failed' }
    & $docker @compose ps -a | Set-Content -LiteralPath (Join-Path $results 'compose-start.txt')
    $runningWorkers = @(& $docker @compose ps --status running -q worker)
    if ($LASTEXITCODE -ne 0 -or $runningWorkers.Count -ne $Workers) { throw 'Unexpected running worker count' }

    & $docker @compose run --rm --no-deps -T driver
    $driverExit = $LASTEXITCODE
    Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'verify.sql') |
        & $docker @compose exec -T postgres psql -X -v ON_ERROR_STOP=1 -U notifications -d notifications -At |
        Set-Content -LiteralPath (Join-Path $results 'database.json')
    if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL verification query failed' }
    if ($driverExit -ne 0) { throw "Load driver exited $driverExit" }

    $db = Get-Content -Raw -LiteralPath (Join-Path $results 'database.json') | ConvertFrom-Json -AsHashtable
    $summary = Get-Content -Raw -LiteralPath (Join-Path $results 'summary.json') | ConvertFrom-Json -AsHashtable
    if ($db.total -ne $Total -or $db.distinct_recipients -ne $Total -or $db.unfinished -ne 0 -or
        $db.mismatched_provider_ids -ne 0 -or $db.queue_jobs -ne 0 -or $db.failed_jobs -ne 0 -or
        $db.batch_count -ne $summary.batchCount -or $db.batch_items -ne $Total -or
        $db.idempotency_records -ne $summary.batchCount) {
        throw 'Unexpected PostgreSQL aggregates. Check database.json'
    }

    $expectedIds = 0
    foreach ($channel in $summary.expected.Keys) {
        $expected = $summary.expected[$channel]
        $rows = @($db.by_status | Where-Object channel -EQ $channel)
        if (($rows | Measure-Object -Property attempts -Sum).Sum -ne $expected.calls) {
            throw "Database attempt count mismatch for $channel"
        }
        $expectedIds += $expected.accepted
        foreach ($status in @('accepted', 'failed', 'uncertain')) {
            $count = ($rows | Where-Object status -EQ $status | Measure-Object -Property notifications -Sum).Sum
            if (($count ?? 0) -ne $expected[$status]) {
                throw "Database status count mismatch for $channel/$status"
            }
        }
    }
    if ($db.distinct_provider_ids -ne $expectedIds) { throw 'Database provider ID count mismatch' }

    'Verified' | Set-Content -LiteralPath (Join-Path $results 'outcome.txt')
    Write-Output "Verified. Results: $results"
} catch {
    "Failed: $_" | Set-Content -LiteralPath (Join-Path $results 'outcome.txt')
    throw
} finally {
    try {
        & $docker @compose ps -a | Set-Content -LiteralPath (Join-Path $results 'compose-end.txt')
        & $docker @compose logs --no-color --tail 20 worker 2>&1 |
            Set-Content -LiteralPath (Join-Path $results 'worker-tail.txt')
    } finally {
        & $docker @compose down --volumes --timeout 10
        foreach ($name in $previous.Keys) {
            [Environment]::SetEnvironmentVariable($name, $previous[$name])
        }
    }
}
