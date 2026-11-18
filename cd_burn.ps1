param(
    [string]$ZipUrl = "",
    [string]$SourceDir = "",
    [string]$VolumeName = "MED_EXAM_CD",
    [string]$PatientLabel = ""
)

# ==============================================================================
# RS-Base クライアントPC用 ダイレクトCD/DVDライティングツール (cd_burn.ps1)
# - Windows標準搭載 IMAPI2 (MsftDiscMaster2 / MsftDiscFormat2Data / MsftFileSystemImage) 使用
# - 外部ライティングソフト不要・HTTP環境対応・ISO9660+Jolietハイブリッド出力
# - 書き込み完了後に自動イジェクト＆一時ステージングデータを完全自動消去
# ==============================================================================

$ProgressPreference = 'SilentlyContinue'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.Windows.Forms.Application]::EnableVisualStyles()

if (-not [string]::IsNullOrWhiteSpace($PatientLabel)) {
    try {
        $PatientLabel = [System.Uri]::UnescapeDataString($PatientLabel)
    } catch {}
}

# ボリュームラベルの正規化 (ISO9660互換: 半角英数字とアンダースコア最大16文字)
$safeVol = ($VolumeName -replace '[^A-Za-z0-9_]', '_')
if ([string]::IsNullOrWhiteSpace($safeVol)) { $safeVol = "MED_EXAM_CD" }
if ($safeVol.Length -gt 16) { $safeVol = $safeVol.Substring(0, 16) }

$stageRoot = Join-Path $env:TEMP ("rsbase_cd_stage_" + [guid]::NewGuid().ToString("N").Substring(0, 10))
$script:stagedReady = $false
$script:totalBytes  = 0
$script:fileCount   = 0
$script:isBurning   = $false
$script:recorders   = @()

