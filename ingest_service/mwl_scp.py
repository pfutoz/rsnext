import os
import re
import csv
import sys
import json
import logging
from datetime import datetime, date

import pydicom
from pydicom.dataset import Dataset
from pydicom.sequence import Sequence
from pydicom.uid import (
    UID,
    generate_uid,
    ExplicitVRLittleEndian,
    ImplicitVRLittleEndian,
)
from pynetdicom import AE
from pynetdicom.sop_class import (
    ModalityWorklistInformationFind,
    ModalityPerformedProcedureStep,
)

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from ingest_service.common import (
    load_config,
    get_db_conn,
    normalize_patient_id,
    normalize_kana_yomi,
    log_ingest_event,
)

logger = logging.getLogger("rsnext.mwl_scp")

# JIS X 0208 (ISO 2022 IR 87) 非収録の異体字を標準漢字へ安全マッピング
JIS0208_VARIANT_MAP = str.maketrans({
    "髙": "高",
    "﨑": "崎",
    "濵": "浜",
    "濱": "浜",
    "邊": "辺",
    "邉": "辺",
    "齋": "斎",
    "齊": "斉",
    "栁": "柳",
    "桒": "桑",
    "德": "徳",
    "𠮷": "吉",
    "塚": "塚",
    "增": "増",
    "寬": "寛",
    "瀨": "瀬",
    "〜": "ー",
    "－": "-",
})

KANA_ROMAJI_DIGRAPHS = {
    "キャ": "KYA", "キュ": "KYU", "キョ": "KYO",
    "シャ": "SHA", "シュ": "SHU", "ショ": "SHO",
    "チャ": "CHA", "チュ": "CHU", "チョ": "CHO",
    "ニャ": "NYA", "ニュ": "NYU", "ニョ": "NYO",
    "ヒャ": "HYA", "ヒュ": "HYU", "ヒョ": "HYO",
    "ミャ": "MYA", "ミュ": "MYU", "ミョ": "MYO",
    "リャ": "RYA", "リュ": "RYU", "リョ": "RYO",
    "ギャ": "GYA", "ギュ": "GYU", "ギョ": "GYO",
    "ジャ": "JA",  "ジュ": "JU",  "ジョ": "JO",
    "ヂャ": "JA",  "ヂュ": "JU",  "ヂョ": "JO",
    "ビャ": "BYA", "ビュ": "BYU", "ビョ": "BYO",
    "ピャ": "PYA", "ピュ": "PYU", "ピョ": "PYO",
    "ファ": "FA",  "フィ": "FI",  "フェ": "FE",  "フォ": "FO",
    "ティ": "TI",  "ディ": "DI",  "トゥ": "TU",  "ドゥ": "DU",
    "ウィ": "WI",  "ウェ": "WE",  "ウォ": "WO",
    "ヴァ": "VA",  "ヴィ": "VI",  "ヴ": "VU",    "ヴェ": "VE", "ヴォ": "VO",
}

KANA_ROMAJI_MONO = {
    "ア": "A", "イ": "I", "ウ": "U", "エ": "E", "オ": "O",
    "カ": "KA", "キ": "KI", "ク": "KU", "ケ": "KE", "コ": "KO",
    "サ": "SA", "シ": "SHI", "ス": "SU", "セ": "SE", "ソ": "SO",
    "タ": "TA", "チ": "CHI", "ツ": "TSU", "テ": "TE", "ト": "TO",
    "ナ": "NA", "ニ": "NI", "ヌ": "NU", "ネ": "NE", "ノ": "NO",
    "ハ": "HA", "ヒ": "HI", "フ": "FU", "ヘ": "HE", "ホ": "HO",
    "マ": "MA", "ミ": "MI", "ム": "MU", "メ": "ME", "モ": "MO",
    "ヤ": "YA", "ユ": "YU", "ヨ": "YO",
    "ラ": "RA", "リ": "RI", "ル": "RU", "レ": "RE", "ロ": "RO",
    "ワ": "WA", "ヰ": "I", "ヱ": "E", "ヲ": "O", "ン": "N",
    "ガ": "GA", "ギ": "GI", "グ": "GU", "ゲ": "GE", "ゴ": "GO",
    "ザ": "ZA", "ジ": "JI", "ズ": "ZU", "ゼ": "ZE", "ゾ": "ZO",
    "ダ": "DA", "ヂ": "JI", "ヅ": "ZU", "デ": "DE", "ド": "DO",
    "バ": "BA", "ビ": "BI", "ブ": "BU", "ベ": "BE", "ボ": "BO",
    "パ": "PA", "ピ": "PI", "プ": "PU", "ペ": "PE", "ポ": "PO",
    "ァ": "A", "ィ": "I", "ゥ": "U", "ェ": "E", "ォ": "O",
    "ャ": "YA", "ュ": "YU", "ョ": "YO", "ヮ": "WA",
}


