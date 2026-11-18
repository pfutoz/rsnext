import os
import re
import sys
import json
import time
import socket
import ctypes
import winreg
import logging
import threading
import subprocess
from ctypes import wintypes
from datetime import datetime

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from ingest_service.common import CONFIG_PATH, load_config, get_db_conn, atomic_write_json, log_ingest_event
from ingest_service.snmp_engine import SnmpV2cAgent, snmp_probe_device

logger = logging.getLogger("rsnext.hw_monitor")

HW_TELEMETRY_FILE = os.path.join(BASE_DIR, "tmp", "hw_telemetry.json")
HW_HISTORY_FILE = os.path.join(BASE_DIR, "tmp", "hw_history.json")
HW_FORCE_FLAG = os.path.join(BASE_DIR, "tmp", "hw_force_refresh.flag")
BACKUP_STATUS_FILE = os.path.join(BASE_DIR, "tmp", "backup_live_status.json")
RCADM_DEFAULT_PATH = r"C:\Program Files\RAIDXpert2\rcadm.exe"


# ==============================================================================
# Win32 Kernel / IP Helper 高速ネイティブAPI (プロセス起動ゼロ・0ms取得)
# ==============================================================================

class FILETIME(ctypes.Structure):
    _fields_ = [("dwLowDateTime", wintypes.DWORD), ("dwHighDateTime", wintypes.DWORD)]


class MEMORYSTATUSEX(ctypes.Structure):
    _fields_ = [
        ("dwLength", wintypes.DWORD),
        ("dwMemoryLoad", wintypes.DWORD),
        ("ullTotalPhys", ctypes.c_uint64),
        ("ullAvailPhys", ctypes.c_uint64),
        ("ullTotalPageFile", ctypes.c_uint64),
        ("ullAvailPageFile", ctypes.c_uint64),
        ("ullTotalVirtual", ctypes.c_uint64),
        ("ullAvailVirtual", ctypes.c_uint64),
        ("ullAvailExtendedVirtual", ctypes.c_uint64),
    ]


class MIB_IFROW(ctypes.Structure):
    _fields_ = [
        ("wszName", ctypes.c_wchar * 256),
        ("dwIndex", wintypes.DWORD),
        ("dwType", wintypes.DWORD),
        ("dwMtu", wintypes.DWORD),
        ("dwSpeed", wintypes.DWORD),
        ("dwPhysAddrLen", wintypes.DWORD),
        ("bPhysAddr", ctypes.c_ubyte * 8),
        ("dwAdminStatus", wintypes.DWORD),
        ("dwOperStatus", wintypes.DWORD),
        ("dwLastChange", wintypes.DWORD),
        ("dwInOctets", wintypes.DWORD),
        ("dwInUcastPkts", wintypes.DWORD),
        ("dwInNUcastPkts", wintypes.DWORD),
        ("dwInDiscards", wintypes.DWORD),
        ("dwInErrors", wintypes.DWORD),
        ("dwInUnknownProtos", wintypes.DWORD),
        ("dwOutOctets", wintypes.DWORD),
        ("dwOutUcastPkts", wintypes.DWORD),
        ("dwOutNUcastPkts", wintypes.DWORD),
        ("dwOutDiscards", wintypes.DWORD),
        ("dwOutErrors", wintypes.DWORD),
        ("dwOutQLen", wintypes.DWORD),
        ("dwDescrLen", wintypes.DWORD),
        ("bDescr", ctypes.c_ubyte * 256),
    ]


def _ft_to_int(ft: FILETIME) -> int:
    return (int(ft.dwHighDateTime) << 32) | int(ft.dwLowDateTime)


def get_cpu_times() -> tuple[int, int, int]:
    idle, kernel, user = FILETIME(), FILETIME(), FILETIME()
    ctypes.windll.kernel32.GetSystemTimes(ctypes.byref(idle), ctypes.byref(kernel), ctypes.byref(user))
    return _ft_to_int(idle), _ft_to_int(kernel), _ft_to_int(user)


def get_memory_info() -> dict:
    ms = MEMORYSTATUSEX()
    ms.dwLength = ctypes.sizeof(MEMORYSTATUSEX)
    ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(ms))
    total = int(ms.ullTotalPhys)
    avail = int(ms.ullAvailPhys)
    used = max(0, total - avail)
    return {
        "percent": round((used / max(1, total)) * 100.0, 1),
        "total_bytes": total,
        "used_bytes": used,
        "avail_bytes": avail,
        "total_gb": round(total / (1024 ** 3), 1),
        "used_gb": round(used / (1024 ** 3), 1),
        "avail_gb": round(avail / (1024 ** 3), 1),
    }


