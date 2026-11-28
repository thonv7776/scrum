# Starts WAMP, verifies the local project, installs only a new/empty database, and opens the site.
param(
    [Parameter(Mandatory = $true)]
    [string]$Root
)

$ErrorActionPreference = 'Stop'
$Root = [System.IO.Path]::GetFullPath($Root).TrimEnd([char]'\', [char]'/')
$www = 'C:\wamp64\www'

if (-not $Root.StartsWith($www + '\', [System.StringComparison]::OrdinalIgnoreCase)) {
    Write-Host 'ERROR: Put the project under C:\wamp64\www\ first.' -ForegroundColor Red
    exit 1
}

$relative = $Root.Substring($www.Length).TrimStart([char]'\') -replace '\\', '/'
$relativeUrl = ($relative.Split('/') | ForEach-Object { [uri]::EscapeDataString($_) }) -join '/'
$url = 'http://localhost/' + $relativeUrl + '/'
$wamp = 'C:\wamp64\wampmanager.exe'

if (Test-Path $wamp) {
    if (-not (Get-Process -Name wampmanager -ErrorAction SilentlyContinue)) {
        Start-Process -FilePath $wamp
    }
}

$php = Get-ChildItem 'C:\wamp64\bin\php' -Directory -Filter 'php*' |
    Sort-Object Name -Descending |
    ForEach-Object { Join-Path $_.FullName 'php.exe' } |
    Where-Object { Test-Path $_ } |
    Select-Object -First 1

if (-not $php) {
    Write-Host 'ERROR: No PHP in WampServer.' -ForegroundColor Red
    exit 1
}

$cert = Join-Path $Root 'Models\Config\cacert.pem'
if (-not (Test-Path $cert)) {
    Write-Host 'ERROR: Missing CA certificate.' -ForegroundColor Red
    exit 1
}

# WAMP services can take a few moments to start after launch.
$ready = $false
for ($i = 0; $i -lt 40; $i++) {
    try {
        $r = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 2 -ErrorAction Stop
        if ($r.StatusCode -eq 200 -and $r.Content -match 'PrivateHire Cars') {
            $ready = $true
            break
        }
    }
    catch {
        # The next attempt checks again after the normal WAMP startup delay.
    }

    Start-Sleep -Seconds 2
}

if (-not $ready) {
    Write-Host "ERROR: Cannot open $url. Check green WAMP icon and Apache rewrite_module." -ForegroundColor Red
    exit 1
}

# Install reference data and all tables ONLY when the selected DB is new/empty.
# Existing databases are validated but NEVER overwritten.
& $php '-d' "curl.cainfo=$cert" '-d' "openssl.cafile=$cert" (Join-Path $Root 'Controllers\Setup\Install.php')
if ($LASTEXITCODE -ne 0) {
    Write-Host 'Database check failed. Check README and WAMP.' -ForegroundColor Red
    exit 1
}

$sha = [Security.Cryptography.SHA256]::Create()
try {
    $mutex = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($Root)))).Replace('-', '').Substring(0, 12)
}
finally {
    $sha.Dispose()
}

$worker = Join-Path $Root 'Controllers\Jobs\Worker.ps1'
Start-Process -FilePath 'powershell.exe' -WindowStyle Minimized -ArgumentList @(
    '-NoProfile',
    '-ExecutionPolicy', 'Bypass',
    '-File', ('"' + $worker + '"'),
    '-PhpExe', ('"' + $php + '"'),
    '-Cert', ('"' + $cert + '"'),
    '-ProjectName', ('"' + $mutex + '"')
)

Write-Host "PrivateHire Cars is ready: $url" -ForegroundColor Green
Start-Process $url