def sanitize_jis0208(text: str) -> str:
    if not text:
        return ""
    s = str(text).translate(JIS0208_VARIANT_MAP)
    out = []
    for ch in s:
        try:
            ch.encode("iso2022_jp")
            out.append(ch)
        except UnicodeEncodeError:
            # JIS X 0208 範囲外の文字は安全に除去または空白化
            out.append(" ")
    return "".join(out).strip()


def kana_word_to_romaji(kana_word: str) -> str:
    if not kana_word:
        return ""
    # ひらがな・半角カナを全角カタカナへ正規化
    s = normalize_kana_yomi(kana_word)
    tokens = []
    i = 0
    n = len(s)
    while i < n:
        if s[i] == "ッ":
            tokens.append("__SOKUON__")
            i += 1
            continue
        if s[i] == "ー":
            i += 1
            continue
        if i + 1 < n and s[i : i + 2] in KANA_ROMAJI_DIGRAPHS:
            tokens.append(KANA_ROMAJI_DIGRAPHS[s[i : i + 2]])
            i += 2
            continue
        ch = s[i]
        if ch in KANA_ROMAJI_MONO:
            tokens.append(KANA_ROMAJI_MONO[ch])
        elif ch.isascii() and ch.isalnum():
            tokens.append(ch.upper())
        i += 1

    res = []
    for idx, tk in enumerate(tokens):
        if tk == "__SOKUON__":
            if idx + 1 < len(tokens) and tokens[idx + 1] != "__SOKUON__" and tokens[idx + 1]:
                first_c = tokens[idx + 1][0]
                if first_c.isalpha():
                    res.append("T" if tokens[idx + 1].startswith("CH") else first_c)
        else:
            res.append(tk)

    joined = "".join(res)
    # 長音 O+U (例: OONO, TAROU -> TARO も許容されるがヘボン式簡略化)
    joined = re.sub(r"OU$", "O", joined)
    return joined


def split_family_given(full_name: str) -> tuple[str, str]:
    if not full_name:
        return "", ""
    parts = [p.strip() for p in re.split(r"[\s　\^]+", str(full_name).strip()) if p.strip()]
    if len(parts) >= 2:
        return parts[0], " ".join(parts[1:])
    if len(parts) == 1:
        return parts[0], ""
    return "", ""


def build_dicom_person_name(kanji: str, yomi: str, fallback_id: str, charset_mode: str = "ISO_2022_IR_87") -> str:
    fam_k, giv_k = split_family_given(kanji)
    fam_y, giv_y = split_family_given(yomi)
    # カナにスペースが無い場合でも全角カタカナ変換
    fam_y_kata = normalize_kana_yomi(fam_y)
    giv_y_kata = normalize_kana_yomi(giv_y)

    fam_rom = kana_word_to_romaji(fam_y_kata) if fam_y_kata else ""
    giv_rom = kana_word_to_romaji(giv_y_kata) if giv_y_kata else ""
    if not fam_rom and kanji and kanji.isascii():
        fam_rom, giv_rom = split_family_given(kanji.upper())
    if not fam_rom:
        fam_rom = f"ID{fallback_id}"

    alpha_comp = f"{fam_rom}^{giv_rom}".rstrip("^")
    if charset_mode == "ASCII_ROMAJI":
        return alpha_comp[:64]

    ideo_comp = f"{fam_k}^{giv_k}".rstrip("^")
    phon_comp = f"{fam_y_kata}^{giv_y_kata}".rstrip("^")

    if charset_mode == "ISO_2022_IR_87":
        ideo_comp = sanitize_jis0208(ideo_comp)
        phon_comp = sanitize_jis0208(phon_comp)

    if ideo_comp or phon_comp:
        return f"{alpha_comp}={ideo_comp}={phon_comp}".rstrip("=")
    return alpha_comp


