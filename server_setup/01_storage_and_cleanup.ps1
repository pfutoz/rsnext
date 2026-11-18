#Requires -RunAsAdministrator
# ==============================================================================
# [新サーバ 192.168.1.163 のリモートデスクトップ上で管理者PowerShell実行]
# Step 1: 旧DBサービスの停止 ＆ 非RAID マルチSSD (D:/E:/F:/G:) + 8TB HDD (H:) 整備
# ※ Cドライブ (Windows Server 2025 OS) は一切フォーマット・変更しません！
# ==============================================================================

$ErrorActionPreference = "Stop"

Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host " RS-Next Server 2025 セットアップ [Step 1: 旧環境停止＆ディスク整備]" -ForegroundColor Cyan
Write-Host "=================================================================" -ForegroundColor Cyan

# 1. 不要な旧 PostgreSQL 9.3 サービス・旧 Apache サービスの停止・無効化
foreach ($svcName in @("postgresql-x64-9.3", "Apache2.4", "Apache2")) {
    $svc = Get-Service -Name $svcName -ErrorAction SilentlyContinue
    if ($svc) {
        Write-Host "[1/4] 旧サービス '$svcName' を停止・無効化しています..." -ForegroundColor Yellow
        if ($svc.Status -ne "Stopped") {
            Stop-Service -Name $svcName -Force -ErrorAction SilentlyContinue
        }
        if ($svcName -eq "postgresql-x64-9.3") {
            Set-Service -Name $svcName -StartupType Disabled
        }
    }
}

# 2. ディスク確認 (OSブートディスク Disk 0 は絶対に対象外として保護)
Write-Host "`n[2/4] 接続されているストレージを確認しています..." -ForegroundColor Cyan
$allDisks = Get-Disk | Sort-Object Number
$allDisks | Select-Object Number, FriendlyName, PartitionStyle, IsBoot, IsSystem, @{N='SizeGB';E={[math]::Round($_.Size/1GB,1)}} | Format-Table -AutoSize

$nonBootDisks = $allDisks | Where-Object { -not $_.IsBoot -and -not $_.IsSystem }

# もし 7TB超の AMD-RAID Array がまだ残っている場合は安全に案内して一時停止
$stillRaid = $nonBootDisks | Where-Object { $_.FriendlyName -match "Array" -and ($_.Size / 1GB) -gt 6000 }
if ($stillRaid) {
    Write-Host "[WARN] 注意: データ用の4TB SSD×4本がまだ1つのRAIDアレイ ($($stillRaid.FriendlyName): $([math]::Round($stillRaid.Size/1GB,0)) GB) になっています。" -ForegroundColor Yellow
    Write-Host "先に AMD RAIDXpert2 でこのアレイを削除し、4本の単体 Volume (約3726 GB × 4本) を作成してから再実行してください。" -ForegroundColor Yellow
    Write-Host "（詳しい画面手順は C:\Apache24\htdocs\rsnext\仕様書\server_setup_guide.html を開いてご覧ください）"
    exit 1
}

# 3. 8TB HDD を H: ドライブへ割り当て（現在 E: 等にある場合は自動で H: へ移動）
$hddDisk = $nonBootDisks | Where-Object { $_.FriendlyName -match "WDC|WD80" -or ($_.Size / 1GB) -gt 6500 } | Select-Object -First 1
if ($hddDisk) {
    Write-Host "[3/4] 8TB バックアップHDD (Disk $($hddDisk.Number): $($hddDisk.FriendlyName)) を H: ドライブへ設定中..." -ForegroundColor Cyan
    if ($hddDisk.PartitionStyle -eq "RAW") {
        Initialize-Disk -Number $hddDisk.Number -PartitionStyle GPT
        New-Partition -DiskNumber $hddDisk.Number -UseMaximumSize -DriveLetter H | Format-Volume -FileSystem NTFS -NewFileSystemLabel "RSNEXT_BACKUP_8TB" -Confirm:$false | Out-Null
    } else {
        $hddPart = Get-Partition -DiskNumber $hddDisk.Number | Where-Object { $_.Type -ne "Reserved" -and $_.Size -gt 100GB } | Select-Object -First 1
        if ($hddPart -and $hddPart.DriveLetter -ne 'H') {
            Set-Partition -DiskNumber $hddDisk.Number -PartitionNumber $hddPart.PartitionNumber -NewDriveLetter H
        }
    }
}

# 4. 4本の 4TB SSD を D: (VOL_ARCHIVE), E: (VOL_ACTIVE), F: (MIRROR_ARCHIVE), G: (MIRROR_ACTIVE) に整形・割当
$ssdDisks = $nonBootDisks | Where-Object {
    ($hddDisk -eq $null -or $_.Number -ne $hddDisk.Number) -and (($_.Size / 1GB) -ge 3000 -and ($_.Size / 1GB) -le 4500)
} | Sort-Object Number

