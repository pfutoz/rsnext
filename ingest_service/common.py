import os
import re
import json
import hashlib
import unicodedata
from datetime import datetime, date
import psycopg
from psycopg.rows import dict_row

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONFIG_PATH = os.path.join(BASE_DIR, "config", "server_config.json")


def load_config() -> dict:
    with open(CONFIG_PATH, "r", encoding="utf-8") as f:
        return json.load(f)


def get_db_conn(cfg: dict = None):
    if cfg is None:
        cfg = load_config()
    d = cfg["db"]
    conn = psycopg.connect(
        host=d["host"],
        port=int(d["port"]),
        dbname=d["dbname"],
        user=d["user"],
        password=d["password"],
        row_factory=dict_row,
        autocommit=False,
    )
    return conn


def hiragana_to_katakana(text: str) -> str:
    out = []
    for ch in text:
        code = ord(ch)
        if 0x3041 <= code <= 0x3096:
            out.append(chr(code + 0x60))
        else:
            out.append(ch)
    return "".join(out)


def normalize_kana_yomi(text: str) -> str:
    if not text:
        return ""
    s = unicodedata.normalize("NFKC", str(text))
    s = hiragana_to_katakana(s)
    s = re.sub(r"[\s　]+", "", s)
    return s.strip()


def normalize_patient_id(raw_id: str, is_drs2: bool = False) -> tuple[str | None, str]:
    """
    患者IDの正規化と妥当性チェック。
    バーコード誤読($17077...)や空欄、NoNumber等は None を返し Quarantine へ誘導する。
    """
    if raw_id is None:
        return None, "患者IDが空です"
    s = unicodedata.normalize("NFKC", str(raw_id)).strip()
    if not s:
        return None, "患者IDが空です"

    # RS-Base旧互換: 999999プレフィックス12桁対応
    if len(s) >= 12 and s.startswith("999999") and s[6:].isdigit():
        s = str(int(s[6:]))

    # フクダ心電図等の "83003(D)" 形式から数字IDを抽出
    m_ecg = re.match(r"^0*([0-9]+)\([A-Za-z]+\)$", s)
    if m_ecg:
        s = m_ecg.group(1)

    if any(bad in s.lower() for bad in ["nonumber", "unknown", "test", "$", "~"]):
        return None, f"不正な患者ID形式です ({s})"

    if not re.match(r"^[0-9]+$", s):
        return None, f"数値以外の患者IDです ({s})"

    val = int(s)
    if val <= 0:
        return None, f"患者IDが0以下です ({s})"
    if val > 9999999999:
        return None, f"患者ID桁数超過（バーコード誤読の疑い: {s}）"

    if is_drs2:
        return f"9{val:05d}", ""
    return str(val), ""


def sha256_file(filepath: str) -> str:
    h = hashlib.sha256()
    with open(filepath, "rb") as f:
        while True:
            chunk = f.read(1024 * 1024)
            if not chunk:
                break
            h.update(chunk)
    return h.hexdigest()


def atomic_write_json(filepath: str, data: dict):
    os.makedirs(os.path.dirname(filepath), exist_ok=True)
    tmp = filepath + f".tmp_{os.getpid()}"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    os.replace(tmp, filepath)