def format_dicom_sex(raw_sex: str) -> str:
    s = (raw_sex or "").strip().upper()
    if s in ("男", "男性", "M", "MALE", "1"):
        return "M"
    if s in ("女", "女性", "F", "FEMALE", "2"):
        return "F"
    return "O"


def calc_dicom_age(birth_dt: date | None, ref_dt: date | None = None) -> str:
    if not birth_dt:
        return ""
    ref = ref_dt or date.today()
    years = ref.year - birth_dt.year - ((ref.month, ref.day) < (birth_dt.month, birth_dt.day))
    years = max(0, min(999, years))
    return f"{years:03d}Y"


def parse_dicom_date_range(val: str) -> tuple[date | None, date | None]:
    """
    DICOM Date Range ('YYYYMMDD', 'YYYYMMDD-YYYYMMDD', 'YYYYMMDD-', '-YYYYMMDD') を解釈する。
    """
    if not val:
        return None, None
    s = str(val).strip()
    if not s or s == "*":
        return None, None

    def _p(dstr: str) -> date | None:
        digits = re.sub(r"[^0-9]", "", dstr)
        if len(digits) == 8:
            try:
                return date(int(digits[0:4]), int(digits[4:6]), int(digits[6:8]))
            except ValueError:
                return None
        return None

    if "-" in s and len(s) >= 9:
        left, right = s.split("-", 1)
        return _p(left), _p(right)
    single = _p(s)
    return single, single


def resolve_caller_charset(conn, calling_ae: str, source_ip: str, cfg: dict) -> str:
    default_cs = cfg.get("ingest", {}).get("dicom_scp", {}).get("mwl_default_charset", "ISO_2022_IR_87")
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT match_rules, mwl_config FROM modality_profiles WHERE is_enabled = TRUE ORDER BY priority_order ASC")
            for row in cur.fetchall():
                rules = row.get("match_rules") or {}
                mwl_c = row.get("mwl_config") or {}
                if isinstance(rules, str):
                    rules = json.loads(rules)
                if isinstance(mwl_c, str):
                    mwl_c = json.loads(mwl_c)
                ae_match = calling_ae and (
                    calling_ae.upper() == str(mwl_c.get("scheduled_aet") or "").upper()
                    or calling_ae.upper() == str(rules.get("calling_ae_title") or "").upper()
                    or calling_ae.upper() == str(rules.get("station_name") or "").upper()
                )
                ip_match = source_ip and source_ip == str(rules.get("source_ip") or "")
                if ae_match or ip_match:
                    return str(mwl_c.get("charset") or default_cs)
    except Exception:
        pass
    return default_cs