def get_uptime_sec() -> int:
    ms = ctypes.windll.kernel32.GetTickCount64()
    return int(ms // 1000)


def format_uptime_ja(sec: int) -> str:
    d, rem = divmod(max(0, int(sec)), 86400)
    h, rem = divmod(rem, 3600)
    m, _ = divmod(rem, 60)
    if d > 0:
        return f"{d}日 {h}時間 {m}分"
    return f"{h}時間 {m}分"


def get_nic_counters() -> list[dict]:
    iphlp = ctypes.windll.iphlpapi
    size = wintypes.ULONG(0)
    iphlp.GetIfTable(None, ctypes.byref(size), False)
    if size.value == 0:
        return []
    buf = ctypes.create_string_buffer(size.value)
    if iphlp.GetIfTable(buf, ctypes.byref(size), False) != 0:
        return []
    num_entries = int.from_bytes(buf.raw[:4], byteorder="little")
    row_size = ctypes.sizeof(MIB_IFROW)
    nics = []
    offset = 4
    for _ in range(num_entries):
        if offset + row_size > size.value:
            break
        row = MIB_IFROW.from_buffer_copy(buf.raw[offset : offset + row_size])
        offset += row_size
        # Ethernet (6) / IEEE80211 (71), exclude loopback (24)
        if row.dwType not in (6, 71):
            continue
        descr = bytes(row.bDescr[: row.dwDescrLen]).decode("ascii", errors="ignore").strip("\x00").strip()
        if any(skip in descr.lower() for skip in ("wan miniport", "virtual", "pseudo", "filter", "qos", "ndis")):
            continue
        mac = ":".join(f"{row.bPhysAddr[i]:02X}" for i in range(min(8, row.dwPhysAddrLen)))
        is_up = row.dwOperStatus in (1, 4, 5)  # OPER_STATUS_OPERATIONAL / CONNECTED
        nics.append(
            {
                "index": int(row.dwIndex),
                "name": descr or f"NIC #{row.dwIndex}",
                "mac": mac,
                "speed_mbps": round(int(row.dwSpeed) / 1_000_000, 0),
                "is_up": bool(is_up and row.dwSpeed > 0),
                "rx_octets": int(row.dwInOctets),
                "tx_octets": int(row.dwOutOctets),
                "rx_errors": int(row.dwInErrors) + int(row.dwInDiscards),
                "tx_errors": int(row.dwOutErrors) + int(row.dwOutDiscards),
            }
        )
    return nics


def read_static_hw_profile() -> dict:
    cpu_name = "Unknown CPU"
    cpu_mhz = 0
    board_mfg = ""
    board_prod = ""
    os_name = "Windows Server"
    os_build = ""

    try:
        with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"HARDWARE\DESCRIPTION\System\CentralProcessor\0") as k:
            cpu_name = str(winreg.QueryValueEx(k, "ProcessorNameString")[0]).strip()
            cpu_mhz = int(winreg.QueryValueEx(k, "~MHz")[0])
    except Exception:
        pass

    try:
        with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"HARDWARE\DESCRIPTION\System\BIOS") as k:
            board_mfg = str(winreg.QueryValueEx(k, "BaseBoardManufacturer")[0]).strip()
            board_prod = str(winreg.QueryValueEx(k, "BaseBoardProduct")[0]).strip()
    except Exception:
        pass

    try:
        with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"SOFTWARE\Microsoft\Windows NT\CurrentVersion") as k:
            os_name = str(winreg.QueryValueEx(k, "ProductName")[0]).strip()
            os_build = str(winreg.QueryValueEx(k, "CurrentBuildNumber")[0]).strip()
    except Exception:
        pass

    return {
        "hostname": socket.gethostname(),
        "os_name": os_name,
        "os_build": os_build,
        "cpu_name": cpu_name,
        "cpu_logical_cores": os.cpu_count() or 1,
        "cpu_base_mhz": cpu_mhz,
        "motherboard": f"{board_mfg} {board_prod}".strip(),
    }


def collect_drive_volumes() -> list[dict]:
    sys_drive = (os.environ.get("SystemDrive") or "C:").upper()
    role_map = {
        "C:": ("OS起動ドライブ / システム・DB", "#475569"),
        "D:": ("過去アーカイブ SSD #1 (本番)", "#0284c7"),
        "E:": ("現役データ SSD #2 (本番)", "#2563eb"),
        "F:": ("アーカイブ ミラー SSD #3", "#0d9488"),
        "G:": ("現役 ミラー SSD #4", "#059669"),
        "H:": ("夜間世代バックアップ 8TB HDD", "#7c3aed"),
    }
    vols = []
    bitmask = ctypes.windll.kernel32.GetLogicalDrives()
    for i in range(26):
        if not (bitmask & (1 << i)):
            continue
        letter = f"{chr(65 + i)}:"
        root = letter + "\\"
        dtype = ctypes.windll.kernel32.GetDriveTypeW(ctypes.c_wchar_p(root))
        if dtype != 3:  # DRIVE_FIXED
            continue
        free_b = ctypes.c_uint64(0)
        total_b = ctypes.c_uint64(0)
        avail_b = ctypes.c_uint64(0)
        ok = ctypes.windll.kernel32.GetDiskFreeSpaceExW(
            ctypes.c_wchar_p(root),
            ctypes.byref(avail_b),
            ctypes.byref(total_b),
            ctypes.byref(free_b),
        )
        if not ok or total_b.value == 0:
            continue
        vol_name_buf = ctypes.create_unicode_buffer(261)
        fs_name_buf = ctypes.create_unicode_buffer(261)
        ctypes.windll.kernel32.GetVolumeInformationW(
            ctypes.c_wchar_p(root), vol_name_buf, 260, None, None, None, fs_name_buf, 260
        )
        tot = int(total_b.value)
        fr = int(free_b.value)
        used = max(0, tot - fr)
        pct = round((used / tot) * 100.0, 1)
        role_label, color = role_map.get(letter, ("ローカルディスク", "#64748b"))
        vols.append(
            {
                "drive": letter,
                "is_boot": letter.upper() == sys_drive,
                "label": vol_name_buf.value or letter,
                "filesystem": fs_name_buf.value or "NTFS",
                "role": role_label,
                "color": color,
                "total_bytes": tot,
                "used_bytes": used,
                "free_bytes": fr,
                "total_gb": round(tot / (1024 ** 3), 1),
                "free_gb": round(fr / (1024 ** 3), 1),
                "used_percent": pct,
                "status": "CRITICAL" if pct >= 92 else ("WARN" if pct >= 82 else "OK"),
            }
        )
    return vols


