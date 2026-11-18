$ErrorActionPreference = "Stop"

# �Ǘ��Ҍ����`�F�b�N
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "[�G���[] �Ǘ��Ҍ���������܂���B�R�}���h�v�����v�g�܂��̓o�b�`���u�Ǘ��҂Ƃ��Ď��s�v���Ă��������B" -ForegroundColor Red
    exit 1
}

$setupDir = Split-Path -Parent $MyInvocation.MyCommand.Path

Write-Host "============================================================================" -ForegroundColor Cyan
Write-Host " RS-Next Windows Server 2025 �S�����Z�b�g�A�b�v�J�n" -ForegroundColor Cyan
Write-Host "============================================================================" -ForegroundColor Cyan

Write-Host "`n>>> [1/3] �f�[�^�pRAID (Array 2: 4TB NVMe x4) �̒P��4�h���C�u����..." -ForegroundColor Yellow
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $setupDir "00b_split_data_raid.ps1")
if ($LASTEXITCODE -ne 0) { throw "Step 1 (00b_split_data_raid.ps1) �Œ�~���܂����B" }

Write-Host "`n>>> [2/3] ��DB��~ �� �X�g���[�W���� (D:/E:/F:/G:/H: �� DRS/DRS2���L)..." -ForegroundColor Yellow
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $setupDir "01_storage_and_cleanup.ps1")
if ($LASTEXITCODE -ne 0) { throw "Step 2 (01_storage_and_cleanup.ps1) �Œ�~���܂����B" }

Write-Host "`n>>> [3/3] PostgreSQL 18 / Python 3.13 / Apache 2.4 / MWL�풓 / �����~���[�\�z..." -ForegroundColor Yellow
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $setupDir "02_install_services_and_prod_config.ps1")
if ($LASTEXITCODE -ne 0) { throw "Step 3 (02_install_services_and_prod_config.ps1) �Œ�~���܂����B" }

Write-Host "`n============================================================================" -ForegroundColor Green
Write-Host " ���ׂĂ̍\�z������Ɋ������܂����I (http://192.168.1.163/rsnext/)" -ForegroundColor Green
Write-Host "============================================================================" -ForegroundColor Green