def build_mwl_response_dataset(order_row: dict, charset_mode: str = "ISO_2022_IR_87") -> Dataset:
    ds = Dataset()
    if charset_mode == "UTF8":
        ds.SpecificCharacterSet = "ISO_IR 192"
    elif charset_mode == "ASCII_ROMAJI":
        ds.SpecificCharacterSet = "ISO_IR 100"
    else:
        ds.SpecificCharacterSet = ["", "ISO 2022 IR 87"]

    def _txt(val: str) -> str:
        s = str(val or "").strip()
        if charset_mode == "ASCII_ROMAJI":
            return s.encode("ascii", errors="ignore").decode("ascii")
        if charset_mode == "ISO_2022_IR_87":
            return sanitize_jis0208(s)
        return s

    pid = str(order_row["patient_id"])
    kanji = str(order_row.get("kanja_kanji") or "")
    yomi = str(order_row.get("kanja_yomi") or "")
    b_dt: date | None = order_row.get("birth_date")
    s_dt: date = order_row.get("scheduled_date") or date.today()
    s_tm = order_row.get("scheduled_time")

    ds.AccessionNumber = str(order_row["accession_number"])[:16]
    ds.StudyInstanceUID = str(order_row["study_instance_uid"])
    ds.PatientID = pid
    ds.PatientName = build_dicom_person_name(kanji, yomi, pid, charset_mode=charset_mode)
    ds.PatientBirthDate = b_dt.strftime("%Y%m%d") if b_dt else ""
    ds.PatientSex = format_dicom_sex(str(order_row.get("sex") or ""))
    age_str = calc_dicom_age(b_dt, s_dt)
    if age_str:
        ds.PatientAge = age_str

    req_doc = _txt(order_row.get("requesting_doctor") or "")
    ds.ReferringPhysicianName = req_doc
    ds.RequestingPhysician = req_doc
    ds.RequestingService = _txt(order_row.get("requesting_dept") or "")

    proc_desc = _txt(order_row.get("procedure_desc") or order_row.get("kensamei") or "Exam")
    ds.RequestedProcedureDescription = proc_desc[:64]
    ds.RequestedProcedureID = f"RP{order_row['order_id']}"[:16]
    ds.PatientComments = _txt(order_row.get("order_comment") or "")

    # Scheduled Procedure Step Sequence (0040,0100)
    sps = Dataset()
    sps.Modality = str(order_row.get("modality") or "OT").upper()
    sps.ScheduledStationAETitle = str(order_row.get("scheduled_aet") or "ANY")[:16]
    sps.ScheduledStationName = _txt(order_row.get("scheduled_station") or "")[:16]
    sps.ScheduledProcedureStepStartDate = s_dt.strftime("%Y%m%d")
    sps.ScheduledProcedureStepStartTime = s_tm.strftime("%H%M%S") if s_tm else "090000"
    sps.ScheduledPerformingPhysicianName = req_doc
    sps.ScheduledProcedureStepDescription = proc_desc[:64]
    sps.ScheduledProcedureStepID = f"SPS{order_row['order_id']}"[:16]

    proto_code = _txt(order_row.get("protocol_code") or order_row.get("preset_code") or "")
    if proto_code:
        p_item = Dataset()
        p_item.CodeValue = proto_code[:16]
        p_item.CodingSchemeDesignator = "99RSNEXT"
        p_item.CodeMeaning = proc_desc[:64]
        sps.ScheduledProtocolCodeSequence = Sequence([p_item])

    ds.ScheduledProcedureStepSequence = Sequence([sps])
    return ds


