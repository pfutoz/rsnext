$BaseDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$StatusFile = Join-Path $BaseDir "tmp\ingest_service_status.json"

if (Test-Path $StatusFile) {
    try {
        $st = Get-Content $StatusFile -Raw -Encoding UTF8 | ConvertFrom-Json
        if ($st.pid) {
            $p = Get-Process -Id $st.pid -ErrorAction SilentlyContinue
            if ($p) {
                Stop-Process -Id $st.pid -Force
                Write-Host "[RS-Next] Stopped Ingest Service (PID: $($st.pid))." -ForegroundColor Yellow
            }
        }
    } catch {}
}

