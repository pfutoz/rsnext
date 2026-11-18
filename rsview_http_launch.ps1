param(
    [string]$BundleUrl = "",
    [string]$CacheKey = "",
    [string]$ServerBase = "",
    [switch]$ProvisionOnly
)

# ==============================================================================
# RS-Base / RS-Next 共通クライアント HTTP DICOM キャッシュ & RSViewer 起動ランチャー
# - サーバ共有フォルダ (SMB) 完全不要・純粋HTTPストリーミング対応
# - クライアント未導入時は RSViewer_pkg.zip をHTTP経由で自動セットアップ
# - 既存環境の RSB_Server.dat 共有パス設定もローカル完結設定へ自動補正
# - ローカルキャッシュ (C:\RSBASE\dicom_cache) 3日間自動保持・LRU自動掃除
# ==============================================================================

$ProgressPreference = 'SilentlyContinue'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.Windows.Forms.Application]::EnableVisualStyles()

function Resolve-RsBaseClientRoot {
    $primary = "C:\RSBASE"
    try {
        if (-not (Test-Path -LiteralPath $primary)) {
            New-Item -ItemType Directory -Path $primary -Force | Out-Null
        }
        $probe = Join-Path $primary ("_probe_" + [guid]::NewGuid().ToString("N").Substring(0, 6) + ".tmp")
        [System.IO.File]::WriteAllText($probe, "ok")
        Remove-Item -LiteralPath $probe -Force -ErrorAction SilentlyContinue
        return $primary
    } catch {
        $fb = Join-Path $env:LOCALAPPDATA "RSBASE"
        if (-not (Test-Path -LiteralPath $fb)) {
            New-Item -ItemType Directory -Path $fb -Force | Out-Null
        }
        return $fb
    }
}

$script:clientRoot = Resolve-RsBaseClientRoot
$script:pubHtmlDir = Join-Path $script:clientRoot "RSB_public_html"
$script:viewerDir  = Join-Path $script:pubHtmlDir "RSViewer"
$script:viewerExe  = Join-Path $script:viewerDir "RSView.exe"
$script:viewer2d   = Join-Path $script:viewerDir "RSView2D.exe"
$script:cacheRoot  = Join-Path $script:clientRoot "dicom_cache"

function Get-ServerCandidates {
    $list = New-Object System.Collections.Generic.List[string]
    if (-not [string]::IsNullOrWhiteSpace($ServerBase)) {
        $list.Add($ServerBase.TrimEnd('/'))
    }
    if ($BundleUrl -match '^(https?://[^/]+/(?:rsnext|rsbase))') {
        $u = $Matches[1].TrimEnd('/')
        if (-not $list.Contains($u)) { $list.Add($u) }
    }
    foreach ($def in @("http://192.168.1.163/rsnext", "http://192.168.1.16/rsbase", "http://127.0.0.1/rsnext", "http://127.0.0.1/rsbase")) {
        if (-not $list.Contains($def)) { $list.Add($def) }
    }
    return $list
}