def handle_mwl_find(event, cfg: dict):
    """
    pynetdicom EVT_C_FIND ハンドラ (Modality Worklist SCP)
    1. modality_orders テーブルから SCHEDULED / IN_PROGRESS オーダーを検索して返却
    2. もし特定の PatientID 指定照会（ID照会モード）で該当オーダーが0件の場合は、
       patients マスタから即座にオンデマンドMWLデータを自動合成して返却（スマートID照会フォールバック）
    """
    ident = event.identifier
    if ident is None:
        yield (0xC000, None)
        return

    req = event.assoc.requestor
    source_ip = getattr(req, "address", "") or ""
    calling_ae = getattr(req, "ae_title", "") or ""
    if isinstance(calling_ae, bytes):
        calling_ae = calling_ae.decode("ascii", errors="ignore")
    calling_ae = str(calling_ae).strip()

    q_pid_raw = str(getattr(ident, "PatientID", "") or "").strip()
    q_acc_raw = str(getattr(ident, "AccessionNumber", "") or "").strip().strip("*")

    q_mod = ""
    q_aet = ""
    q_date_raw = ""
    if "ScheduledProcedureStepSequence" in ident and len(ident.ScheduledProcedureStepSequence) > 0:
        sps_q = ident.ScheduledProcedureStepSequence[0]
        q_mod = str(getattr(sps_q, "Modality", "") or "").strip().strip("*").upper()
        q_aet = str(getattr(sps_q, "ScheduledStationAETitle", "") or "").strip().strip("*")
        q_date_raw = str(getattr(sps_q, "ScheduledProcedureStepStartDate", "") or "").strip()

    d_from, d_to = parse_dicom_date_range(q_date_raw)

    conn = get_db_conn(cfg)
    try:
        charset_mode = resolve_caller_charset(conn, calling_ae, source_ip, cfg)

        where = ["o.status IN ('SCHEDULED', 'IN_PROGRESS')"]
        params = []

        if q_acc_raw:
            where.append("o.accession_number ILIKE %s")
            params.append(f"%{q_acc_raw}%")

        clean_pid_q = q_pid_raw.replace("*", "").replace("?", "").strip()
        norm_pid_q = None
        if clean_pid_q:
            norm_pid_q, _ = normalize_patient_id(clean_pid_q)
            if "*" in q_pid_raw or "?" in q_pid_raw:
                where.append("o.patient_id LIKE %s")
                params.append(f"%{norm_pid_q or clean_pid_q}%")
            else:
                where.append("o.patient_id = %s")
                params.append(norm_pid_q or clean_pid_q)

        if q_mod:
            # CR と DX はレントゲン装置コンソールの設定差異を自動吸収して両方ヒットさせる
            if q_mod in ("DX", "CR"):
                where.append("o.modality IN ('DX', 'CR')")
            else:
                where.append("o.modality = %s")
                params.append(q_mod)

        if q_aet:
            # オーダー側に特定のAETが指定されている場合のみ絞り込み、空欄オーダーは全装置へ公開
            where.append("(o.scheduled_aet = '' OR UPPER(o.scheduled_aet) = UPPER(%s))")
            params.append(q_aet)

        if d_from and d_to:
            where.append("o.scheduled_date BETWEEN %s AND %s")
            params.extend([d_from, d_to])
        elif d_from:
            where.append("o.scheduled_date >= %s")
            params.append(d_from)
        elif d_to:
            where.append("o.scheduled_date <= %s")
            params.append(d_to)

        sql = f"""
            SELECT o.*, p.kanja_kanji, p.kanja_yomi, p.birth_date, p.sex
            FROM modality_orders o
            JOIN patients p ON p.patient_id = o.patient_id
            WHERE {' AND '.join(where)}
            ORDER BY o.scheduled_date ASC, o.scheduled_time ASC NULLS LAST, o.order_id ASC
            LIMIT 200
        """
        with conn.cursor() as cur:
            cur.execute(sql, tuple(params))
            rows = cur.fetchall()

        # スマートID照会フォールバック:
        # 装置で具体的な患者IDを入力して照会したが事前オーダーが無い場合、patients マスタから即座に属性を返す
        fallback_enabled = bool(cfg.get("ingest", {}).get("dicom_scp", {}).get("mwl_id_lookup_fallback", True))
        if not rows and fallback_enabled and norm_pid_q and "*" not in q_pid_raw:
            with conn.cursor() as cur:
                cur.execute("SELECT * FROM patients WHERE patient_id = %s", (norm_pid_q,))
                pat = cur.fetchone()
            if pat:
                today_d = date.today()
                acc_auto = f"ID{today_d.strftime('%y%m%d')}{norm_pid_q}"[:16]
                rows = [{
                    "order_id": 0,
                    "accession_number": acc_auto,
                    "study_instance_uid": str(generate_uid()),
                    "patient_id": pat["patient_id"],
                    "kanja_kanji": pat["kanja_kanji"],
                    "kanja_yomi": pat["kanja_yomi"],
                    "birth_date": pat["birth_date"],
                    "sex": pat["sex"],
                    "modality": q_mod or "OT",
                    "scheduled_aet": calling_ae or "ANY",
                    "scheduled_station": "",
                    "scheduled_date": today_d,
                    "scheduled_time": datetime.now().time(),
                    "kensamei": f"{q_mod or '一般'}検査",
                    "procedure_desc": f"ID照会 ({pat['kanja_kanji']})",
                    "preset_code": "ID_LOOKUP",
                    "protocol_code": "",
                    "requesting_doctor": "",
                    "requesting_dept": "",
                    "order_comment": "",
                }]
                log_ingest_event(
                    conn,
                    "INFO",
                    "DICOM_MWL",
                    "MWL_ID_LOOKUP_HIT",
                    f"MWL スマートID照会応答: 患者ID {norm_pid_q} ({pat['kanja_kanji']}) -> {calling_ae or source_ip}",
                    patient_id=norm_pid_q,
                )
                conn.commit()
    finally:
        conn.close()

    for r in rows:
        if event.is_cancelled:
            yield (0xFE00, None)
            return
        resp_ds = build_mwl_response_dataset(r, charset_mode=charset_mode)
        yield (0xFF00, resp_ds)


