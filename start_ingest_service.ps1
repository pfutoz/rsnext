$ErrorActionPreference = "Stop"
$BaseDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$StatusFile = Join-Path $BaseDir "tmp\ingest_service_status.json"
$LogOut = Join-Path $BaseDir "tmp\ingest_supervisor.log"
$LogErr = Join-Path $BaseDir "tmp\ingest_supervisor.err.log"

if (-not (Test-Path (Join-Path $BaseDir "tmp"))) {
    New-Item -ItemType Directory -Path (Join-Path $BaseDir "tmp") -Force | Out-Null
}

if (Test-Path $StatusFile) {
    try {
        $st = Get-Content $StatusFile -Raw -Encoding UTF8 | ConvertFrom-Json
        if ($st.pid -and (Get-Process -Id $st.pid -ErrorAction SilentlyContinue)) {
            Write-Host "[RS-Next] Ingest Service is already running (PID: $($st.pid))." -ForegroundColor Green
            exit 0
        }
    } catch {}
}

$PyCandidates = @(
    "C:\Users\Owner\AppData\Local\Programs\Python\Python313\python.exe",
    (Get-Command python -ErrorAction SilentlyContinue).Source
) | Where-Object { $_ -and (Test-Path $_) }

$PyBin = $PyCandidates | Select-Object -First 1
if (-not $PyBin) {
    throw "Python executable not found."
}

$Proc = Start-Process -FilePath $PyBin -ArgumentList "-m", "ingest_service.run_all" -WorkingDirectory $BaseDir -WindowStyle Hidden -RedirectStandardOutput $LogOut -RedirectStandardError $LogErr -PassThru
Start-Sleep -Seconds 2
Write-Host "[RS-Next] Started Ingest Service Supervisor (PID: $($Proc.Id))." -ForegroundColor Cyan