def log_ingest_event(conn, level: str, channel: str, event_type: str, summary: str, patient_id: str = "", study_id: int = None, detail: dict = None):
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO ingest_logs (level, channel, event_type, patient_id, study_id, summary, detail)
            VALUES (%s, %s, %s, %s, %s, %s, %s)
            """,
            (level, channel, event_type, patient_id or "", study_id, summary, json.dumps(detail or {}, ensure_ascii=False)),
        )


def resolve_kensamei_by_strategy(profile: dict, meta: dict) -> str:
    strategy = (profile.get("kensamei_from_tag") or "FIXED").upper()
    default_name = profile.get("default_kensamei") or "検査画像"
    mod = (meta.get("dicom_modality") or profile.get("modality_code") or "OT").upper()
    body = (meta.get("body_part") or "").strip()
    proto = (meta.get("protocol_name") or "").strip()
    desc = (meta.get("study_description") or meta.get("series_description") or "").strip()

    if strategy == "FIXED":
        return default_name

    combined = f"{body} {proto} {desc}".upper()
    if mod in ("CT", "MR") and strategy != "FIXED":
        mod_disp = "MRI" if mod == "MR" else "CT"
        m_pre = re.match(r"^(単純のみ|単純|造影のみ|単純\+造影|単純・造影|造影)[）\)\s:：]+(.+)$", proto)
        if m_pre:
            kind_lbl = "単純" if "単純" in m_pre.group(1) and "造影" not in m_pre.group(1) else ("造影" if "造影" in m_pre.group(1) else "")
            return f"{m_pre.group(2).strip()}{mod_disp}" + (f"({kind_lbl})" if kind_lbl else "")
        for cand in (meta.get("study_description") or "", proto):
            c_clean = cand.strip()
            if c_clean and not re.fullmatch(r"[,.\d\s_\-]+", c_clean):
                return c_clean if mod_disp in c_clean.upper() else f"{mod_disp}({c_clean})"
        has_chest = any(k in combined for k in ("CHEST", "THORAX", "LUNG", "胸", "肺"))
        has_abd = any(k in combined for k in ("ABD", "LIVER", "PANCREAS", "腹", "肝", "膵"))
        has_pelv = any(k in combined for k in ("PELV", "RECT", "COLON", "骨盤", "直腸"))
        has_head = any(k in combined for k in ("HEAD", "BRAIN", "CRANI", "頭", "脳"))
        if has_chest and has_abd and has_pelv:
            return f"胸〜骨盤{mod_disp}"
        if has_chest and has_abd:
            return f"胸腹部{mod_disp}"
        if has_pelv:
            return f"骨盤{mod_disp}"
        if has_abd:
            return f"腹部{mod_disp}"
        if has_chest:
            return f"胸部{mod_disp}"
        if has_head:
            return f"頭部{mod_disp}"
        return f"他院紹介{mod_disp}"

    if strategy == "BODY_OR_PROTOCOL":
        if mod in ("DX", "CR"):
            body_rules = [
                (("CHEST", "THORAX", "RIB", "胸郭", "肋骨", "胸"), "胸部(D)"),
                (("ABD", "KUB", "腹"), "腹部(D)"),
                (("LSPINE", "L-SPINE", "LUMBAR", "腰椎", "腰部", "腰"), "腰椎(D)"),
                (("CSPINE", "C-SPINE", "CERVICAL", "頸椎", "頚椎", "頸部"), "頸椎(D)"),
                (("TSPINE", "T-SPINE", "THORACIC", "胸椎"), "胸椎(D)"),
                (("PELVIS", "HIP", "SACRUM", "骨盤", "股関節", "仙骨"), "骨盤(D)"),
                (("KNEE", "PATELLA", "膝"), "膝関節(D)"),
                (("SHOULDER", "CLAVICLE", "肩", "鎖骨"), "肩関節(D)"),
                (("HAND", "WRIST", "FINGER", "ELBOW", "FOREARM", "手", "肘", "前腕"), "手部(D)"),
                (("FOOT", "ANKLE", "CALCANEUS", "TOE", "足", "踵"), "足部(D)"),
                (("SKULL", "HEAD", "SINUS", "頭", "副鼻腔"), "頭部(D)"),
            ]
            for keywords, std_label in body_rules:
                if any(kw in combined for kw in keywords):
                    return std_label
            if proto:
                # 例: "膝正面-AP 1" などの方向サフィックスを除いた部位ベース名に揃える
                base_p = re.sub(r"[-_\s]*(PA|AP|LAT|OBL|RPO|LPO|RAO|LAO|\d+)\s*$", "", proto, flags=re.I).strip()
                return base_p or proto
            if body:
                return f"{mod}_{body}"
        for cand in (desc, proto, body):
            if cand:
                return cand
        return default_name

    if strategy == "MODALITY_PROTOCOL":
        label = proto or desc or body
        if label:
            clean = re.sub(r"[\s/\\]+", "_", label).strip("_")
            if mod == "US" and not clean.upper().startswith("US"):
                return f"US_{clean}_"
            if mod == "RF" and not clean.upper().startswith("RF"):
                return f"RF_{clean}"
            return clean
        return default_name

    return default_name


def rule_matches(rules: dict, meta: dict) -> bool:
    if not rules:
        return True

    def csv_in(rule_val: str, actual: str) -> bool:
        allowed = [x.strip().lower() for x in str(rule_val).split(",") if x.strip()]
        if not allowed:
            return True
        return (actual or "").strip().lower() in allowed

    if rules.get("source_ip"):
        if not csv_in(rules["source_ip"], meta.get("source_ip", "")):
            return False

    if rules.get("calling_ae_title"):
        if not csv_in(rules["calling_ae_title"], meta.get("calling_ae_title", "")):
            return False

    if rules.get("station_name"):
        if not csv_in(rules["station_name"], meta.get("station_name", "")):
            return False

    if rules.get("dicom_modality"):
        if not csv_in(rules["dicom_modality"], meta.get("dicom_modality", "")):
            return False

    if rules.get("manufacturer_contains"):
        needle = str(rules["manufacturer_contains"]).strip().lower()
        if needle and needle not in (meta.get("manufacturer") or "").lower():
            return False

    if rules.get("model_contains"):
        needle = str(rules["model_contains"]).strip().lower()
        if needle and needle not in (meta.get("model_name") or "").lower():
            return False

    if rules.get("file_ext"):
        if not csv_in(rules["file_ext"], (meta.get("file_ext") or "").lstrip(".")):
            return False

    if rules.get("watch_subfolder"):
        if not csv_in(rules["watch_subfolder"], meta.get("watch_subfolder", "")):
            return False

    w = int(meta.get("width") or 0)
    if rules.get("min_width") and w < int(rules["min_width"]):
        return False
    if rules.get("max_width") and int(rules["max_width"]) > 0 and w > int(rules["max_width"]):
        return False

    return True


def infer_category_and_style(kensamei: str, file_kind: str = "", dicom_modality: str = "") -> tuple[str, str, str]:
    """
    プロファイル汎用フォールバック時や旧データ移行時に、検査名・拡張子・Modalityから
    正確な display_category / modality_code / badge_color を推定する。
    """
    k = (kensamei or "").strip()
    fk = (file_kind or "").upper()
    dm = (dicom_modality or "").upper()

    if fk == "ECG" or dm == "ECG" or re.search(r"心電図|ECG", k, re.I):
        return "ecg", "ECG", "#e11d48"
    if fk == "VIDEO":
        if re.search(r"エコー|超音波|US", k, re.I):
            return "us", (dm or "US"), "#0284c7"
        return "endoscopy", (dm or "ES"), "#0d9488"
    if dm == "CT" or re.search(r"CT", k, re.I):
        return "xray", "CT", "#0284c7"
    if dm == "MR" or re.search(r"MRI|MR検査", k, re.I):
        return "xray", "MR", "#4f46e5"
    if dm == "ES" or k == "ES_General" or re.search(r"内視鏡|胃|大腸|食道|十二指腸|直腸|結腸|GF|CF|カメラ|スコープ|検診", k, re.I):
        return "endoscopy", (dm or "ES"), "#059669"
    if dm == "US" or k.startswith("US_") or re.search(r"エコー|超音波|US|Adult_Abd|Carotid", k, re.I):
        return "us", (dm or "US"), "#0284c7"
    if dm in ("DX", "CR", "RF", "XA") or re.search(r"胸部|腹部|腰椎|レントゲン|XP|CR|DR|RF|透視|骨|単純", k, re.I):
        return "xray", (dm or "DX"), "#7c3aed"
    return "other", (dm or "OT"), "#475569"


def match_modality_profile(conn, meta: dict, record_discovery: bool = True) -> dict:
    """
    DBの modality_profiles を優先順で評価し、一致するプロファイルと自動決定した検査名を返す。
    未登録の新しい装置指紋を検知した場合は discovered_devices へ自動登録する（プラグ＆プレイ検出）。
    """
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT * FROM modality_profiles
            WHERE is_enabled = TRUE
            ORDER BY priority_order ASC, profile_id ASC
            """
        )
        profiles = cur.fetchall()

    matched = None
    for p in profiles:
        rules = p.get("match_rules") or {}
        if isinstance(rules, str):
            rules = json.loads(rules)
        if rule_matches(rules, meta):
            matched = dict(p)
            break

    if matched is None:
        matched = {
            "profile_id": None,
            "profile_code": "UNMATCHED_FALLBACK",
            "device_name": "未登録モダリティ",
            "modality_code": meta.get("dicom_modality") or "OT",
            "display_category": "other",
            "default_kensamei": meta.get("dicom_modality") or "外部取込画像",
            "kensamei_from_tag": "BODY_OR_PROTOCOL",
            "badge_color": "#475569",
            "priority_order": 9999,
        }

    kensamei = resolve_kensamei_by_strategy(matched, meta)
    matched["resolved_kensamei"] = kensamei

    # 汎用プロファイル (priority >= 900) に落ちた場合でも、Modalityや検査名から適切なカテゴリを補完
    if int(matched.get("priority_order") or 999) >= 900 or matched.get("display_category") == "other":
        inf_cat, inf_mod, inf_col = infer_category_and_style(
            kensamei,
            meta.get("file_ext") if (meta.get("file_ext") or "").lower() in ("ecg", "001") else meta.get("file_kind", ""),
            meta.get("dicom_modality", ""),
        )
        if inf_cat != "other":
            matched["display_category"] = inf_cat
            matched["modality_code"] = inf_mod
            matched["badge_color"] = inf_col

    # 自動検出レーダー記録（汎用フォールバックに落ちた装置、または新規IP/Station指紋を記録）
    if record_discovery and meta.get("source_protocol") not in ("MIGRATION", "SIMULATOR", "HTTP_API", "CD_IMPORT"):
        fp_parts = [
            meta.get("source_protocol") or "",
            meta.get("source_ip") or "",
            meta.get("calling_ae_title") or "",
            meta.get("station_name") or "",
            meta.get("manufacturer") or "",
            meta.get("model_name") or "",
            meta.get("dicom_modality") or "",
            meta.get("file_ext") or "",
        ]
        if any(fp_parts[1:]):
            fp_key = "|".join(fp_parts)[:250]
            linked_id = matched["profile_id"] if (matched.get("priority_order", 999) < 900) else None
            res_str = f"{meta.get('width', '')}x{meta.get('height', '')}".strip("x")
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO discovered_devices
                    (fingerprint_key, source_protocol, source_ip, calling_ae_title, station_name,
                     manufacturer, model_name, dicom_modality, sample_resolution, sample_file_ext, linked_profile_id)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    ON CONFLICT (fingerprint_key) DO UPDATE SET
                        last_seen_at = NOW(),
                        seen_count = discovered_devices.seen_count + 1,
                        sample_resolution = COALESCE(NULLIF(EXCLUDED.sample_resolution, ''), discovered_devices.sample_resolution),
                        linked_profile_id = COALESCE(discovered_devices.linked_profile_id, EXCLUDED.linked_profile_id)
                    """,
                    (
                        fp_key,
                        meta.get("source_protocol") or "UNKNOWN",
                        meta.get("source_ip") or "",
                        meta.get("calling_ae_title") or "",
                        meta.get("station_name") or "",
                        meta.get("manufacturer") or "",
                        meta.get("model_name") or "",
                        meta.get("dicom_modality") or "",
                        res_str,
                        (meta.get("file_ext") or "").lstrip("."),
                        linked_id,
                    ),
                )

    return matched