def handle_mpps_create(event, cfg: dict):
    """
    DICOM MPPS N-CREATE ハンドラ (モダリティでの検査開始通知 -> ステータスを IN_PROGRESS へ更新)
    """
    req = event.request
    attr = event.attribute_list or Dataset()
    mpps_uid = str(getattr(req, "AffectedSOPInstanceUID", "") or generate_uid())

    acc_no = ""
    study_uid = ""
    pat_id_raw = str(getattr(attr, "PatientID", "") or "").strip()
    mod = str(getattr(attr, "Modality", "") or "").strip().upper()

    if "ScheduledStepAttributesSequence" in attr and len(attr.ScheduledStepAttributesSequence) > 0:
        ssa = attr.ScheduledStepAttributesSequence[0]
        acc_no = str(getattr(ssa, "AccessionNumber", "") or "").strip()
        study_uid = str(getattr(ssa, "StudyInstanceUID", "") or "").strip()

    norm_pid, _ = normalize_patient_id(pat_id_raw) if pat_id_raw else (None, "")

    conn = get_db_conn(cfg)
    try:
        with conn.cursor() as cur:
            matched_order = None
            if acc_no:
                cur.execute("SELECT order_id, patient_id, kensamei FROM modality_orders WHERE accession_number = %s", (acc_no,))
                matched_order = cur.fetchone()
            if not matched_order and study_uid:
                cur.execute("SELECT order_id, patient_id, kensamei FROM modality_orders WHERE study_instance_uid = %s", (study_uid,))
                matched_order = cur.fetchone()
            if not matched_order and norm_pid:
                cur.execute(
                    """
                    SELECT order_id, patient_id, kensamei FROM modality_orders
                    WHERE patient_id = %s AND scheduled_date = CURRENT_DATE AND status = 'SCHEDULED'
                      AND (%s = '' OR modality = %s)
                    ORDER BY order_id ASC LIMIT 1
                    """,
                    (norm_pid, mod, mod),
                )
                matched_order = cur.fetchone()

            if matched_order:
                cur.execute(
                    """
                    UPDATE modality_orders
                    SET status = 'IN_PROGRESS', mpps_sop_uid = %s, started_at = COALESCE(started_at, NOW()), updated_at = NOW()
                    WHERE order_id = %s
                    """,
                    (mpps_uid, matched_order["order_id"]),
                )
                log_ingest_event(
                    conn,
                    "INFO",
                    "DICOM_MPPS",
                    "MPPS_IN_PROGRESS",
                    f"MPPS 検査開始通知: オーダー #{matched_order['order_id']} ({matched_order['kensamei']})",
                    patient_id=matched_order["patient_id"],
                )
                conn.commit()
                export_today_orders_csv(conn, cfg)
    except Exception as e:
        logger.warning("MPPS N-CREATE error: %s", e)
    finally:
        conn.close()

    resp = Dataset()
    resp.SOPClassUID = ModalityPerformedProcedureStep
    resp.SOPInstanceUID = mpps_uid
    return 0x0000, resp