$targetSpec = @(
    @{ Letter = 'D'; Label = 'VOL_ARCHIVE_SSD1' },
    @{ Letter = 'E'; Label = 'VOL_ACTIVE_SSD2'  },
    @{ Letter = 'F'; Label = 'MIRROR_ARCH_SSD3' },
    @{ Letter = 'G'; Label = 'MIRROR_ACT_SSD4'  }
)

if ($ssdDisks.Count -ge 4) {
    Write-Host "[4/4] 4TB SSD × 4本を単体NTFSドライブ (D:, E:, F:, G:) として準備中..." -ForegroundColor Cyan
    for ($i = 0; $i -lt 4; $i++) {
        $d = $ssdDisks[$i]
        $spec = $targetSpec[$i]
        $ltr = $spec.Letter
        $lbl = $spec.Label

        if ($d.IsOffline) { Set-Disk -Number $d.Number -IsOffline $false }
        if ($d.IsReadOnly) { Set-Disk -Number $d.Number -IsReadOnly $false }

        if ($d.PartitionStyle -eq "RAW") {
            Initialize-Disk -Number $d.Number -PartitionStyle GPT
            New-Partition -DiskNumber $d.Number -UseMaximumSize -DriveLetter $ltr |
                Format-Volume -FileSystem NTFS -NewFileSystemLabel $lbl -Confirm:$false | Out-Null
        } else {
            $p = Get-Partition -DiskNumber $d.Number | Where-Object { $_.Type -ne "Reserved" -and $_.Size -gt 100GB } | Select-Object -First 1
            if ($p) {
                if ($p.DriveLetter -ne $ltr) {
                    Set-Partition -DiskNumber $d.Number -PartitionNumber $p.PartitionNumber -NewDriveLetter $ltr
                }
                Set-Volume -DriveLetter $ltr -NewFileSystemLabel $lbl -ErrorAction SilentlyContinue
            }
        }
        Write-Host "  -> Disk $($d.Number) => ${ltr}:\ ($lbl) OK" -ForegroundColor Green
    }
} else {
    Write-Host "[INFO] 検出された4TB単体SSD数: $($ssdDisks.Count) 本（4本認識後にD:?G:自動割当を行います）" -ForegroundColor Yellow
}

# 5. RS-Next 本番ディレクトリ階層＆MALL3用共有フォルダ (DRS / DRS2) 作成
$dirs = @(
    "C:\RSNextData\cache\thumbs",
    "C:\RSNextData\cache\ecg_json",
    "C:\RSNextData\inbox\ftp",
    "C:\RSNextData\inbox\dicom_scp",
    "C:\RSNextData\inbox\drop",
    "C:\RSNextData\quarantine",
    "C:\RSNextData\outbox\orders",
    "C:\RSNextData\logs",
    "C:\FUKUDA_ECG\ORG",
    "C:\DRS",
    "C:\DRS2"
)
if (Test-Path "D:\") { $dirs += "D:\rsnext_archive\studies" }
if (Test-Path "E:\") { $dirs += "E:\rsnext_active\studies" }
if (Test-Path "F:\") { $dirs += "F:\rsnext_archive" }
if (Test-Path "G:\") { $dirs += "G:\rsnext_active" }
if (Test-Path "H:\") {
    $dirs += "H:\rsnext_backup\db_dumps"
    $dirs += "H:\rsnext_backup\vol_active"
    $dirs += "H:\rsnext_backup\vol_archive"
}

foreach ($dir in $dirs) {
    if (-not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
}

# MALL3 連携用 SMB 共有 (DRS / DRS2) ＆ オプション互換用共有 (rsnext_archive / rsnext_active) の作成
foreach ($shareName in @("DRS", "DRS2")) {
    $sPath = "C:\$shareName"
    if (-not (Get-SmbShare -Name $shareName -ErrorAction SilentlyContinue)) {
        New-SmbShare -Name $shareName -Path $sPath -FullAccess "Everyone" | Out-Null
        Write-Host "  -> SMB共有 \\$env:COMPUTERNAME\$shareName ($sPath) を公開しました" -ForegroundColor Green
    }
}
foreach ($roShare in @(
    @{ Name = "rsnext_archive"; Path = "D:\rsnext_archive" },
    @{ Name = "rsnext_active";  Path = "E:\rsnext_active"  }
)) {
    if ((Test-Path $roShare.Path) -and (-not (Get-SmbShare -Name $roShare.Name -ErrorAction SilentlyContinue))) {
        New-SmbShare -Name $roShare.Name -Path $roShare.Path -FullAccess "Administrators","SYSTEM" -ReadAccess "Everyone" | Out-Null
        Write-Host "  -> SMB共有 \\$env:COMPUTERNAME\$($roShare.Name) ($($roShare.Path)) を公開しました" -ForegroundColor Green
    }
}

Write-Host "`n[OK] Step 1 完了！続いて 02_install_services_and_prod_config.ps1 を実行してください。" -ForegroundColor Green