def parse_rcadm_qa(text: str) -> dict:
    disks = []
    arrays = []
    section = ""
    for raw_line in text.splitlines():
        line = raw_line.strip()
        if not line:
            continue
        if "<DISK LIST>" in line:
            section = "DISK"
            continue
        if "<ARRAY LIST>" in line:
            section = "ARRAY"
            continue
        if line.startswith("<") and line.endswith(">"):
            section = ""
            continue
        if line.startswith("Disk") or line.startswith("----") or line.startswith("A ") or line.startswith("-- "):
            continue
        if line.startswith("Warning:") or "AMD RAID driver" in line or "best experience" in line:
            continue

        if section == "DISK":
            # 例: 0 Online Disk NVMe Gen4 x4 4.0TB 0.0MB 0.0MB - Simple - NC on 0:01 CT4000P3PSSD8 P9CR40A_ 2341E87E8D6B
            m = re.match(
                r"^(\d+)\s+(\S+)\s+(\S+)\s+(NVMe|SATA/\S+|SAS\S*)\s+"
                r"(Gen\d+\s+x\d+|\S+Gb/sec|\S+)\s+(\d+(?:\.\d+)?[TGMP]B)\s+"
                r"\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+(on|off|-)\s+(\d+:\d+)\s+"
                r"(.+?)\s+(\S+)\s+(\S+)$",
                line,
            )
            if m:
                st = m.group(2)
                disks.append(
                    {
                        "slot": int(m.group(1)),
                        "state": st,
                        "healthy": st.lower() in ("online", "ok", "normal", "ready"),
                        "disk_type": m.group(3),
                        "bus": m.group(4),
                        "link_speed": m.group(5),
                        "size": m.group(6),
                        "smart_poll": m.group(7),
                        "channel": m.group(8),
                        "model": m.group(9).strip(),
                        "firmware": m.group(10),
                        "serial": m.group(11),
                    }
                )
        elif section == "ARRAY":
            # 例: 2 VOLUME 1 No D: NORMAL 4.0TB NO 0x2038... NOT_ACTIVE ... ... RW 256KB No SSD1_ARCHIVE
            m = re.match(
                r"^(\d+)\s+(\S+)\s+(\d+)\s+(Yes|No)\s+([A-Z]:|\S+)\s+(\S+)\s+(\d+(?:\.\d+)?[TGMP]B)\s+\S+\s+(\S+)\s+(\S+).*?(\S+)$",
                line,
            )
            if m:
                st = m.group(6)
                arr_name = m.group(10)
                arrays.append(
                    {
                        "array_id": int(m.group(1)),
                        "type": m.group(2),
                        "os_disk": int(m.group(3)),
                        "is_boot": m.group(4) == "Yes",
                        "drive": m.group(5) if m.group(5).endswith(":") else "",
                        "state": st,
                        "healthy": st.upper() == "NORMAL",
                        "size": m.group(7),
                        "task": m.group(9),
                        "name": "" if arr_name == "..." else arr_name,
                        "member_slots": [],
                    }
                )

    return {"source": "AMD_RAIDXPERT2", "disks": disks, "arrays": arrays}


def parse_rcadm_array_details(detail_text: str, arrays: list[dict]) -> None:
    arr_by_id = {int(a["array_id"]): a for a in arrays}
    cur_arr = None
    for raw_line in detail_text.splitlines():
        line = raw_line.strip()
        if not line:
            continue
        m_hdr = re.match(r"^<ARRAY:(\d+)>$", line, re.I)
        if m_hdr:
            aid = int(m_hdr.group(1))
            cur_arr = arr_by_id.get(aid)
            if cur_arr is None:
                cur_arr = {
                    "array_id": aid,
                    "type": "VOLUME",
                    "os_disk": -1,
                    "is_boot": False,
                    "drive": "",
                    "state": "NORMAL",
                    "healthy": True,
                    "size": "",
                    "task": "NOT_ACTIVE",
                    "name": "",
                    "member_slots": [],
                }
                arrays.append(cur_arr)
                arr_by_id[aid] = cur_arr
            continue

        if cur_arr is None:
            continue

        m_mp = re.match(r"^Mount Points:\s*\.+\s*(.+)$", line, re.I)
        if m_mp:
            mp = m_mp.group(1).strip()
            m_drv = re.search(r"([A-Z]:)", mp)
            if m_drv:
                cur_arr["drive"] = m_drv.group(1).upper()
            continue

        m_sys = re.match(r"^System Device:\s*\.+\s*(Yes|No)\b", line, re.I)
        if m_sys:
            cur_arr["is_boot"] = m_sys.group(1).lower() == "yes"
            continue

        m_nm = re.match(r"^Array name:\s*\.+\s*(.+)$", line, re.I)
        if m_nm:
            nm = m_nm.group(1).strip().strip('"').strip()
            if nm and nm.lower() not in ("<none>", "...", "none"):
                cur_arr["name"] = nm
            continue

        # メンバー物理ディスク行 (例: "5     500.1GB    0.0MB Online   NoError" / "0       4.0TB  537.0MB Online   NoError")
        m_disk = re.match(r"^(\d+)\s+\d+(?:\.\d+)?[TGMP]B\s+\d+(?:\.\d+)?[TGMP]B\s+\S+", line)
        if m_disk:
            slot_num = int(m_disk.group(1))
            if slot_num not in cur_arr["member_slots"]:
                cur_arr["member_slots"].append(slot_num)


