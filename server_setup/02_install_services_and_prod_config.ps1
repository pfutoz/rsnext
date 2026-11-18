#Requires -RunAsAdministrator
param(
    [string]$PostgresPassword = "postgres"
)

# ==============================================================================
# [新サーバ 192.168.1.163 管理者PowerShell用]
# Step 2: VC++ランタイム / Python 3.13 / PostgreSQL 18 / Apache 2.4 /
#         SMB共有・Server 2025 ポリシー最適化 / RS-Next 常駐サービス / 自動ミラー登録
# ==============================================================================

$ErrorActionPreference = "Stop"
$RsNextRoot = "C:\Apache24\htdocs\rsnext"

Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host " RS-Next Server 2025 セットアップ [Step 2: DB・Web・常駐・BCP構築]" -ForegroundColor Cyan
Write-Host "=================================================================" -ForegroundColor Cyan

if (-not (Test-Path $RsNextRoot)) {
    throw "C:\Apache24\htdocs\rsnext が見つかりません。先に 00_send_package_to_server.ps1 を実行してください。"
}

# 1. Visual C++ x64 ランタイム導入確認
$vcInstalled = Get-ItemProperty "HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" -ErrorAction SilentlyContinue
if (-not $vcInstalled) {
    $localVc = "C:\Program Files (x86)\AMD\Chipset_Software\Prerequisites\VC_redist.x64.exe"
    if (Test-Path $localVc) {
        Write-Host "[1/8] Visual C++ x64 ランタイムをインストール中..." -ForegroundColor Cyan
        Start-Process -FilePath $localVc -ArgumentList "/install", "/quiet", "/norestart" -Wait
    } else {
        $vcTmp = "$env:TEMP\vc_redist.x64.exe"
        & curl.exe -fSL "https://aka.ms/vs/17/release/vc_redist.x64.exe" -o $vcTmp
        Start-Process -FilePath $vcTmp -ArgumentList "/install", "/quiet", "/norestart" -Wait
    }
}

# 2. Python 3.13 (C:\Python313) と医療通信ライブラリの導入
$pyExe = @(
    "C:\Python313\python.exe",
    "C:\Program Files\Python313\python.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $pyExe) {
    Write-Host "[2/8] Python 3.13.9 (64-bit) をダウンロード・インストール中..." -ForegroundColor Cyan
    $pyInstaller = "$env:TEMP\python-3.13.9-amd64.exe"
    & curl.exe -fSL "https://www.python.org/ftp/python/3.13.9/python-3.13.9-amd64.exe" -o $pyInstaller
    Start-Process -FilePath $pyInstaller -ArgumentList "/quiet", "InstallAllUsers=1", "PrependPath=1", "TargetDir=C:\Python313", "Include_test=0" -Wait
    $pyExe = "C:\Python313\python.exe"
}

Write-Host "[2/8] Python 医療通信パッケージ (pydicom / pynetdicom / pyftpdlib / watchdog / psycopg / Pillow) を確認・導入中..." -ForegroundColor Cyan
& $pyExe -m pip install --upgrade pip --quiet
& $pyExe -m pip install pydicom pynetdicom pyftpdlib watchdog "psycopg[binary]" pillow numpy pymssql --quiet

# 3. PostgreSQL 18 の導入確認・rsnext_db (UTF-8) 構築
$psqlCandidates = Get-ChildItem "C:\Program Files\PostgreSQL\*\bin\psql.exe" -ErrorAction SilentlyContinue |
    Where-Object { $_.FullName -notmatch "\\9\.3\\" } |
    Sort-Object FullName -Descending
$psqlExe = if ($psqlCandidates) { $psqlCandidates[0].FullName } else { $null }

if (-not $psqlExe) {
    Write-Host "[3/8] PostgreSQL 18.7 (Windows x64) をダウンロード・サイレント導入中..." -ForegroundColor Cyan
    $pgInstaller = "$env:TEMP\postgresql-18.7-1-windows-x64.exe"
    & curl.exe -fSL "https://get.enterprisedb.com/postgresql/postgresql-18.7-1-windows-x64.exe" -o $pgInstaller
    $pgProc = Start-Process -FilePath $pgInstaller -ArgumentList @(
        "--mode", "unattended",
        "--unattendedmodeui", "none",
        "--superpassword", $PostgresPassword,
        "--servicename", "postgresql-x64-18",
        "--serverport", "5432",
        "--locale", "C"
    ) -Wait -PassThru
    $psqlCandAfter = Get-ChildItem "C:\Program Files\PostgreSQL\*\bin\psql.exe" -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -notmatch "\\9\.3\\" } |
        Sort-Object FullName -Descending | Select-Object -First 1
    if (-not $psqlCandAfter) {
        throw "PostgreSQL 18 のインストールに失敗しました (ExitCode=$($pgProc.ExitCode))。"
    }
    $psqlExe = $psqlCandAfter.FullName
}

