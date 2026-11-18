#Requires -RunAsAdministrator
# ==============================================================================
# [新サーバ 192.168.1.163 用] データ用 RAID10 (Array 2: Disk 0..3) の単体4本化スクリプト
# - Array 1 (Disk 5: 500GB OS C:) と Array 3 (Disk 4: 8TB HDD) は絶対に変更しない安全ガード付
# ==============================================================================

$ErrorActionPreference = "Continue"
$rcadm = "C:\Program Files\RAIDXpert2\rcadm.exe"

if (-not (Test-Path $rcadm)) {
    throw "rcadm.exe が見つかりません: $rcadm"
}

$q2 = (& $rcadm -M -q -a 2 -v 2>&1) -join "`n"
Write-Host "=== Current Array 2 Status ==="
Write-Host $q2

if ($q2 -match "System Device:\s*\.*\s*No" -and $q2 -match "RAID10") {
    # Windows側で7TB超の非ブートRAIDディスクがあれば先にオフライン化してファイルハンドルを解放
    $raidOsDisk = Get-Disk | Where-Object { -not $_.IsBoot -and -not $_.IsSystem -and ($_.Size / 1GB) -gt 6000 -and $_.FriendlyName -match "Array" } | Select-Object -First 1
    if ($raidOsDisk) {
        Write-Host "Setting OS Disk $($raidOsDisk.Number) offline before splitting Array 2..."
        Set-Disk -Number $raidOsDisk.Number -IsOffline $true -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 2
    }

    Write-Host "Releasing data RAID10 (Array 2)..."
    & $rcadm -D --array 2 --no-ask
    Start-Sleep -Seconds 2
    & $rcadm -M --rescan
    Start-Sleep -Seconds 2

    # 解放された 4TB NVMe SSD (Disk 0, 1, 2, 3) の状態を確認し、必要なら単体 Volume として公開
    $qaAfterDel = (& $rcadm -M -qa 2>&1) -join "`n"
    Write-Host "=== Status right after releasing Array 2 ==="
    Write-Host $qaAfterDel

    $volNames = @("SSD1_ARCHIVE", "SSD2_ACTIVE", "SSD3_MIR_ARCH", "SSD4_MIR_ACT")
    for ($d = 0; $d -lt 4; $d++) {
        # もし自動で LEGACY 単体アレイになっていなければ、単体 Volume (-v) を作成
        $diskInfo = (& $rcadm -M -q -d $d -v 2>&1) -join "`n"
        if ($diskInfo -notmatch "LEGACY") {
            Write-Host "Creating standalone Volume on Disk $d ($($volNames[$d]))..."
            & $rcadm -M --initialize-disk --disk $d 2>&1 | Out-Null
            & $rcadm -C --volume --disk $d --name $volNames[$d]
            Start-Sleep -Seconds 1
        }
    }

    & $rcadm -M --unhide --array * 2>&1 | Out-Null
    & $rcadm -M --rescan 2>&1 | Out-Null
    Update-HostStorageCache -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 3
} else {
    Write-Host "Array 2 is already split or not RAID10. Skipping array split."
}

Write-Host "=== Final rcadm -M -qa ==="
& $rcadm -M -qa
Write-Host "=== Final Windows Get-Disk ==="
Get-Disk | Sort-Object Number | Select-Object Number, FriendlyName, PartitionStyle, OperationalStatus, IsBoot, IsSystem, @{N='SizeGB';E={[math]::Round($_.Size/1GB,1)}} | Format-Table -AutoSize