def bind_disks_to_arrays_and_volumes(disks: list[dict], arrays: list[dict], volumes: list[dict] | None = None) -> None:
    vols_by_drive = {str(v.get("drive", "")).upper(): v for v in (volumes or [])}
    sys_drive = (os.environ.get("SystemDrive") or "C:").upper()

    # 万が一詳細取得が空だった場合のサイズ一意フォールバック (例: 500.1GB -> C:, 8.0TB -> H:)
    any_members = any(a.get("member_slots") for a in arrays)
    if not any_members and arrays and disks:
        used_slots = set()
        for a in arrays:
            same_sz = [d for d in disks if d.get("size") == a.get("size") and d["slot"] not in used_slots]
            if len(same_sz) == 1:
                a["member_slots"] = [same_sz[0]["slot"]]
                used_slots.add(same_sz[0]["slot"])

    slot_to_arr: dict[int, dict] = {}
    for a in arrays:
        drv = str(a.get("drive") or "").upper()
        if drv == sys_drive:
            a["is_boot"] = True
        vinfo = vols_by_drive.get(drv)
        if vinfo:
            a["volume_label"] = vinfo.get("label") or ""
            a["volume_role"] = vinfo.get("role") or ""
        for sl in a.get("member_slots") or []:
            slot_to_arr[int(sl)] = a

    for d in disks:
        arr = slot_to_arr.get(int(d["slot"]))
        if arr:
            drv = str(arr.get("drive") or "").upper()
            vinfo = vols_by_drive.get(drv) or {}
            d["array_id"] = arr["array_id"]
            d["drive"] = drv
            d["is_boot"] = bool(arr.get("is_boot") or drv == sys_drive)
            d["array_name"] = arr.get("name") or ""
            d["array_type"] = arr.get("type") or ""
            d["array_state"] = arr.get("state") or ""
            d["volume_label"] = vinfo.get("label") or arr.get("volume_label") or ""
            d["volume_role"] = vinfo.get("role") or arr.get("volume_role") or ""


def query_physical_storage(volumes: list[dict] | None = None) -> dict:
    if os.path.isfile(RCADM_DEFAULT_PATH):
        try:
            p = subprocess.run(
                [RCADM_DEFAULT_PATH, "-M", "-qa"],
                capture_output=True,
                text=True,
                encoding="cp932",
                errors="replace",
                timeout=8,
            )
            parsed = parse_rcadm_qa(p.stdout + "\n" + p.stderr)
            if parsed["disks"]:
                try:
                    p_det = subprocess.run(
                        [RCADM_DEFAULT_PATH, "-M", "-q", "-a", *[str(a["array_id"]) for a in parsed["arrays"]], "-v"],
                        capture_output=True,
                        text=True,
                        encoding="cp932",
                        errors="replace",
                        timeout=8,
                    )
                    det_out = p_det.stdout + "\n" + p_det.stderr
                    parse_rcadm_array_details(det_out, parsed["arrays"])
                except Exception as det_err:
                    logger.warning("rcadm array detail query failed: %s", det_err)

                bind_disks_to_arrays_and_volumes(parsed["disks"], parsed["arrays"], volumes)
                return parsed
        except Exception as e:
            logger.warning("rcadm query failed: %s", e)

    # フォールバック: PowerShell Get-PhysicalDisk
    try:
        ps_cmd = (
            "Get-PhysicalDisk | Sort-Object DeviceId | ForEach-Object { "
            "[PSCustomObject]@{Slot=[int]$_.DeviceId;Model=$_.FriendlyName;Serial=$_.SerialNumber;"
            "Bus=[string]$_.BusType;Media=[string]$_.MediaType;SizeGB=[math]::Round($_.Size/1GB,1);"
            "Health=[string]$_.HealthStatus;Op=[string]$_.OperationalStatus;Fw=$_.FirmwareVersion} "
            "} | ConvertTo-Json -Compress"
        )
        p = subprocess.run(
            ["powershell.exe", "-NoProfile", "-Command", ps_cmd],
            capture_output=True,
            text=True,
            encoding="cp932",
            errors="replace",
            timeout=10,
        )
        raw = p.stdout.strip()
        items = json.loads(raw) if raw else []
        if isinstance(items, dict):
            items = [items]
        disks = []
        for it in items:
            hlth = str(it.get("Health") or "Healthy")
            disks.append(
                {
                    "slot": int(it.get("Slot") or 0),
                    "state": hlth,
                    "healthy": hlth.lower() in ("healthy", "0", "ok"),
                    "disk_type": str(it.get("Media") or "SSD"),
                    "bus": str(it.get("Bus") or "NVMe"),
                    "link_speed": "-",
                    "size": f"{it.get('SizeGB', 0)}GB",
                    "smart_poll": "auto",
                    "channel": "-",
                    "model": str(it.get("Model") or "Disk"),
                    "firmware": str(it.get("Fw") or "-"),
                    "serial": str(it.get("Serial") or "-").strip(),
                }
            )
        return {"source": "WINDOWS_STORAGE_WMI", "disks": disks, "arrays": []}
    except Exception as e:
        return {"source": "ERROR", "error": str(e), "disks": [], "arrays": []}