$pgBinDir = Split-Path $psqlExe -Parent
$pgReadyExe = Join-Path $pgBinDir "pg_isready.exe"
for ($w = 0; $w -lt 15; $w++) {
    & $pgReadyExe -h 127.0.0.1 -p 5432 | Out-Null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 1
}

# システム PATH に C:\php, C:\Python313, PostgreSQL\bin を追加
$curPath = [Environment]::GetEnvironmentVariable("Path", "Machine")
foreach ($addP in @("C:\php", "C:\Python313", $pgBinDir)) {
    if ((Test-Path $addP) -and ($curPath -notlike "*$addP*")) {
        $curPath = "$curPath;$addP"
    }
}
[Environment]::SetEnvironmentVariable("Path", $curPath, "Machine")

Write-Host "[3/8] UTF-8 データベース 'rsnext_db' と全スキーマ・MWLプリセットを構築中..." -ForegroundColor Cyan
$env:PGPASSWORD = $PostgresPassword
$env:PGOPTIONS = "-c client_min_messages=warning"
$ErrorActionPreference = "Continue"
$dbExistsRaw = (& $psqlExe -h 127.0.0.1 -U postgres -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='rsnext_db'" 2>$null | Out-String).Trim()
if ($dbExistsRaw -ne "1") {
    & $psqlExe -h 127.0.0.1 -U postgres -d postgres -c "CREATE DATABASE rsnext_db WITH ENCODING 'UTF8' TEMPLATE template0;"
}
& $psqlExe -h 127.0.0.1 -U postgres -d rsnext_db -f "$RsNextRoot\sql\001_schema.sql" | Out-Null
& $psqlExe -h 127.0.0.1 -U postgres -d rsnext_db -f "$RsNextRoot\sql\002_mwl_orders.sql" | Out-Null

# 4. 本番用 server_config.json の適用
Write-Host "[4/8] Windows Server 2025 本番設定 (server_config.json) を適用中..." -ForegroundColor Cyan
$prodCfgRaw = Get-Content "$RsNextRoot\config\server_config.production_2025.json" -Raw -Encoding UTF8 | ConvertFrom-Json
$prodCfgRaw.db.password = $PostgresPassword
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText(
    "$RsNextRoot\config\server_config.json",
    ($prodCfgRaw | ConvertTo-Json -Depth 10),
    $utf8NoBom
)
if (-not (Test-Path "$RsNextRoot\tmp")) {
    New-Item -ItemType Directory -Path "$RsNextRoot\tmp" -Force | Out-Null
}

# 5. SMB共有アクセス権 (NTFS ACL) ＆ Windows Server 2025 ワークグループ互換ポリシー設定
Write-Host "[5/8] 院内LAN共有 (DRS/DRS2) のNTFS権限 ＆ Server 2025 SMB互換ポリシーを設定中..." -ForegroundColor Cyan
# 旧環境の不要な D ルート共有があれば解除
if (Get-SmbShare -Name "D" -ErrorAction SilentlyContinue) {
    Remove-SmbShare -Name "D" -Force -ErrorAction SilentlyContinue
}
# C:\DRS, C:\DRS2 に Everyone 変更権限を付与 (MALL3 患者CSV書込用)
foreach ($rwDir in @("C:\DRS", "C:\DRS2", "C:\FUKUDA_ECG", "C:\RSNextData", "$RsNextRoot\tmp")) {
    if (Test-Path $rwDir) {
        & icacls.exe $rwDir /grant "*S-1-1-0:(OI)(CI)M" /T /C /Q | Out-Null
    }
}
# D:\rsnext_archive, E:\rsnext_active に Everyone 読取権限を付与
foreach ($roDir in @("D:\rsnext_archive", "E:\rsnext_active")) {
    if (Test-Path $roDir) {
        & icacls.exe $roDir /grant "*S-1-1-0:(OI)(CI)RX" /T /C /Q | Out-Null
    }
}
# Windows Server 2025 で既定有効化された SMB署名強制を緩和（旧RS-Base 192.168.1.164 や医療機器・MALL3端末とのSMB接続ブロックを防止）
Set-SmbServerConfiguration -RequireSecuritySignature $false -EnableSMB2Protocol $true -Force -ErrorAction SilentlyContinue
Set-SmbClientConfiguration -RequireSecuritySignature $false -EnableInsecureGuestLogons $true -Force -ErrorAction SilentlyContinue

# 6. Apache 2.4 サービス登録・再起動
Write-Host "[6/8] Apache 2.4 Webサービスを登録・再起動中..." -ForegroundColor Cyan
$httpdExe = "C:\Apache24\bin\httpd.exe"
if (Test-Path $httpdExe) {
    if (-not (Get-Service -Name "Apache2.4" -ErrorAction SilentlyContinue)) {
        & $httpdExe -k install -n "Apache2.4"
    }
    Set-Service -Name "Apache2.4" -StartupType Automatic
    Restart-Service -Name "Apache2.4"
}

# 7. Windows ファイアウォール受信規則の登録 (Web:80/443, FTP:21+PASV, DICOM+MWL:104, Fukuda:1001, SMB:445/139)
Write-Host "[7/8] Windows Server ファイアウォール受信ポート (80, 443, 21, 104, 1001, 445) を開放中..." -ForegroundColor Cyan
$fwRules = @(
    @{ Name = "RSNext_HTTP_80";        Port = "80";          Desc = "RS-Next Web Viewer & API (HTTP)" },
    @{ Name = "RSNext_HTTPS_443";      Port = "443";         Desc = "RS-Next Web Viewer & API (HTTPS)" },
    @{ Name = "RSNext_FTP_21";         Port = "21";          Desc = "RS-Next Endoscope FTP Control" },
    @{ Name = "RSNext_FTP_PASV";       Port = "60000-60099"; Desc = "RS-Next Endoscope FTP Passive" },
    @{ Name = "RSNext_DICOM_MWL_104";  Port = "104";         Desc = "RS-Next DICOM Storage SCP + MWL + MPPS" },
    @{ Name = "RSNext_FukudaECG_1001"; Port = "1001";        Desc = "Fukuda Denshi ECG LAN Receiver" },
    @{ Name = "RSNext_SMB_445";        Port = "445";         Desc = "RS-Next SMB Shares (DRS/DRS2)" },
    @{ Name = "RSNext_NetBIOS_139";    Port = "139";         Desc = "RS-Next Legacy NetBIOS Session" }
)
foreach ($r in $fwRules) {
    Remove-NetFirewallRule -DisplayName $r.Name -ErrorAction SilentlyContinue
    New-NetFirewallRule -DisplayName $r.Name -Direction Inbound -Action Allow -Protocol TCP -LocalPort $r.Port -Description $r.Desc | Out-Null
}

# 8. 常駐 Ingest + MWL サービス ＆ 自動ミラーリング (D->F, E->G) ＆ 8TB HDD 夜間バックアップ登録
Write-Host "[8/8] OS起動時自動常駐サービス ＆ 非RAID自動ミラー・夜間バックアップジョブを登録中..." -ForegroundColor Cyan

# 診断用一時タスクがあれば掃除
foreach ($tmpTask in @("RSNext_DiagRaid", "RSNext_DiagDB")) {
    if (Get-ScheduledTask -TaskName $tmpTask -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $tmpTask -Confirm:$false -ErrorAction SilentlyContinue
    }
}

# 既存の RSNext_Ingest_MWL_Service が動いていれば一度停止して最新設定で再起動
if (Get-ScheduledTask -TaskName "RSNext_Ingest_MWL_Service" -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName "RSNext_Ingest_MWL_Service" -ErrorAction SilentlyContinue
    Get-CimInstance Win32_Process | Where-Object { $_.Name -eq "python.exe" -and $_.CommandLine -match "ingest_service" } | ForEach-Object {
        Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
    }
    Start-Sleep -Seconds 1
}

$actIngest = New-ScheduledTaskAction -Execute $pyExe -Argument "-m ingest_service.run_all" -WorkingDirectory $RsNextRoot
$trgIngest = New-ScheduledTaskTrigger -AtStartup
$setIngest = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName "RSNext_Ingest_MWL_Service" -Action $actIngest -Trigger $trgIngest -Settings $setIngest -User "SYSTEM" -RunLevel Highest -Force | Out-Null
Start-ScheduledTask -TaskName "RSNext_Ingest_MWL_Service"

# (B) E: -> G: 現役SSD 1時間おき高速ミラー
if ((Test-Path "E:\rsnext_active") -and (Test-Path "G:\rsnext_active")) {
    $actMirAct = New-ScheduledTaskAction -Execute "robocopy.exe" -Argument '"E:\rsnext_active" "G:\rsnext_active" /MIR /FFT /R:2 /W:3 /MT:16 /NP /LOG+:"C:\RSNextData\logs\mirror_active.log"'
    $trgMirAct = New-ScheduledTaskTrigger -Once -At "00:15" -RepetitionInterval (New-TimeSpan -Hours 1)
    Register-ScheduledTask -TaskName "RSNext_Mirror_Active_E_to_G" -Action $actMirAct -Trigger $trgMirAct -User "SYSTEM" -RunLevel Highest -Force | Out-Null
}

# (C) D: -> F: アーカイブSSD 日次ミラー
if ((Test-Path "D:\rsnext_archive") -and (Test-Path "F:\rsnext_archive")) {
    $actMirArc = New-ScheduledTaskAction -Execute "robocopy.exe" -Argument '"D:\rsnext_archive" "F:\rsnext_archive" /MIR /FFT /R:2 /W:3 /MT:16 /NP /LOG+:"C:\RSNextData\logs\mirror_archive.log"'
    $trgMirArc = New-ScheduledTaskTrigger -Daily -At "01:30"
    Register-ScheduledTask -TaskName "RSNext_Mirror_Archive_D_to_F" -Action $actMirArc -Trigger $trgMirArc -User "SYSTEM" -RunLevel Highest -Force | Out-Null
}

# (D) H: 8TB HDD 夜間 DBダンプ (30世代保持) ＋ データバックアップ
if (Test-Path "H:\rsnext_backup") {
    $pgDumpExe = Join-Path $pgBinDir "pg_dump.exe"
    $nightlyScript = "C:\RSNextData\run_nightly_backup.ps1"
    $nightlyBody = @"
`$env:PGPASSWORD = '$PostgresPassword'
`$ts = (Get-Date).ToString('yyyyMMdd_HHmm')
`$dumpFile = "H:\rsnext_backup\db_dumps\rsnext_db_`$ts.dump"
& "$pgDumpExe" -h 127.0.0.1 -U postgres -Fc rsnext_db -f `$dumpFile
Get-ChildItem "H:\rsnext_backup\db_dumps" -Filter "rsnext_db_*.dump" | Where-Object { `$_.LastWriteTime -lt (Get-Date).AddDays(-30) } | Remove-Item -Force
if (Test-Path "E:\rsnext_active") {
    & robocopy "E:\rsnext_active" "H:\rsnext_backup\vol_active" /MIR /FFT /R:2 /W:3 /MT:8 /NP /LOG+:"C:\RSNextData\logs\backup_hdd.log" | Out-Null
}
"@
    [System.IO.File]::WriteAllText($nightlyScript, ($nightlyBody -replace "`r?`n", "`r`n"), [System.Text.Encoding]::ASCII)
    $actBk = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$nightlyScript`""
    $trgBk = New-ScheduledTaskTrigger -Daily -At "23:00"
    Register-ScheduledTask -TaskName "RSNext_Nightly_HDD_Backup" -Action $actBk -Trigger $trgBk -User "SYSTEM" -RunLevel Highest -Force | Out-Null
}

Start-Sleep -Seconds 2
Write-Host "`n=================================================================" -ForegroundColor Green
Write-Host " [DONE] RS-Next Windows Server 2025 本番環境の構築が完了しました！" -ForegroundColor Green
Write-Host "  ・Web画面      : http://192.168.1.163/rsnext/"
Write-Host "  ・撮影依頼/MWL : http://192.168.1.163/rsnext/worklist.php"
Write-Host "  ・DICOM/MWL    : Port 104 待受中 / FTP: Port 21 待受中"
Write-Host "=================================================================" -ForegroundColor Green