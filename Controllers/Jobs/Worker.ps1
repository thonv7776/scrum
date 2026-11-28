param(
  [Parameter(Mandatory = $true)][string]$PhpExe,
  [Parameter(Mandatory = $true)][string]$Cert,
  [Parameter(Mandatory = $true)][string]$ProjectName
)
# Single instance per project, even when START_PRIVATEHIRE.bat is opened twice.
$mutexName = 'Local\PrivateHire_Notify_' + ($ProjectName -replace '[^a-zA-Z0-9_-]', '_')
$instance = [System.Threading.Mutex]::new($false, $mutexName)
$acquired = $false
try {
    $acquired = $instance.WaitOne(0)
    if (-not $acquired) { exit 0 }
    $worker = Join-Path $PSScriptRoot 'Worker.php'
    while ($true) {
        & $PhpExe -d "curl.cainfo=$Cert" -d "openssl.cafile=$Cert" $worker
        if ($LASTEXITCODE -ne 0) { Write-Host 'Worker warning: check the WAMP MySQL server or email/SMS settings.' }
        Start-Sleep -Seconds 3
    }
}
finally {
    if ($acquired) { $instance.ReleaseMutex() }
    $instance.Dispose()
}