function Send-ClientLaunchReport([string]$Level, [string]$EventName, [string]$Message, [string]$Detail = "") {
    try {
        $logDir = Join-Path $script:clientRoot "logs"
        if (-not (Test-Path -LiteralPath $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }
        $logFp = Join-Path $logDir "client.log"
        $ts = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
        $line = "$ts [$Level] [rsview_http] [$EventName] $Message" + $(if ($Detail) { " | $Detail" } else { "" })
        Add-Content -LiteralPath $logFp -Value $line -Encoding Default -ErrorAction SilentlyContinue
    } catch {}

    try {
        $verTxt = "2.4.0"
        $verFp = Join-Path $script:clientRoot "watcher_version.txt"
        if (Test-Path -LiteralPath $verFp) {
            $vRead = ([System.IO.File]::ReadAllText($verFp)).Trim()
            if ($vRead) { $verTxt = $vRead }
        }
        $pairs = @{
            action       = "report"
            source       = "rsview_http"
            level        = $Level
            event        = $EventName
            version      = $verTxt
            hostname     = $env:COMPUTERNAME
            username     = $env:USERNAME
            install_dir  = $script:clientRoot
            client_ts    = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
            rsview_ok    = $(if (Test-Path -LiteralPath $script:viewerExe) { "1" } else { "0" })
            record_event = "1"
            message      = $Message
            detail       = $Detail
        }
        $sb = New-Object System.Text.StringBuilder
        foreach ($k in $pairs.Keys) {
            if ($sb.Length -gt 0) { [void]$sb.Append("&") }
            [void]$sb.Append([System.Uri]::EscapeDataString($k) + "=" + [System.Uri]::EscapeDataString([string]$pairs[$k]))
        }
        $bodyBytes = [System.Text.Encoding]::UTF8.GetBytes($sb.ToString())

        foreach ($u in @("http://192.168.1.163/rsnext", "http://192.168.1.16/rsbase")) {
            try {
                $req = [System.Net.HttpWebRequest]::Create("$u/client_logs.php?action=report")
                $req.Method = "POST"
                $req.ContentType = "application/x-www-form-urlencoded; charset=UTF-8"
                $req.Timeout = 2000
                $req.ReadWriteTimeout = 2000
                $req.ContentLength = $bodyBytes.Length
                $st = $req.GetRequestStream()
                $st.Write($bodyBytes, 0, $bodyBytes.Length)
                $st.Close()
                $resp = $req.GetResponse()
                $resp.Close()
            } catch {}
        }
    } catch {}
}

function New-BannerForm([string]$Headline, [string]$SubText) {
    $frm = New-Object System.Windows.Forms.Form
    $frm.Text = "RSViewer 起動準備中"
    $frm.ClientSize = New-Object System.Drawing.Size(430, 92)
    $frm.FormBorderStyle = [System.Windows.Forms.FormBorderStyle]::FixedToolWindow
    $frm.ControlBox = $false
    $frm.StartPosition = [System.Windows.Forms.FormStartPosition]::CenterScreen
    $frm.TopMost = $true
    $frm.BackColor = [System.Drawing.Color]::FromArgb(15, 23, 42)

    $lbl1 = New-Object System.Windows.Forms.Label
    $lbl1.Text = $Headline
    $lbl1.ForeColor = [System.Drawing.Color]::White
    $lbl1.Font = New-Object System.Drawing.Font("Yu Gothic UI", 10.5, [System.Drawing.FontStyle]::Bold)
    $lbl1.Location = New-Object System.Drawing.Point(16, 14)
    $lbl1.Size = New-Object System.Drawing.Size(398, 24)

    $lbl2 = New-Object System.Windows.Forms.Label
    $lbl2.Text = $SubText
    $lbl2.ForeColor = [System.Drawing.Color]::FromArgb(148, 163, 184)
    $lbl2.Font = New-Object System.Drawing.Font("Yu Gothic UI", 8.5, [System.Drawing.FontStyle]::Regular)
    $lbl2.Location = New-Object System.Drawing.Point(16, 42)
    $lbl2.Size = New-Object System.Drawing.Size(398, 20)

    $pb = New-Object System.Windows.Forms.ProgressBar
    $pb.Style = [System.Windows.Forms.ProgressBarStyle]::Marquee
    $pb.MarqueeAnimationSpeed = 25
    $pb.Location = New-Object System.Drawing.Point(16, 66)
    $pb.Size = New-Object System.Drawing.Size(398, 12)

    $frm.Controls.Add($lbl1)
    $frm.Controls.Add($lbl2)
    $frm.Controls.Add($pb)
    $frm.Tag = @{ Title = $lbl1; Sub = $lbl2 }
    $frm.Show()
    [System.Windows.Forms.Application]::DoEvents()
    return $frm
}

function Update-BannerForm($Form, [string]$Headline, [string]$SubText) {
    if ($null -eq $Form) { return }
    try {
        if ($Headline) { $Form.Tag.Title.Text = $Headline }
        if ($SubText)  { $Form.Tag.Sub.Text   = $SubText }
        $Form.Refresh()
        [System.Windows.Forms.Application]::DoEvents()
    } catch {}
}

function Download-HttpFile([string]$Url, [string]$DestPath, [int]$TimeoutMs = 60000) {
    $req = [System.Net.HttpWebRequest]::Create($Url)
    $req.Method = "GET"
    $req.Timeout = 8000
    $req.ReadWriteTimeout = $TimeoutMs
    $req.CachePolicy = New-Object System.Net.Cache.RequestCachePolicy([System.Net.Cache.RequestCacheLevel]::NoCacheNoStore)
    $resp = $req.GetResponse()
    try {
        $inStream = $resp.GetResponseStream()
        $outStream = [System.IO.File]::Create($DestPath)
        try {
            $buf = New-Object byte[] 65536
            while (($read = $inStream.Read($buf, 0, $buf.Length)) -gt 0) {
                $outStream.Write($buf, 0, $read)
                [System.Windows.Forms.Application]::DoEvents()
            }
        } finally {
            $outStream.Close()
            $inStream.Close()
        }
    } finally {
        $resp.Close()
    }
}

function Extract-ZipOverwrite([string]$ZipPath, [string]$DestDir) {
    if (-not (Test-Path -LiteralPath $DestDir)) {
        New-Item -ItemType Directory -Path $DestDir -Force | Out-Null
    }
    $fullDestRoot = [System.IO.Path]::GetFullPath($DestDir)
    if (-not $fullDestRoot.EndsWith("\")) { $fullDestRoot += "\" }

    $za = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
    try {
        foreach ($entry in $za.Entries) {
            $rel = $entry.FullName.Replace('/', '\').TrimStart('\')
            if ([string]::IsNullOrWhiteSpace($rel)) { continue }
            $targetPath = [System.IO.Path]::GetFullPath((Join-Path $fullDestRoot $rel))
            if (-not $targetPath.StartsWith($fullDestRoot, [System.StringComparison]::OrdinalIgnoreCase)) { continue }

            if ($entry.FullName.EndsWith("/") -or $entry.FullName.EndsWith("\")) {
                if (-not (Test-Path -LiteralPath $targetPath)) {
                    New-Item -ItemType Directory -Path $targetPath -Force | Out-Null
                }
            } else {
                $parent = [System.IO.Path]::GetDirectoryName($targetPath)
                if (-not (Test-Path -LiteralPath $parent)) {
                    New-Item -ItemType Directory -Path $parent -Force | Out-Null
                }
                [System.IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $targetPath, $true)
            }
        }
    } finally {
        $za.Dispose()
    }
}

function Ensure-LocalRSViewer($BannerRef) {
    $licInViewer = Join-Path $script:viewerDir "RSView_LIC.dat"
    $licInParent = Join-Path $script:pubHtmlDir "RSView_LIC.dat"
    $serverDat   = Join-Path $script:viewerDir "RSB_Server.dat"

    $needsPkg = (-not (Test-Path -LiteralPath $script:viewerExe)) -or
                (-not (Test-Path -LiteralPath $script:viewer2d)) -or
                ((-not (Test-Path -LiteralPath $licInViewer)) -and (-not (Test-Path -LiteralPath $licInParent)))

    if ($needsPkg) {
        $ownBanner = $false
        if (($null -eq $BannerRef) -and (-not $ProvisionOnly)) {
            $BannerRef = New-BannerForm "RSViewer を自動セットアップしています..." "初回のみサーバからHTTP経由でビューワ本体を配置します（共有フォルダ不要）"
            $ownBanner = $true
        } else {
            Update-BannerForm $BannerRef "RSViewer を自動セットアップしています..." "初回のみサーバからHTTP経由でビューワ本体を配置します"
        }

        $tmpPkg = Join-Path $env:TEMP ("rsviewer_pkg_" + [guid]::NewGuid().ToString("N").Substring(0, 8) + ".zip")
        $downloaded = $false
        $usedUrl = ""

        foreach ($base in (Get-ServerCandidates)) {
            try {
                $pkgUrl = "$base/RSViewer_pkg.zip?t=" + [DateTime]::UtcNow.Ticks
                Download-HttpFile -Url $pkgUrl -DestPath $tmpPkg -TimeoutMs 45000
                if ((Test-Path -LiteralPath $tmpPkg) -and ((Get-Item -LiteralPath $tmpPkg).Length -gt 1000000)) {
                    $downloaded = $true
                    $usedUrl = $base
                    break
                }
            } catch {}
        }

        if ($downloaded) {
            try {
                Extract-ZipOverwrite -ZipPath $tmpPkg -DestDir $script:viewerDir
                Send-ClientLaunchReport "INFO" "RSVIEW_PROVISION_OK" "RSViewer をローカルへ自動インストールしました" ("source=" + $usedUrl + " | dir=" + $script:viewerDir)
            } finally {
                Remove-Item -LiteralPath $tmpPkg -Force -ErrorAction SilentlyContinue
            }
        } else {
            Remove-Item -LiteralPath $tmpPkg -Force -ErrorAction SilentlyContinue
            if ($ownBanner -and $null -ne $BannerRef) { $BannerRef.Close(); $BannerRef.Dispose() }
            Send-ClientLaunchReport "ERROR" "RSVIEW_PROVISION_FAIL" "RSViewer_pkg.zip のHTTP取得に失敗しました" ("viewerDir=" + $script:viewerDir)
            return $false
        }

        if ($ownBanner -and $null -ne $BannerRef) {
            $BannerRef.Close()
            $BannerRef.Dispose()
        }
    }

    if (-not (Test-Path -LiteralPath $script:viewerExe)) {
        return $false
    }

    # ゼロSMB構成を強制保証: RSB_Server.dat をローカル C:\RSBASE\RSB_public_html\ へ向け、ライセンスを両方へ配置
    try {
        if (-not (Test-Path -LiteralPath $script:pubHtmlDir)) {
            New-Item -ItemType Directory -Path $script:pubHtmlDir -Force | Out-Null
        }
        $desiredDat = $script:pubHtmlDir.TrimEnd('\') + "\" + "`r`n"
        $curDat = ""
        if (Test-Path -LiteralPath $serverDat) {
            $curDat = [System.IO.File]::ReadAllText($serverDat, [System.Text.Encoding]::GetEncoding(932))
        }
        if ($curDat -ne $desiredDat) {
            [System.IO.File]::WriteAllText($serverDat, $desiredDat, [System.Text.Encoding]::GetEncoding(932))
        }
        if ((Test-Path -LiteralPath $licInViewer) -and (-not (Test-Path -LiteralPath $licInParent))) {
            Copy-Item -LiteralPath $licInViewer -Destination $licInParent -Force
        } elseif ((Test-Path -LiteralPath $licInParent) -and (-not (Test-Path -LiteralPath $licInViewer))) {
            Copy-Item -LiteralPath $licInParent -Destination $licInViewer -Force
        }
    } catch {}

    return $true
}

function Purge-OldDicomCache {
    try {
        if (-not (Test-Path -LiteralPath $script:cacheRoot)) {
            New-Item -ItemType Directory -Path $script:cacheRoot -Force | Out-Null
            return
        }
        $cutoff = (Get-Date).AddDays(-3)
        $dirs = @(Get-ChildItem -LiteralPath $script:cacheRoot -Directory -ErrorAction SilentlyContinue)
        foreach ($d in $dirs) {
            $marker = Join-Path $script:cacheRoot ($d.Name + ".ready")
            $refTime = if (Test-Path -LiteralPath $marker) { (Get-Item -LiteralPath $marker).LastWriteTime } else { $d.LastWriteTime }
            if ($refTime -lt $cutoff) {
                Remove-Item -LiteralPath $d.FullName -Recurse -Force -ErrorAction SilentlyContinue
                Remove-Item -LiteralPath $marker -Force -ErrorAction SilentlyContinue
            }
        }
        # 最大40検査まで保持（古い順LRU整理）
        $remain = @(Get-ChildItem -LiteralPath $script:cacheRoot -Directory -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending)
        if ($remain.Count -gt 40) {
            for ($i = 40; $i -lt $remain.Count; $i++) {
                $oldDir = $remain[$i]
                $marker = Join-Path $script:cacheRoot ($oldDir.Name + ".ready")
                Remove-Item -LiteralPath $oldDir.FullName -Recurse -Force -ErrorAction SilentlyContinue
                Remove-Item -LiteralPath $marker -Force -ErrorAction SilentlyContinue
            }
        }
    } catch {}
}

# 1. ProvisionOnly モード（セットアップ時・常駐起動時のサイレント事前配備）
if ($ProvisionOnly) {
    [void](Ensure-LocalRSViewer $null)
    exit 0
}

if ([string]::IsNullOrWhiteSpace($BundleUrl)) {
    exit 1
}

Purge-OldDicomCache

# 2. キャッシュキー正規化
$safeKey = ($CacheKey -replace '[^A-Za-z0-9_\-]', '_')
if ([string]::IsNullOrWhiteSpace($safeKey)) {
    $md5 = [System.Security.Cryptography.MD5]::Create()
    $hashBytes = $md5.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($BundleUrl))
    $safeKey = "dcm_" + ([System.BitConverter]::ToString($hashBytes).Replace("-", "").Substring(0, 14).ToLowerInvariant())
}

$studyCacheDir = Join-Path $script:cacheRoot $safeKey
$readyMarker   = Join-Path $script:cacheRoot ($safeKey + ".ready")
$isCached      = (Test-Path -LiteralPath $readyMarker) -and (Test-Path -LiteralPath $studyCacheDir) -and (@(Get-ChildItem -LiteralPath $studyCacheDir -Recurse -File -ErrorAction SilentlyContinue).Count -gt 0)

$banner = $null
try {
    if (-not $isCached) {
        $banner = New-BannerForm "断層DICOMデータをローカル取得中..." "HTTP経由で検査スライスを高速キャッシュしています（共有フォルダ不要）"
    }

    if (-not (Ensure-LocalRSViewer $banner)) {
        if ($null -ne $banner) { $banner.Close(); $banner.Dispose(); $banner = $null }
        [System.Windows.Forms.MessageBox]::Show(
            "このPCに RSViewer を準備できませんでした。`r`n院内ネットワーク接続をご確認いただくか、RSBaseSetup.exe を実行してください。",
            "RSViewer セットアップエラー",
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Warning
        ) | Out-Null
        exit 1
    }

    if (-not $isCached) {
        Update-BannerForm $banner "断層DICOMデータをローカル取得中..." "サーバから検査スライスをダウンロードしています..."
        $tmpBundle = Join-Path $env:TEMP ("rsnext_dcm_" + $safeKey + "_" + [guid]::NewGuid().ToString("N").Substring(0, 6) + ".zip")
        try {
            Download-HttpFile -Url $BundleUrl -DestPath $tmpBundle -TimeoutMs 120000
            if (-not (Test-Path -LiteralPath $tmpBundle) -or ((Get-Item -LiteralPath $tmpBundle).Length -lt 100)) {
                throw "サーバからDICOMバンドルを取得できませんでした。"
            }
            Update-BannerForm $banner "RSViewer を起動しています..." "ローカルキャッシュへ展開中..."
            if (Test-Path -LiteralPath $studyCacheDir) {
                Remove-Item -LiteralPath $studyCacheDir -Recurse -Force -ErrorAction SilentlyContinue
            }
            Extract-ZipOverwrite -ZipPath $tmpBundle -DestDir $studyCacheDir
            [System.IO.File]::WriteAllText($readyMarker, (Get-Date).ToString("o"))
        } finally {
            Remove-Item -LiteralPath $tmpBundle -Force -ErrorAction SilentlyContinue
        }
    } else {
        try { (Get-Item -LiteralPath $readyMarker).LastWriteTime = Get-Date } catch {}
    }

    if ($null -ne $banner) {
        $banner.Close()
        $banner.Dispose()
        $banner = $null
    }

    $sliceCount = @(Get-ChildItem -LiteralPath $studyCacheDir -Recurse -File -ErrorAction SilentlyContinue).Count
    if ($sliceCount -eq 0) {
        Remove-Item -LiteralPath $readyMarker -Force -ErrorAction SilentlyContinue
        throw "展開後のDICOMファイルが0件でした。"
    }

    Start-Process -FilePath $script:viewerExe -ArgumentList @("""$studyCacheDir""") -WorkingDirectory $script:viewerDir
    Send-ClientLaunchReport "INFO" "TRIGGER_OK" ("RSViewer をゼロSMB(HTTPキャッシュ)で起動しました (" + $sliceCount + " files)") ("mode=HTTP_DICOM | key=" + $safeKey + " | cached=" + $isCached + " | url=" + $BundleUrl)
    exit 0
} catch {
    if ($null -ne $banner) {
        try { $banner.Close(); $banner.Dispose() } catch {}
    }
    $errMsg = $_.Exception.Message
    Send-ClientLaunchReport "ERROR" "LAUNCH_ERROR" "HTTP DICOM取得またはRSViewer起動に失敗しました" ("err=" + $errMsg + " | url=" + $BundleUrl)
    [System.Windows.Forms.MessageBox]::Show(
        "断層データの取得または RSViewer の起動中にエラーが発生しました:`r`n" + $errMsg,
        "RSViewer 起動エラー",
        [System.Windows.Forms.MessageBoxButtons]::OK,
        [System.Windows.Forms.MessageBoxIcon]::Error
    ) | Out-Null
    exit 1
}