def query_system_hw_events() -> list[dict]:
    try:
        ps_cmd = (
            "$ErrorActionPreference='SilentlyContinue'; "
            "$ev = Get-WinEvent -FilterHashtable @{LogName='System'; Level=1,2,3; "
            "ProviderName='disk','Ntfs','stornvme','storahci','rcraid','WHEA-Logger','Microsoft-Windows-Kernel-Power'; "
            "StartTime=(Get-Date).AddDays(-7)} -MaxEvents 12; "
            "if ($ev) { $ev | ForEach-Object { [PSCustomObject]@{Time=$_.TimeCreated.ToString('yyyy-MM-dd HH:mm:ss');"
            "Level=$_.LevelDisplayName;Provider=$_.ProviderName;Id=$_.Id;Msg=($_.Message -replace '\\s+',' ').Substring(0,[math]::Min(180,($_.Message -replace '\\s+',' ').Length))} } | ConvertTo-Json -Compress }"
        )
        p = subprocess.run(
            ["powershell.exe", "-NoProfile", "-Command", ps_cmd],
            capture_output=True,
            text=True,
            encoding="cp932",
            errors="replace",
            timeout=8,
        )
        raw = p.stdout.strip()
        if not raw:
            return []
        arr = json.loads(raw)
        return [arr] if isinstance(arr, dict) else arr
    except Exception:
        return []


def tcp_probe(host: str, port: int, timeout: float = 1.2) -> tuple[bool, float | None]:
    t0 = time.perf_counter()
    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    s.settimeout(timeout)
    try:
        s.connect((host, int(port)))
        return True, round((time.perf_counter() - t0) * 1000.0, 1)
    except Exception:
        return False, None
    finally:
        s.close()


def icmp_ping_ms(host: str, timeout_ms: int = 900) -> tuple[bool, float | None]:
    try:
        p = subprocess.run(
            ["ping.exe", "-n", "1", "-w", str(timeout_ms), host],
            capture_output=True,
            text=True,
            encoding="cp932",
            errors="replace",
            timeout=2.5,
        )
        if p.returncode == 0 and ("TTL=" in p.stdout.upper()):
            m = re.search(r"[=<]\s*(\d+)\s*ms", p.stdout, re.I)
            return True, float(m.group(1)) if m else 0.5
        return False, None
    except Exception:
        return False, None


