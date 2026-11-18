param(
    [string]$ServerIp = "192.168.1.163",
    [string]$Username = "Administrator",
    [string]$Password = ""
)

# ==============================================================================
# [開発PC 192.168.1.111 側で実行]
# 新サーバ (192.168.1.163) の Cドライブへ Apache24 + PHP8 + RS-Next 一式を高速転送
# ==============================================================================

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($Password)) {
    $sec = Read-Host -Prompt "${ServerIp}\${Username} のパスワードを入力してください" -AsSecureString
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)
    $Password = [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)
}

Write-Host "=== 1. 新サーバ ($ServerIp) へ SMB 接続中... ===" -ForegroundColor Cyan
net use "\\$ServerIp\C$" $Password /user:"$ServerIp\$Username" | Out-Null

try {
    Write-Host "=== 2. C:\php (設定済 PHP 8 一式) を転送中... ===" -ForegroundColor Cyan
    & robocopy "C:\php" "\\$ServerIp\C$\php" /MIR /MT:16 /R:2 /W:2 /NFL /NDL /NJH /NJS | Out-Null

    Write-Host "=== 3. C:\Apache24 (本体・conf・modules) を転送中... ===" -ForegroundColor Cyan
    foreach ($sub in @("bin", "cgi-bin", "conf", "error", "icons", "include", "lib", "modules")) {
        if (Test-Path "C:\Apache24\$sub") {
            & robocopy "C:\Apache24\$sub" "\\$ServerIp\C$\Apache24\$sub" /MIR /MT:16 /R:2 /W:2 /NFL /NDL /NJH /NJS | Out-Null
        }
    }
    if (-not (Test-Path "\\$ServerIp\C$\Apache24\logs")) {
        New-Item -ItemType Directory -Path "\\$ServerIp\C$\Apache24\logs" -Force | Out-Null
    }
    if (-not (Test-Path "\\$ServerIp\C$\Apache24\htdocs")) {
        New-Item -ItemType Directory -Path "\\$ServerIp\C$\Apache24\htdocs" -Force | Out-Null
    }

    Write-Host "=== 4. C:\Apache24\htdocs\rsnext (RS-Next プログラム＆仕様書一式) を転送中... ===" -ForegroundColor Cyan
    & robocopy "C:\Apache24\htdocs\rsnext" "\\$ServerIp\C$\Apache24\htdocs\rsnext" /MIR `
        /XF "server_config.json" /XD "storage_data" "tmp" "__pycache__" ".git" ".vscode" `
        /MT:16 /R:2 /W:2 /NFL /NDL /NJH /NJS | Out-Null

    # ルートアクセス (http://192.168.1.163/) から /rsnext/ へ自動転送するトップページを配置
    $rootRedirect = '<?php header("Location: /rsnext/"); exit;'
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText("\\$ServerIp\C$\Apache24\htdocs\index.php", $rootRedirect, $utf8NoBom)

    Write-Host "[OK] 転送完了！ 次にリモートデスクトップ ($ServerIp) 上で以下を順に実行してください:" -ForegroundColor Green
    Write-Host "   1) C:\Apache24\htdocs\rsnext\server_setup\01_storage_and_cleanup.ps1"
    Write-Host "   2) C:\Apache24\htdocs\rsnext\server_setup\02_install_services_and_prod_config.ps1"
} finally {
    net use "\\$ServerIp\C$" /delete /y | Out-Null
}