def handle_mpps_set(event, cfg: dict):
    """
    DICOM MPPS N-SET ハンドラ (モダリティでの撮影完了 COMPLETED / 中止 DISCONTINUED 通知)
    """
    req = event.request
    mod_list = event.modification_list or Dataset()
    mpps_uid = str(getattr(req, "RequestedSOPInstanceUID", "") or "").strip()
    pps_status = str(getattr(mod_list, "PerformedProcedureStepStatus", "COMPLETED") or "COMPLETED").strip().upper()

    new_state = "CANCELLED" if pps_status == "DISCONTINUED" else "COMPLETED"

    conn = get_db_conn(cfg)
    try:
        if mpps_uid:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    UPDATE modality_orders
                    SET status = %s,
                        completed_at = CASE WHEN %s = 'COMPLETED' THEN NOW() ELSE completed_at END,
                        updated_at = NOW()
                    WHERE mpps_sop_uid = %s
                    RETURNING order_id, patient_id, kensamei
                    """,
                    (new_state, new_state, mpps_uid),
                )
                updated = cur.fetchone()
                if updated:
                    log_ingest_event(
                        conn,
                        "INFO",
                        "DICOM_MPPS",
                        f"MPPS_{new_state}",
                        f"MPPS 検査{('完了' if new_state == 'COMPLETED' else '中止')}通知: オーダー #{updated['order_id']} ({updated['kensamei']})",
                        patient_id=updated["patient_id"],
                    )
                    conn.commit()
                    export_today_orders_csv(conn, cfg)
    except Exception as e:
        logger.warning("MPPS N-SET error: %s", e)
    finally:
        conn.close()

    resp = Dataset()
    resp.SOPClassUID = ModalityPerformedProcedureStep
    resp.SOPInstanceUID = mpps_uid
    return 0x0000, resp


def export_today_orders_csv(conn, cfg: dict = None):
    """
    非MWL外部連携ソフト（フォルダ監視型心電計ゲートウェイ・コンソールCSV取込等）向けに
    当日有効オーダー一覧を UTF-8 および CP932 CSV として outbox へ自動書き出しする。
    """
    cfg = cfg or load_config()
    out_dir = cfg.get("ingest", {}).get("dicom_scp", {}).get("orders_outbox_dir") or os.path.join(
        cfg["storage"]["inbox_root"], "..", "outbox", "orders"
    )
    os.makedirs(out_dir, exist_ok=True)

    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT o.order_id, o.accession_number, o.scheduled_date, o.scheduled_time,
                   o.modality, o.kensamei, o.procedure_desc, o.status, o.requesting_doctor,
                   p.patient_id, p.kanja_kanji, p.kanja_yomi, p.birth_date, p.sex
            FROM modality_orders o
            JOIN patients p ON p.patient_id = o.patient_id
            WHERE o.scheduled_date = CURRENT_DATE AND o.status IN ('SCHEDULED', 'IN_PROGRESS')
            ORDER BY o.order_id ASC
            """
        )
        rows = cur.fetchall()

    headers = ["AccessionNo", "PatientID", "KanaName", "KanjiName", "BirthDate", "Sex", "Modality", "ExamName", "ProcedureDesc", "Doctor", "Status"]
    csv_rows = []
    ecg_rows = []
    for r in rows:
        item = [
            r["accession_number"],
            r["patient_id"],
            r["kanja_yomi"],
            r["kanja_kanji"],
            r["birth_date"].strftime("%Y/%m/%d") if r["birth_date"] else "",
            r["sex"],
            r["modality"],
            r["kensamei"],
            r["procedure_desc"],
            r["requesting_doctor"],
            r["status"],
        ]
        csv_rows.append(item)
        if r["modality"] == "ECG":
            ecg_rows.append(item)

    def _write_csv(fp: str, data_rows: list, enc: str):
        tmp = fp + f".tmp_{os.getpid()}"
        with open(tmp, "w", encoding=enc, errors="replace", newline="\r\n") as wf:
            writer = csv.writer(wf)
            writer.writerow(headers)
            writer.writerows(data_rows)
        os.replace(tmp, fp)

    _write_csv(os.path.join(out_dir, "orders_today_utf8.csv"), csv_rows, "utf-8-sig")
    _write_csv(os.path.join(out_dir, "orders_today_cp932.csv"), csv_rows, "cp932")
    _write_csv(os.path.join(out_dir, "fukuda_ecg_orders_cp932.csv"), ecg_rows, "cp932")