def probe_lan_and_snmp_targets(cfg: dict) -> list[dict]:
    built_in = [
        {"id": "MALL3_NEW", "name": "新カルテ MALL3 SQL", "host": "192.168.1.77", "tcp_port": 1433, "role": "電子カルテDB (本院)", "snmp": False},
        {"id": "MALL3_ONO", "name": "おのカルテ MALL3 SQL", "host": "192.168.1.52", "tcp_port": 1433, "role": "電子カルテDB (9番台)", "snmp": False},
        {"id": "KARTE_HUB", "name": "KarteView / RSBase共通ハブ", "host": "192.168.1.16", "tcp_port": 80, "role": "カルテ連携・常駐ハブ", "snmp": False},
        {"id": "LEGACY_RSB", "name": "現行 RS-Base サーバ", "host": "192.168.1.164", "tcp_port": 5432, "role": "旧画像サーバ (Read-Only)", "snmp": False},
    ]

    # DB内の自動検出モダリティ (例: 島津 SHIMADZU-PC 等) も自動追加
    seen_hosts = {t["host"] for t in built_in}
    try:
        conn = get_db_conn(cfg)
        try:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT DISTINCT ON (source_ip) source_ip, station_name, calling_ae_title, dicom_modality, last_seen_at
                    FROM discovered_devices
                    WHERE source_ip <> '' AND source_ip NOT IN ('127.0.0.1', '::1')
                    ORDER BY source_ip, last_seen_at DESC
                    LIMIT 15
                    """
                )
                for r in cur.fetchall():
                    ip = str(r["source_ip"])
                    if ip not in seen_hosts:
                        seen_hosts.add(ip)
                        lbl = r["station_name"] or r["calling_ae_title"] or f"モダリティ ({r['dicom_modality']})"
                        built_in.append(
                            {
                                "id": f"MOD_{ip}",
                                "name": f"装置: {lbl}",
                                "host": ip,
                                "tcp_port": 0,
                                "role": f"医療機器 ({r['dicom_modality'] or 'DICOM'})",
                                "snmp": False,
                            }
                        )
        finally:
            conn.close()
    except Exception:
        pass

    # カスタム登録ターゲット (ルータ・NAS・UPS・スイッチ等のSNMP/Ping監視対象)
    ops_cfg = cfg.get("ops_monitor", {})
    for idx, ct in enumerate(ops_cfg.get("custom_targets") or []):
        host = str(ct.get("host") or "").strip()
        if not host:
            continue
        built_in.append(
            {
                "id": str(ct.get("id") or f"CUSTOM_{idx}"),
                "name": str(ct.get("name") or host),
                "host": host,
                "tcp_port": int(ct.get("tcp_port") or 0),
                "role": str(ct.get("role") or "カスタムSNMP/LAN機器"),
                "snmp": bool(ct.get("snmp", True)),
                "snmp_port": int(ct.get("snmp_port") or 161),
                "community": str(ct.get("community") or "public"),
                "is_custom": True,
            }
        )

    results = []
    for t in built_in:
        host = t["host"]
        port = int(t.get("tcp_port") or 0)
        alive = False
        rtt = None
        check_method = ""
        if port > 0:
            alive, rtt = tcp_probe(host, port, timeout=1.1)
            check_method = f"TCP:{port}"
        if not alive:
            p_ok, p_rtt = icmp_ping_ms(host, timeout_ms=800)
            if p_ok:
                alive = True
                rtt = p_rtt
                check_method = "ICMP Ping" if port == 0 else f"Ping OK (TCP:{port}閉)"
            elif port == 0:
                check_method = "ICMP Ping"

        snmp_info = None
        if t.get("snmp"):
            snmp_info = snmp_probe_device(
                host,
                port=int(t.get("snmp_port") or 161),
                community=str(t.get("community") or "public"),
                timeout=1.2,
            )
            if snmp_info["snmp_ok"] and not alive:
                alive = True
                rtt = snmp_info["rtt_ms"]
                check_method = "SNMP UDP:161"

        results.append(
            {
                "id": t["id"],
                "name": t["name"],
                "host": host,
                "role": t["role"],
                "tcp_port": port,
                "is_custom": bool(t.get("is_custom", False)),
                "alive": alive,
                "rtt_ms": rtt,
                "method": check_method,
                "snmp": snmp_info,
            }
        )
    return results


class HardwareMonitorService:
    """
    常駐ハードウェア・ストレージ(RAIDXpert2/NVMe)・LAN死活・SNMP v2cエージェント統合サービス
    """

    def __init__(self, cfg: dict):
        self.cfg = cfg
        self._cfg_mtime = 0.0
        self._stop_evt = threading.Event()
        self._thread: threading.Thread | None = None
        self.static_hw = read_static_hw_profile()
        self._prev_cpu = get_cpu_times()
        self._prev_nic: dict[int, tuple[float, int, int]] = {}
        self._last_deep_ts = 0.0
        self._deep_cache = {
            "storage": {"source": "INIT", "disks": [], "arrays": []},
            "hw_events": [],
            "lan_nodes": [],
        }
        self._latest_snapshot: dict = {}
        self.snmp_agent: SnmpV2cAgent | None = None
        self._last_history_min = ""
        self._last_alert_key = ""

    def _get_snmp_cfg(self) -> dict:
        ops = self.cfg.get("ops_monitor", {})
        sc = ops.get("snmp_agent", {})
        return {
            "enabled": bool(sc.get("enabled", True)),
            "bind_host": str(sc.get("bind_host") or "0.0.0.0"),
            "port": int(sc.get("port") or 161),
            "community": str(sc.get("community") or "public"),
        }

    def _sync_snmp_agent(self):
        sc = self._get_snmp_cfg()
        if not sc["enabled"]:
            if self.snmp_agent:
                self.snmp_agent.stop()
                self.snmp_agent = None
            return
        if self.snmp_agent:
            if (
                self.snmp_agent.port == sc["port"]
                and self.snmp_agent.community == sc["community"]
                and self.snmp_agent.active
            ):
                return
            self.snmp_agent.stop()
            self.snmp_agent = None
        self.snmp_agent = SnmpV2cAgent(
            self._build_snmp_mib,
            bind_host=sc["bind_host"],
            port=sc["port"],
            community=sc["community"],
        )
        self.snmp_agent.start()

    def _build_snmp_mib(self) -> dict:
        snap = self._latest_snapshot or {}
        cpu_pct = int(round(snap.get("cpu_percent") or 0))
        mem = snap.get("memory") or {}
        ram_pct = int(round(mem.get("percent") or 0))
        up_ticks = get_uptime_sec() * 100
        vols = {v["drive"]: v for v in (snap.get("volumes") or [])}
        st = snap.get("storage") or {}
        disks = st.get("disks") or []
        ok_disks = sum(1 for d in disks if d.get("healthy"))
        bad_disks = max(0, len(disks) - ok_disks)
        overall_code = 0 if snap.get("overall_status") == "OK" else (1 if snap.get("overall_status") == "WARN" else 2)

        bk_err = 0
        if os.path.isfile(BACKUP_STATUS_FILE):
            try:
                with open(BACKUP_STATUS_FILE, "r", encoding="utf-8") as f:
                    bs = json.load(f)
                if bs.get("last_overall_status") == "ERROR":
                    bk_err = 1
            except Exception:
                pass

        descr = (
            f"RS-Next PACS ({self.static_hw['os_name']}) "
            f"CPU:{cpu_pct}% RAM:{ram_pct}% Disks:{ok_disks}/{len(disks)} Online"
        )
        return {
            "1.3.6.1.2.1.1.1.0": ("str", descr),
            "1.3.6.1.2.1.1.2.0": ("oid", "1.3.6.1.4.1.99999.1"),
            "1.3.6.1.2.1.1.3.0": ("ticks", up_ticks),
            "1.3.6.1.2.1.1.4.0": ("str", "RS-Next System Monitor"),
            "1.3.6.1.2.1.1.5.0": ("str", self.static_hw["hostname"]),
            "1.3.6.1.2.1.1.6.0": ("str", str(self.cfg.get("clinic_name") or "Clinic PACS Room")),
            "1.3.6.1.2.1.25.3.3.1.2.1": ("int", cpu_pct),
            "1.3.6.1.4.1.2021.4.5.0": ("int", int((mem.get("total_bytes") or 0) // 1024)),
            "1.3.6.1.4.1.2021.4.6.0": ("int", int((mem.get("avail_bytes") or 0) // 1024)),
            "1.3.6.1.4.1.99999.1.1.0": ("int", overall_code),
            "1.3.6.1.4.1.99999.1.2.0": ("int", cpu_pct),
            "1.3.6.1.4.1.99999.1.3.0": ("int", ram_pct),
            "1.3.6.1.4.1.99999.1.4.0": ("int", int(vols.get("E:", {}).get("free_gb") or 0)),
            "1.3.6.1.4.1.99999.1.5.0": ("int", int(vols.get("D:", {}).get("free_gb") or 0)),
            "1.3.6.1.4.1.99999.1.6.0": ("int", int(vols.get("H:", {}).get("free_gb") or 0)),
            "1.3.6.1.4.1.99999.1.7.0": ("int", ok_disks),
            "1.3.6.1.4.1.99999.1.8.0": ("int", bad_disks),
            "1.3.6.1.4.1.99999.1.9.0": ("int", bk_err),
        }

    def start(self):
        if self._thread and self._thread.is_alive():
            return
        self._stop_evt.clear()
        self._thread = threading.Thread(target=self._loop, name="HardwareMonitor", daemon=True)
        self._thread.start()
        logger.info("Hardware & SNMP Monitor service started")

    def stop(self):
        self._stop_evt.set()
        if self.snmp_agent:
            self.snmp_agent.stop()
            self.snmp_agent = None
        if self._thread and self._thread.is_alive():
            self._thread.join(timeout=4.0)

    def _sample_cpu_percent(self) -> float:
        idle2, kern2, user2 = get_cpu_times()
        idle1, kern1, user1 = self._prev_cpu
        self._prev_cpu = (idle2, kern2, user2)
        d_idle = max(0, idle2 - idle1)
        d_total = max(1, (kern2 - kern1) + (user2 - user1))
        busy = max(0, d_total - d_idle)
        return round(min(100.0, (busy / d_total) * 100.0), 1)

    def _sample_nics(self) -> tuple[list[dict], float, float]:
        now = time.perf_counter()
        raw_nics = get_nic_counters()
        enriched = []
        tot_rx_mbps = 0.0
        tot_tx_mbps = 0.0
        for n in raw_nics:
            idx = n["index"]
            rx_mbps = 0.0
            tx_mbps = 0.0
            if idx in self._prev_nic:
                t0, prev_rx, prev_tx = self._prev_nic[idx]
                dt = max(0.2, now - t0)
                # 32-bit counter wrap safety
                drx = (n["rx_octets"] - prev_rx) & 0xFFFFFFFF
                dtx = (n["tx_octets"] - prev_tx) & 0xFFFFFFFF
                rx_mbps = round((drx * 8.0) / (dt * 1_000_000.0), 2)
                tx_mbps = round((dtx * 8.0) / (dt * 1_000_000.0), 2)
            self._prev_nic[idx] = (now, n["rx_octets"], n["tx_octets"])
            n["rx_mbps"] = rx_mbps
            n["tx_mbps"] = tx_mbps
            if n["is_up"]:
                tot_rx_mbps += rx_mbps
                tot_tx_mbps += tx_mbps
                enriched.append(n)
        if not enriched and raw_nics:
            enriched = raw_nics[:2]
        return enriched, round(tot_rx_mbps, 2), round(tot_tx_mbps, 2)

    def _check_local_services(self) -> list[dict]:
        ing = self.cfg.get("ingest", {})
        dicom_port = int(ing.get("dicom_scp", {}).get("port") or 104)
        ftp_port = int(ing.get("ftp", {}).get("port") or 21)
        sc = self._get_snmp_cfg()

        checks = [
            ("WEB_HTTP", "Apache 2.4 Webサーバ", "TCP:80", tcp_probe("127.0.0.1", 80, 0.5)[0]),
            ("PGSQL_DB", "PostgreSQL 18 (rsnext_db)", "TCP:5432", tcp_probe("127.0.0.1", 5432, 0.5)[0]),
            ("DICOM_MWL", "DICOM Storage + MWL", f"TCP:{dicom_port}", tcp_probe("127.0.0.1", dicom_port, 0.5)[0]),
            ("FTP_ENDO", "内視鏡 FTPサーバ", f"TCP:{ftp_port}", tcp_probe("127.0.0.1", ftp_port, 0.5)[0]),
            ("FUKUDA_1001", "フクダ心電図 LAN受信モジュール", "TCP:1001", tcp_probe("127.0.0.1", 1001, 0.5)[0]),
            (
                "SNMP_AGENT",
                "SNMP v2c 監視エージェント",
                f"UDP:{sc['port']}" if sc["enabled"] else "無効",
                bool(self.snmp_agent and self.snmp_agent.active) if sc["enabled"] else True,
            ),
        ]
        return [
            {"code": c, "name": nm, "endpoint": ep, "ok": ok}
            for (c, nm, ep, ok) in checks
        ]

    def _append_history(self, cpu_pct: float, ram_pct: float, rx_mbps: float, tx_mbps: float):
        now_dt = datetime.now().astimezone()
        ts_short = now_dt.strftime("%H:%M:%S")
        min_key = now_dt.strftime("%Y-%m-%d %H:%M")

        hist = {"fast_10s": [], "hourly_24h": []}
        if os.path.isfile(HW_HISTORY_FILE):
            try:
                with open(HW_HISTORY_FILE, "r", encoding="utf-8") as f:
                    hist = json.load(f)
            except Exception:
                pass

        pt = {"t": ts_short, "cpu": cpu_pct, "ram": ram_pct, "rx": rx_mbps, "tx": tx_mbps}
        fast = hist.get("fast_10s") or []
        fast.append(pt)
        hist["fast_10s"] = fast[-60:]  # 直近10分間 (10秒×60点)

        if min_key != self._last_history_min:
            self._last_history_min = min_key
            h24 = hist.get("hourly_24h") or []
            h24.append({"t": now_dt.strftime("%H:%M"), "cpu": cpu_pct, "ram": ram_pct, "rx": rx_mbps, "tx": tx_mbps})
            hist["hourly_24h"] = h24[-1440:]  # 直近24時間 (1分×1440点)

        atomic_write_json(HW_HISTORY_FILE, hist)

    def _loop(self):
        time.sleep(0.6)
        while not self._stop_evt.is_set():
            try:
                try:
                    st = os.stat(CONFIG_PATH)
                    if st.st_mtime != self._cfg_mtime:
                        self.cfg = load_config()
                        self._cfg_mtime = st.st_mtime
                        self._sync_snmp_agent()
                except Exception:
                    pass

                force_deep = False
                if os.path.isfile(HW_FORCE_FLAG):
                    force_deep = True
                    try:
                        os.remove(HW_FORCE_FLAG)
                    except OSError:
                        pass

                cpu_pct = self._sample_cpu_percent()
                mem = get_memory_info()
                vols = collect_drive_volumes()

                now_mono = time.monotonic()
                if force_deep or (now_mono - self._last_deep_ts) >= 60.0:
                    self._last_deep_ts = now_mono
                    self._deep_cache["storage"] = query_physical_storage(vols)
                    self._deep_cache["hw_events"] = query_system_hw_events()
                    self._deep_cache["lan_nodes"] = probe_lan_and_snmp_targets(self.cfg)
                elif self._deep_cache["storage"].get("disks"):
                    bind_disks_to_arrays_and_volumes(
                        self._deep_cache["storage"]["disks"],
                        self._deep_cache["storage"].get("arrays") or [],
                        vols,
                    )

                nics, rx_mbps, tx_mbps = self._sample_nics()
                svcs = self._check_local_services()
                up_sec = get_uptime_sec()

                # 総合ヘルス判定
                alerts = []
                overall = "OK"
                for d in self._deep_cache["storage"].get("disks", []):
                    if not d.get("healthy"):
                        overall = "CRITICAL"
                        alerts.append(f"物理ドライブ異常: Slot #{d['slot']} {d['model']} ({d['state']})")
                for a in self._deep_cache["storage"].get("arrays", []):
                    if not a.get("healthy"):
                        overall = "CRITICAL"
                        alerts.append(f"RAID/ボリューム異常: Array #{a['array_id']} {a['drive']} ({a['state']})")
                for v in vols:
                    if v["status"] == "CRITICAL":
                        overall = "CRITICAL"
                        alerts.append(f"ディスク残量逼迫: {v['drive']} 使用率 {v['used_percent']}% (残り {v['free_gb']} GB)")
                    elif v["status"] == "WARN" and overall == "OK":
                        overall = "WARN"
                        alerts.append(f"ディスク残量注意: {v['drive']} 使用率 {v['used_percent']}%")
                for s in svcs:
                    if not s["ok"]:
                        if overall == "OK":
                            overall = "WARN"
                        alerts.append(f"サービス応答停止: {s['name']} ({s['endpoint']})")

                # 重大異常発生時は監査ログへ自動記録 (同一内容の連投抑止)
                alert_sig = "|".join(alerts)
                if overall == "CRITICAL" and alert_sig != self._last_alert_key:
                    self._last_alert_key = alert_sig
                    try:
                        conn = get_db_conn(self.cfg)
                        try:
                            log_ingest_event(conn, "ERROR", "HW_MONITOR", "HARDWARE_ALERT", " ／ ".join(alerts))
                            conn.commit()
                        finally:
                            conn.close()
                    except Exception:
                        pass
                elif overall == "OK":
                    self._last_alert_key = ""

                sc = self._get_snmp_cfg()
                snapshot = {
                    "updated_at": datetime.now().astimezone().isoformat(),
                    "overall_status": overall,
                    "alerts": alerts,
                    "system": {
                        **self.static_hw,
                        "uptime_sec": up_sec,
                        "uptime_str": format_uptime_ja(up_sec),
                    },
                    "cpu_percent": cpu_pct,
                    "memory": mem,
                    "network": {
                        "rx_mbps": rx_mbps,
                        "tx_mbps": tx_mbps,
                        "adapters": nics,
                    },
                    "volumes": vols,
                    "storage": self._deep_cache["storage"],
                    "services": svcs,
                    "snmp_agent": {
                        "enabled": sc["enabled"],
                        "port": sc["port"],
                        "community": sc["community"],
                        "active": bool(self.snmp_agent and self.snmp_agent.active),
                        "requests_served": self.snmp_agent.requests_served if self.snmp_agent else 0,
                        "last_error": self.snmp_agent.last_error if self.snmp_agent else "",
                    },
                    "lan_nodes": self._deep_cache["lan_nodes"],
                    "hw_events": self._deep_cache["hw_events"],
                }
                self._latest_snapshot = snapshot
                atomic_write_json(HW_TELEMETRY_FILE, snapshot)
                self._append_history(cpu_pct, mem["percent"], rx_mbps, tx_mbps)
            except Exception as e:
                logger.warning("HardwareMonitor tick error: %s", e)

            self._stop_evt.wait(10.0)


def refresh_storage_telemetry_now() -> dict:
    vols = collect_drive_volumes()
    storage = query_physical_storage(vols)
    snap = {}
    if os.path.isfile(HW_TELEMETRY_FILE):
        try:
            with open(HW_TELEMETRY_FILE, "r", encoding="utf-8") as f:
                snap = json.load(f)
        except Exception:
            snap = {}
    snap["updated_at"] = datetime.now().astimezone().isoformat()
    snap["volumes"] = vols
    snap["storage"] = storage
    atomic_write_json(HW_TELEMETRY_FILE, snap)
    return snap


if __name__ == "__main__":
    if "--refresh-storage-now" in sys.argv:
        out = refresh_storage_telemetry_now()
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
        print(json.dumps({"status": "success", "disks": len(out.get("storage", {}).get("disks", []))}))