function Cleanup-Stage {
    if (Test-Path -LiteralPath $stageRoot) {
        Remove-Item -LiteralPath $stageRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Send-RsClientBurnLog([string]$Level, [string]$EventName, [string]$Message, [string]$Detail = "") {
    try {
        $baseDir = if (Test-Path -LiteralPath "C:\RSBASE") { "C:\RSBASE" } else { Join-Path $env:LOCALAPPDATA "RSBASE" }
        $logDir = Join-Path $baseDir "logs"
        if (-not (Test-Path -LiteralPath $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }
        $logFp = Join-Path $logDir "client.log"
        $ts = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
        $line = "$ts [$Level] [cd_burn] [$EventName] $Message" + $(if ($Detail) { " | $Detail" } else { "" })
        Add-Content -LiteralPath $logFp -Value $line -Encoding Default -ErrorAction SilentlyContinue
    } catch {}

    try {
        $urls = @()
        if ($ZipUrl -match '^(https?://[^/]+/rsbase)') {
            $urls += $Matches[1]
        }
        $urls += "http://192.168.1.16/rsbase"
        $urls += "http://127.0.0.1/rsbase"

        $pairs = @{
            action    = "report"
            source    = "cd_burn"
            level     = $Level
            event     = $EventName
            version   = "2.2.2"
            hostname  = $env:COMPUTERNAME
            username  = $env:USERNAME
            client_ts = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
            cdburn_ok = "1"
            message   = $Message
            detail    = $Detail
        }
        $sb = New-Object System.Text.StringBuilder
        foreach ($k in $pairs.Keys) {
            if ($sb.Length -gt 0) { [void]$sb.Append("&") }
            [void]$sb.Append([System.Uri]::EscapeDataString($k) + "=" + [System.Uri]::EscapeDataString([string]$pairs[$k]))
        }
        $bodyBytes = [System.Text.Encoding]::UTF8.GetBytes($sb.ToString())

        foreach ($u in ($urls | Select-Object -Unique)) {
            try {
                $req = [System.Net.HttpWebRequest]::Create("$u/client_logs.php?action=report")
                $req.Method = "POST"
                $req.ContentType = "application/x-www-form-urlencoded; charset=UTF-8"
                $req.Timeout = 2500
                $req.ReadWriteTimeout = 2500
                $req.ContentLength = $bodyBytes.Length
                $st = $req.GetRequestStream()
                $st.Write($bodyBytes, 0, $bodyBytes.Length)
                $st.Close()
                $resp = $req.GetResponse()
                $resp.Close()
                break
            } catch {}
        }
    } catch {}
}

# ------------------------------------------------------------------------------
# GUI 構築
# ------------------------------------------------------------------------------
$form = New-Object System.Windows.Forms.Form
$form.Text = "RS-Base 検査CD 書き込みツール"
$form.Size = New-Object System.Drawing.Size(530, 415)
$form.StartPosition = "CenterScreen"
$form.FormBorderStyle = "FixedDialog"
$form.MaximizeBox = $false
$form.BackColor = [System.Drawing.Color]::FromArgb(248, 250, 252)
$form.Font = New-Object System.Drawing.Font("Yu Gothic UI", 9.5)
$form.TopMost = $true

# ヘッダーパネル
$hdrPanel = New-Object System.Windows.Forms.Panel
$hdrPanel.Dock = "Top"
$hdrPanel.Height = 58
$hdrPanel.BackColor = [System.Drawing.Color]::FromArgb(6, 78, 59)

$hdrTitle = New-Object System.Windows.Forms.Label
$hdrTitle.Text = "💿 検査CD 書き込みツール"
$hdrTitle.ForeColor = [System.Drawing.Color]::White
$hdrTitle.Font = New-Object System.Drawing.Font("Yu Gothic UI", 11.5, [System.Drawing.FontStyle]::Bold)
$hdrTitle.Location = New-Object System.Drawing.Point(16, 10)
$hdrTitle.AutoSize = $true
$hdrPanel.Controls.Add($hdrTitle)

$hdrSub = New-Object System.Windows.Forms.Label
$hdrSub.Text = if ($PatientLabel) { "対象: $PatientLabel  |  医療法人小野会 オリジナルビューワー" } else { "医療法人小野会 オリジナルビューワー収録パッケージ" }
$hdrSub.ForeColor = [System.Drawing.Color]::FromArgb(209, 250, 229)
$hdrSub.Font = New-Object System.Drawing.Font("Yu Gothic UI", 8.8)
$hdrSub.Location = New-Object System.Drawing.Point(18, 34)
$hdrSub.AutoSize = $true
$hdrPanel.Controls.Add($hdrSub)
$form.Controls.Add($hdrPanel)

# ドライブ選択ラベル & コンボボックス
$lblDrive = New-Object System.Windows.Forms.Label
$lblDrive.Text = "書き込み先 光学ドライブ (CD/DVD・外付けUSBドライブ対応):"
$lblDrive.Font = New-Object System.Drawing.Font("Yu Gothic UI", 9, [System.Drawing.FontStyle]::Bold)
$lblDrive.Location = New-Object System.Drawing.Point(18, 72)
$lblDrive.AutoSize = $true
$form.Controls.Add($lblDrive)

$cmbDrive = New-Object System.Windows.Forms.ComboBox
$cmbDrive.DropDownStyle = "DropDownList"
$cmbDrive.Location = New-Object System.Drawing.Point(18, 95)
$cmbDrive.Size = New-Object System.Drawing.Size(292, 28)
$form.Controls.Add($cmbDrive)

$btnRefresh = New-Object System.Windows.Forms.Button
$btnRefresh.Text = "🔄 再検出"
$btnRefresh.Location = New-Object System.Drawing.Point(316, 94)
$btnRefresh.Size = New-Object System.Drawing.Size(78, 29)
$btnRefresh.BackColor = [System.Drawing.Color]::White
$form.Controls.Add($btnRefresh)

$btnEject = New-Object System.Windows.Forms.Button
$btnEject.Text = "⏏ トレイ開閉"
$btnEject.Location = New-Object System.Drawing.Point(400, 94)
$btnEject.Size = New-Object System.Drawing.Size(96, 29)
$btnEject.BackColor = [System.Drawing.Color]::White
$form.Controls.Add($btnEject)

# ディスク状態ボックス
$grpStatus = New-Object System.Windows.Forms.Panel
$grpStatus.Location = New-Object System.Drawing.Point(18, 134)
$grpStatus.Size = New-Object System.Drawing.Size(478, 86)
$grpStatus.BackColor = [System.Drawing.Color]::White
$grpStatus.BorderStyle = "FixedSingle"

$lblMediaStatus = New-Object System.Windows.Forms.Label
$lblMediaStatus.Text = "光学ドライブを確認中..."
$lblMediaStatus.Font = New-Object System.Drawing.Font("Yu Gothic UI", 9.5, [System.Drawing.FontStyle]::Bold)
$lblMediaStatus.Location = New-Object System.Drawing.Point(12, 10)
$lblMediaStatus.Size = New-Object System.Drawing.Size(452, 26)
$grpStatus.Controls.Add($lblMediaStatus)

$lblPkgInfo = New-Object System.Windows.Forms.Label
$lblPkgInfo.Text = "CDデータを準備しています..."
$lblPkgInfo.ForeColor = [System.Drawing.Color]::FromArgb(71, 85, 105)
$lblPkgInfo.Location = New-Object System.Drawing.Point(12, 38)
$lblPkgInfo.Size = New-Object System.Drawing.Size(452, 40)
$grpStatus.Controls.Add($lblPkgInfo)
$form.Controls.Add($grpStatus)

# 進捗バー & 進捗テキスト
$lblProgress = New-Object System.Windows.Forms.Label
$lblProgress.Text = "待機中"
$lblProgress.Location = New-Object System.Drawing.Point(18, 232)
$lblProgress.Size = New-Object System.Drawing.Size(478, 22)
$lblProgress.ForeColor = [System.Drawing.Color]::FromArgb(30, 58, 138)
$form.Controls.Add($lblProgress)

$progBar = New-Object System.Windows.Forms.ProgressBar
$progBar.Location = New-Object System.Drawing.Point(18, 256)
$progBar.Size = New-Object System.Drawing.Size(478, 22)
$progBar.Minimum = 0
$progBar.Maximum = 100
$form.Controls.Add($progBar)

# 自動書き込み開始チェック
$chkAutoBurn = New-Object System.Windows.Forms.CheckBox
$chkAutoBurn.Text = "空のディスクを検知したら自動で書き込みを開始する"
$chkAutoBurn.Location = New-Object System.Drawing.Point(20, 288)
$chkAutoBurn.Size = New-Object System.Drawing.Size(380, 24)
$chkAutoBurn.Checked = $false
$form.Controls.Add($chkAutoBurn)

# アクションボタン
$btnBurn = New-Object System.Windows.Forms.Button
$btnBurn.Text = "💿 CD-Rへ書き込み開始"
$btnBurn.Font = New-Object System.Drawing.Font("Yu Gothic UI", 10, [System.Drawing.FontStyle]::Bold)
$btnBurn.Location = New-Object System.Drawing.Point(18, 322)
$btnBurn.Size = New-Object System.Drawing.Size(340, 40)
$btnBurn.BackColor = [System.Drawing.Color]::FromArgb(2, 132, 199)
$btnBurn.ForeColor = [System.Drawing.Color]::White
$btnBurn.FlatStyle = "Flat"
$btnBurn.Enabled = $false
$form.Controls.Add($btnBurn)

$btnClose = New-Object System.Windows.Forms.Button
$btnClose.Text = "閉じる"
$btnClose.Location = New-Object System.Drawing.Point(368, 322)
$btnClose.Size = New-Object System.Drawing.Size(128, 40)
$btnClose.BackColor = [System.Drawing.Color]::White
$form.Controls.Add($btnClose)

# ------------------------------------------------------------------------------
# 1. CDデータのローカルステージング取得 (ローカル直コピー / HTTP ZIP高速取得 / SMBフォールバック)
# ------------------------------------------------------------------------------
function Prepare-StageData {
    try {
        $lblProgress.Text = "サーバーからCDパッケージデータを取得中..."
        $progBar.Style = "Marquee"
        [System.Windows.Forms.Application]::DoEvents()

        New-Item -ItemType Directory -Path $stageRoot -Force | Out-Null
        $copiedOk = $false

        # (A) ローカルドライブ上のパスであれば直接高速コピー
        if (-not [string]::IsNullOrWhiteSpace($SourceDir) -and ($SourceDir -match '^[A-Za-z]:\\')) {
            try {
                if (Test-Path -LiteralPath (Join-Path $SourceDir "index.html")) {
                    Copy-Item -Path (Join-Path $SourceDir "*") -Destination $stageRoot -Recurse -Force -ErrorAction Stop
                    $copiedOk = $true
                }
            } catch {
                $copiedOk = $false
            }
        }

        # (B) HTTP(S) ZIP URL 経由で高速ダウンロード＆展開 (SMBポート閉塞・VPN環境でも100%動作)
        if (-not $copiedOk -and -not [string]::IsNullOrWhiteSpace($ZipUrl)) {
            $tmpZip = Join-Path $env:TEMP ("rsbase_dl_" + [guid]::NewGuid().ToString("N").Substring(0, 8) + ".zip")
            try {
                $wc = New-Object System.Net.WebClient
                $wc.DownloadFile($ZipUrl, $tmpZip)
                $wc.Dispose()
                if ((Test-Path -LiteralPath $tmpZip) -and ((Get-Item -LiteralPath $tmpZip).Length -gt 100)) {
                    [System.IO.Compression.ZipFile]::ExtractToDirectory($tmpZip, $stageRoot)
                    $copiedOk = $true
                }
            } catch {
                $copiedOk = $false
            } finally {
                if (Test-Path -LiteralPath $tmpZip) {
                    Remove-Item -LiteralPath $tmpZip -Force -ErrorAction SilentlyContinue
                }
            }
        }

        # (C) UNC共有フォルダ (\\192.168.1.16\...) へのフォールバック
        if (-not $copiedOk -and -not [string]::IsNullOrWhiteSpace($SourceDir)) {
            try {
                if (Test-Path -LiteralPath (Join-Path $SourceDir "index.html")) {
                    Copy-Item -Path (Join-Path $SourceDir "*") -Destination $stageRoot -Recurse -Force -ErrorAction Stop
                    $copiedOk = $true
                }
            } catch {
                $copiedOk = $false
            }
        }

        if (-not $copiedOk) {
            throw "書き込み元データ（HTTPサーバーまたは共有フォルダ）にアクセスできませんでした。"
        }

        # 集計
        $files = Get-ChildItem -LiteralPath $stageRoot -Recurse -File
        $script:fileCount = $files.Count
        $sum = ($files | Measure-Object -Property Length -Sum).Sum
        $script:totalBytes = if ($sum) { [int64]$sum } else { 0 }
        $mb = [math]::Round($script:totalBytes / 1MB, 2)

        $lblPkgInfo.Text = "準備完了: 全 $($script:fileCount) ファイル / 合計 $mb MB  (ボリューム名: $safeVol)"
        $progBar.Style = "Blocks"
        $progBar.Value = 0
        $lblProgress.Text = "空のCD-Rをセットして「書き込み開始」を押してください。"
        $script:stagedReady = $true
    } catch {
        $progBar.Style = "Blocks"
        $lblMediaStatus.Text = "❌ データ取得エラー"
        $lblMediaStatus.ForeColor = [System.Drawing.Color]::FromArgb(185, 28, 28)
        $lblPkgInfo.Text = $_.Exception.Message
        $lblProgress.Text = "エラーのため中断しました"
        Send-RsClientBurnLog -Level "ERROR" -EventName "CDBURN_STAGE_FAIL" -Message "CD書き込み用データの取得・展開に失敗しました" -Detail ("err=" + $_.Exception.Message + " | zip=" + $ZipUrl + " | src=" + $SourceDir)
    }
}

# ------------------------------------------------------------------------------
# 2. 光学ドライブ一覧とメディア状態の監視
# ------------------------------------------------------------------------------
function Load-Drives {
    $cmbDrive.Items.Clear()
    $script:recorders = @()
    try {
        $dm = New-Object -ComObject IMAPI2.MsftDiscMaster2
        for ($i = 0; $i -lt $dm.Count; $i++) {
            $uid = $dm.Item($i)
            $rec = New-Object -ComObject IMAPI2.MsftDiscRecorder2
            $rec.InitializeDiscRecorder($uid)
            $vol = ($rec.VolumePathNames | Select-Object -First 1)
            $name = "[$vol] $($rec.VendorId.Trim()) $($rec.ProductId.Trim())"
            $script:recorders += [PSCustomObject]@{
                UniqueId = $uid
                Name     = $name
                Recorder = $rec
            }
            [void]$cmbDrive.Items.Add($name)
        }
        if ($cmbDrive.Items.Count -gt 0) {
            $cmbDrive.SelectedIndex = 0
        } else {
            $lblMediaStatus.Text = "⚠️ CD/DVDドライブが見つかりません（外付け接続後に「再検出」を押してください）"
            $lblMediaStatus.ForeColor = [System.Drawing.Color]::FromArgb(180, 83, 9)
            Send-RsClientBurnLog -Level "WARN" -EventName "CDBURN_NO_DRIVE" -Message "このPCに光学ドライブ(CD/DVD)が検出されませんでした" -Detail ("vol=" + $safeVol)
        }
    } catch {
        $lblMediaStatus.Text = "⚠️ 光学ドライブの検出に失敗しました: $($_.Exception.Message)"
        Send-RsClientBurnLog -Level "ERROR" -EventName "CDBURN_IMAPI_ERR" -Message "光学ドライブ検出(IMAPI2)でエラーが発生しました" -Detail $_.Exception.Message
    }
}

$script:canBurnCurrentMedia = $false
$script:needsEraseRewritable = $false

function Check-MediaStatus {
    if ($script:isBurning) { return }
    $script:canBurnCurrentMedia = $false
    $script:needsEraseRewritable = $false

    if ($cmbDrive.SelectedIndex -lt 0 -or $cmbDrive.SelectedIndex -ge $script:recorders.Count) {
        $btnBurn.Enabled = $false
        return
    }

    $recObj = $script:recorders[$cmbDrive.SelectedIndex].Recorder
    try {
        $fmt = New-Object -ComObject IMAPI2.MsftDiscFormat2Data
        if (-not $fmt.IsRecorderSupported($recObj)) {
            $lblMediaStatus.Text = "⚠️ 選択されたドライブはデータ書き込み非対応です"
            $lblMediaStatus.ForeColor = [System.Drawing.Color]::FromArgb(185, 28, 28)
            $btnBurn.Enabled = $false
            return
        }

        if (-not $fmt.IsCurrentMediaSupported($recObj)) {
            $lblMediaStatus.Text = "🟡 空のCD-R（またはDVD-R）をドライブに入れてください"
            $lblMediaStatus.ForeColor = [System.Drawing.Color]::FromArgb(180, 83, 9)
            $btnBurn.Enabled = $false
            return
        }

        $fmt.Recorder = $recObj
        $fmt.ClientName = "RSBaseCDBurner"
        $isBlank = ($fmt.MediaPhysicallyBlank -or $fmt.MediaHeuristicallyBlank)
        $mediaType = [int]$fmt.CurrentPhysicalMediaType
        # 3 = CDRW, 5 = DVDRAM, 7/8/10/13/18 = Rewritable DVD/BD
        $isRewritable = ($mediaType -in @(3, 5, 7, 8, 10, 13, 18))

        if ($isBlank) {
            $freeBytes = [int64]$fmt.FreeSectorsOnMedia * 2048
            $freeMb = [math]::Round($freeBytes / 1MB, 0)
            if ($script:stagedReady -and $script:totalBytes -gt $freeBytes) {
                $lblMediaStatus.Text = "❌ 容量不足: ディスク空き容量 ($freeMb MB) を超えています"
                $lblMediaStatus.ForeColor = [System.Drawing.Color]::FromArgb(185, 28, 28)
                $btnBurn.Enabled = $false
            } else {
                $lblMediaStatus.Text = "🟢 空のディスクを確認しました (空き容量: 約 $freeMb MB)"
                $lblMediaStatus.ForeColor = [System.Drawing.Color]::FromArgb(21, 128, 61)
                $script:canBurnCurrentMedia = $true
                $btnBurn.Text = "💿 CD-Rへ書き込み開始"
                $btnBurn.Enabled = $script:stagedReady
                if ($script:stagedReady -and $chkAutoBurn.Checked) {
                    Start-DiscBurn
                }
            }
        } elseif ($isRewritable) {
            $lblMediaStatus.Text = "🟠 データ入りのCD-RW/DVD-RWです（消去して上書き可能）"
            $lblMediaStatus.ForeColor = [System.Drawing.Color]::FromArgb(194, 65, 12)
            $script:canBurnCurrentMedia = $true
            $script:needsEraseRewritable = $true
            $btnBurn.Text = "🧹 消去して新しく書き込む"
            $btnBurn.Enabled = $script:stagedReady
        } else {
            $lblMediaStatus.Text = "⚠️ 書き込み済みのCD-Rです。新しい空のCD-Rに交換してください"
            $lblMediaStatus.ForeColor = [System.Drawing.Color]::FromArgb(185, 28, 28)
            $btnBurn.Enabled = $false
        }
    } catch {
        $lblMediaStatus.Text = "🟡 ディスクを読み込み中、または未挿入です..."
        $lblMediaStatus.ForeColor = [System.Drawing.Color]::FromArgb(100, 116, 139)
        $btnBurn.Enabled = $false
    }
}

# ------------------------------------------------------------------------------
# 3. バックグラウンド Runspace による非ブロッキング IMAPI2 書き込み実行
# ------------------------------------------------------------------------------
$script:syncState = [hashtable]::Synchronized(@{
    Running  = $false
    Finished = $false
    Success  = $false
    Phase    = ""
    ErrorMsg = ""
})
$script:bgPowerShell = $null
$script:bgHandle     = $null

function Start-DiscBurn {
    if ($script:isBurning -or -not $script:stagedReady -or -not $script:canBurnCurrentMedia) { return }

    if ($script:needsEraseRewritable) {
        $ans = [System.Windows.Forms.MessageBox]::Show(
            "セットされている書き換え可能ディスク（CD-RW/DVD-RW）の既存データを消去して新しく書き込みますか？",
            "ディスク消去確認",
            [System.Windows.Forms.MessageBoxButtons]::YesNo,
            [System.Windows.Forms.MessageBoxIcon]::Warning
        )
        if ($ans -ne [System.Windows.Forms.DialogResult]::Yes) { return }
    }

    $script:isBurning = $true
    $form.TopMost = $false
    $btnBurn.Enabled = $false
    $btnRefresh.Enabled = $false
    $btnEject.Enabled = $false
    $cmbDrive.Enabled = $false
    $btnClose.Enabled = $false
    $progBar.Style = "Marquee"

    $uid = $script:recorders[$cmbDrive.SelectedIndex].UniqueId
    $doErase = $script:needsEraseRewritable

    $script:syncState.Running  = $true
    $script:syncState.Finished = $false
    $script:syncState.Success  = $false
    $script:syncState.Phase    = "ISO9660 + Joliet イメージを構築しています..."
    $script:syncState.ErrorMsg = ""

    $script:bgPowerShell = [powershell]::Create().AddScript({
        param($RecorderUid, $StagePath, $VolLabel, $NeedErase, $Sync)
        $rec    = $null
        $eraser = $null
        $fmt    = $null
        $fsi    = $null
        $resImg = $null
        $stream = $null
        try {
            $rec = New-Object -ComObject IMAPI2.MsftDiscRecorder2
            $rec.InitializeDiscRecorder($RecorderUid)

            if ($NeedErase) {
                $Sync.Phase = "CD-RW/DVD-RW の既存データをクイック消去しています..."
                $eraser = New-Object -ComObject IMAPI2.MsftDiscFormat2Erase
                $eraser.Recorder = $rec
                $eraser.ClientName = "RSBaseCDBurner"
                $eraser.FullErase = $false
                $eraser.EraseMedia()
            }

            $Sync.Phase = "書き込み用ファイルシステムイメージを構築中..."
            $fmt = New-Object -ComObject IMAPI2.MsftDiscFormat2Data
            $fmt.Recorder = $rec
            $fmt.ClientName = "RSBaseCDBurner"
            # 追記不可（クローズ）にしてどの病院のPC・古いドライブでも100%読める標準CDにする
            $fmt.ForceMediaToBeClosed = $true

            $fsi = New-Object -ComObject IMAPI2FS.MsftFileSystemImage
            $fsi.ChooseImageDefaults($rec)
            # ISO9660 (PACS DICOMDIR用) + Joliet (日本語「閲覧を開く.html」用)
            $fsi.FileSystemsToCreate = 3
            $fsi.VolumeName = $VolLabel
            $fsi.Root.AddTree($StagePath, $false)
            $resImg = $fsi.CreateResultImage()
            $stream = $resImg.ImageStream

            $Sync.Phase = "🔥 ディスクへ書き込み・ファイナライズ中...（完了すると自動でトレイが開きます）"
            $fmt.Write($stream)

            $Sync.Phase = "書き込み完了！トレイを排出しています..."
            try { $rec.EjectMedia() } catch {}

            $Sync.Success = $true
        } catch {
            $Sync.Success = $false
            $Sync.ErrorMsg = $_.Exception.Message
        } finally {
            foreach ($comObj in @($stream, $resImg, $fsi, $fmt, $eraser, $rec)) {
                if ($null -ne $comObj) {
                    try { [void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($comObj) } catch {}
                }
            }
            [GC]::Collect()
            [GC]::WaitForPendingFinalizers()
            $Sync.Running = $false
            $Sync.Finished = $true
        }
    }).AddArgument($uid).AddArgument($stageRoot).AddArgument($safeVol).AddArgument($doErase).AddArgument($script:syncState)

    $script:bgHandle = $script:bgPowerShell.BeginInvoke()
}

# イベントハンドラ設定
$btnRefresh.Add_Click({
    if (-not $script:isBurning) {
        Load-Drives
        Check-MediaStatus
    }
})

$btnEject.Add_Click({
    if ($cmbDrive.SelectedIndex -ge 0 -and $cmbDrive.SelectedIndex -lt $script:recorders.Count) {
        try {
            $script:recorders[$cmbDrive.SelectedIndex].Recorder.EjectMedia()
        } catch {}
    }
})

$cmbDrive.Add_SelectedIndexChanged({ Check-MediaStatus })
$btnBurn.Add_Click({ Start-DiscBurn })
$btnClose.Add_Click({ $form.Close() })

# 状態監視タイマー (800ms間隔)
$pollTimer = New-Object System.Windows.Forms.Timer
$pollTimer.Interval = 800
$pollTimer.Add_Tick({
    if ($script:isBurning) {
        $lblProgress.Text = $script:syncState.Phase
        if ($script:syncState.Finished) {
            $script:isBurning = $false
            if ($script:bgPowerShell) {
                try { $script:bgPowerShell.EndInvoke($script:bgHandle) } catch {}
                $script:bgPowerShell.Dispose()
                $script:bgPowerShell = $null
            }
            $progBar.Style = "Blocks"
            $btnRefresh.Enabled = $true
            $btnEject.Enabled = $true
            $cmbDrive.Enabled = $true
            $btnClose.Enabled = $true

            if ($script:syncState.Success) {
                Send-RsClientBurnLog -Level "INFO" -EventName "CDBURN_OK" -Message "検査CDの書き込みとファイナライズが完了しました" -Detail ("vol=" + $safeVol + " | files=" + $script:fileCount)
                $progBar.Value = 100
                $lblMediaStatus.Text = "✅ CDの作成が正常に完了しました！"
                $lblMediaStatus.ForeColor = [System.Drawing.Color]::FromArgb(21, 128, 61)
                $lblProgress.Text = "ディスクを取り出してラベルをご記入ください。"
                [System.Media.SystemSounds]::Asterisk.Play()
                [System.Windows.Forms.MessageBox]::Show(
                    "検査CDへの書き込みとファイナライズが正常に完了しました！`n排出されたディスクを取り出してください。",
                    "CD作成完了",
                    [System.Windows.Forms.MessageBoxButtons]::OK,
                    [System.Windows.Forms.MessageBoxIcon]::Information
                ) | Out-Null
                $form.Close()
            } else {
                Send-RsClientBurnLog -Level "ERROR" -EventName "CDBURN_FAIL" -Message "CD書き込み中にエラーが発生しました" -Detail ("err=" + $script:syncState.ErrorMsg + " | vol=" + $safeVol)
                $progBar.Value = 0
                $lblMediaStatus.Text = "❌ 書き込みエラーが発生しました"
                $lblMediaStatus.ForeColor = [System.Drawing.Color]::FromArgb(185, 28, 28)
                $lblProgress.Text = $script:syncState.ErrorMsg
                [System.Windows.Forms.MessageBox]::Show(
                    "CD書き込み中にエラーが発生しました:`n" + $script:syncState.ErrorMsg,
                    "書き込みエラー",
                    [System.Windows.Forms.MessageBoxButtons]::OK,
                    [System.Windows.Forms.MessageBoxIcon]::Error
                ) | Out-Null
                Check-MediaStatus
            }
        }
    } else {
        Check-MediaStatus
    }
})

$form.Add_Shown({
    $form.Activate()
    Load-Drives
    Prepare-StageData
    Check-MediaStatus
    $pollTimer.Start()
})

$form.Add_FormClosing({
    if ($script:isBurning) {
        $_.Cancel = $true
        [System.Windows.Forms.MessageBox]::Show(
            "現在ディスクへ書き込み中です。破損防止のため完了するまで閉じないでください。",
            "書き込み実行中",
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Warning
        ) | Out-Null
        return
    }
    $pollTimer.Stop()
    Cleanup-Stage
})

[void]$form.ShowDialog()
Cleanup-Stage