def query_mwl_scu_test(
    host: str = "127.0.0.1",
    port: int = None,
    calling_aet: str = "TEST_CONSOLE",
    called_aet: str = "RSNEXT_MWL",
    modality: str = "",
    patient_id: str = "",
    sched_date: str = "",
) -> dict:
    """
    Web画面（worklist.php）から実際の DICOM C-FIND パケットを送信して MWL 応答を検証するテストSCU。
    """
    cfg = load_config()
    if port is None:
        port = int(cfg["ingest"]["dicom_scp"]["port"])

    ae = AE(ae_title=calling_aet[:16] or "TEST_SCU")
    ae.add_requested_context(ModalityWorklistInformationFind, [ExplicitVRLittleEndian, ImplicitVRLittleEndian])

    ds_q = Dataset()
    ds_q.SpecificCharacterSet = ["", "ISO 2022 IR 87"]
    ds_q.AccessionNumber = ""
    ds_q.PatientName = ""
    ds_q.PatientID = patient_id
    ds_q.PatientBirthDate = ""
    ds_q.PatientSex = ""
    ds_q.StudyInstanceUID = ""
    ds_q.RequestedProcedureDescription = ""

    sps = Dataset()
    sps.Modality = modality
    sps.ScheduledStationAETitle = ""
    sps.ScheduledProcedureStepStartDate = sched_date
    sps.ScheduledProcedureStepStartTime = ""
    sps.ScheduledPerformingPhysicianName = ""
    sps.ScheduledProcedureStepDescription = ""
    sps.ScheduledProcedureStepID = ""
    ds_q.ScheduledProcedureStepSequence = Sequence([sps])

    assoc = ae.associate(host, port, ae_title=called_aet[:16] or "RSNEXT_SCP")
    if not assoc.is_established:
        return {"status": "error", "message": f"DICOM MWL SCP ({host}:{port}) へ接続できませんでした"}

    matches = []
    try:
        responses = assoc.send_c_find(ds_q, ModalityWorklistInformationFind)
        for status, ident in responses:
            if status and status.Status in (0xFF00, 0xFF01) and ident is not None:
                sps_r = ident.ScheduledProcedureStepSequence[0] if "ScheduledProcedureStepSequence" in ident and len(ident.ScheduledProcedureStepSequence) > 0 else Dataset()
                matches.append({
                    "accession_number": str(getattr(ident, "AccessionNumber", "")),
                    "patient_id": str(getattr(ident, "PatientID", "")),
                    "patient_name_dicom": str(getattr(ident, "PatientName", "")),
                    "birth_date": str(getattr(ident, "PatientBirthDate", "")),
                    "sex": str(getattr(ident, "PatientSex", "")),
                    "study_instance_uid": str(getattr(ident, "StudyInstanceUID", "")),
                    "modality": str(getattr(sps_r, "Modality", "")),
                    "start_date": str(getattr(sps_r, "ScheduledProcedureStepStartDate", "")),
                    "start_time": str(getattr(sps_r, "ScheduledProcedureStepStartTime", "")),
                    "procedure_desc": str(getattr(sps_r, "ScheduledProcedureStepDescription", "")) or str(getattr(ident, "RequestedProcedureDescription", "")),
                    "charset": str(getattr(ident, "SpecificCharacterSet", "")),
                })
    finally:
        assoc.release()

    return {"status": "success", "host": host, "port": port, "count": len(matches), "items": matches}


def main():
    if len(sys.argv) >= 2 and sys.argv[1] == "test_find":
        mod = sys.argv[2] if len(sys.argv) >= 3 else ""
        pid = sys.argv[3] if len(sys.argv) >= 4 else ""
        dt = sys.argv[4] if len(sys.argv) >= 5 else ""
        res = query_mwl_scu_test(modality=mod, patient_id=pid, sched_date=dt)
        print(json.dumps(res, ensure_ascii=False))
        sys.exit(0)
    if len(sys.argv) >= 2 and sys.argv[1] == "export_csv":
        cfg = load_config()
        conn = get_db_conn(cfg)
        try:
            export_today_orders_csv(conn, cfg)
        finally:
            conn.close()
        print(json.dumps({"status": "success"}, ensure_ascii=False))
        sys.exit(0)


if __name__ == "__main__":
    main()
