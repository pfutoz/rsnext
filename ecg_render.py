import sys
import os
import re
import struct
import json
import numpy as np

# Static Huffman Tree for Fukuda Denshi 12L anal + wave
HUFF_TREE = [
    -1, -1, -23, -1, -1, -21, -1, -1, 19, -1, -1, -16, -1, -1, -14, -1, -1, 14,
    -1, -1, 16, -1, -1, -17, -1, -1, -19, -1, -1, -22, -1, -1, -24, -1, -1, -20,
    -1, -1, -25, 15, 14, 0, 5, 4, -13, 17, 16, 13, 6, 3, -15, 18, 7, 15, 20, 19,
    17, 2, 8, -18, 21, 11, 18, 22, 1, 20, 23, 9, 21, 24, 0, 22, 25, 10, 23, -1,
    12, 23,
]
HUFF_ROOT = 13

MFER_LEAD_CODES = {
    1: "I",
    2: "II",
    3: "V1",
    4: "V2",
    5: "V3",
    6: "V4",
    7: "V5",
    8: "V6",
    9: "V7",
    11: "V3R",
    12: "V4R",
    61: "III",
    62: "aVR",
    63: "aVL",
    64: "aVF",
    65: "-aVR",
}


class BitReader:
    def __init__(self, data, pos=0):
        self.data = data
        self.pos = pos

    def bit(self):
        widx = self.pos // 16
        o = widx * 2
        w = 0
        if o + 2 <= len(self.data):
            w = (self.data[o] << 8) | self.data[o + 1]
        v = (w >> (15 - (self.pos % 16))) & 1
        self.pos += 1
        return v

    def bits(self, n):
        v = 0
        for _ in range(n):
            v = (v << 1) | self.bit()
        return v


def _derive_missing_limb_leads(leads_mv: dict) -> dict:
    out = {}
    if "I" in leads_mv and "II" in leads_mv:
        l1 = np.asarray(leads_mv["I"], dtype=np.float32)
        l2 = np.asarray(leads_mv["II"], dtype=np.float32)
        out["I"] = l1.tolist()
        out["II"] = l2.tolist()
        out["III"] = leads_mv.get("III") or (l2 - l1).tolist()
        out["aVR"] = leads_mv.get("aVR") or (-(l1 + l2) / 2.0).tolist()
        out["aVL"] = leads_mv.get("aVL") or ((l1 - l2 / 2.0)).tolist()
        out["aVF"] = leads_mv.get("aVF") or ((l2 - l1 / 2.0)).tolist()
    else:
        for k in ("I", "II", "III", "aVR", "aVL", "aVF"):
            if k in leads_mv:
                out[k] = leads_mv[k]

    for v in ("V1", "V2", "V3", "V4", "V5", "V6"):
        if v in leads_mv:
            out[v] = leads_mv[v]
    for k, v in leads_mv.items():
        if k not in out:
            out[k] = v
    return out


def parse_mfer_data(data: bytes) -> dict | None:
    """
    MFER (Medical waveform Format Encoding Rules / ISO 22077-1: .mwf) ネイティブデコーダ。
    日本光電 Cardiofax (ECG-2450 / 2550 / 3150 / 3350 等) やMFER準拠心電計の波形・患者属性を復号する。
    """
    pos = 0
    n = len(data)
    endian = ">"  # MFER既定はBig-Endian
    data_type = 0  # 0=int16, 1=uint16, 2=int32, 4=uint8, 5=int8
    n_channels = 8
    block_len = 0
    n_seq = 1
    sample_hz = 500.0
    mv_per_lsb = 0.00125  # 既定 1.25 uV
    chan_lead_map: dict[int, str] = {}
    patient_id = ""
    patient_name = ""
    exam_date = ""
    device_name = "Nihon Kohden (MFER)"
    wave_bytes = b""

    def _decode_str(b: bytes) -> str:
        for enc in ("cp932", "utf-8", "iso2022_jp", "latin1"):
            try:
                return b.decode(enc).strip("\x00 \r\n\t")
            except Exception:
                continue
        return ""

    def _decode_mfer_unit_val(v: bytes) -> tuple[int, int, float]:
        # [unit_code (1B), exponent_int8 (1B), mantissa (1..4B signed)]
        if len(v) < 3:
            return 0, 0, 0.0
        ucode = v[0]
        exp = struct.unpack("b", v[1:2])[0]
        mant = int.from_bytes(v[2:], byteorder="big" if endian == ">" else "little", signed=True)
        return ucode, exp, float(mant) * (10.0 ** exp)

    while pos < n:
        tag = data[pos]
        pos += 1
        if tag == 0x80:  # End of contents
            break
        if tag == 0x00:
            continue

        ch_idx = None
        if tag == 0x3F:  # Channel-specific attribute prefix
            if pos >= n:
                break
            ch_idx = data[pos] & 0x3F
            pos += 1
            if pos >= n:
                break
            tag = data[pos]
            pos += 1

        if pos >= n:
            break
        l0 = data[pos]
        pos += 1
        if l0 == 0x80:
            # Indefinite length (typically waveform 0x1E until 0x80 0x00)
            end_mk = data.find(b"\x80\x00", pos)
            if end_mk == -1:
                val = data[pos:]
                pos = n
            else:
                val = data[pos:end_mk]
                pos = end_mk + 2
        elif l0 & 0x80:
            n_len_bytes = l0 & 0x7F
            if pos + n_len_bytes > n:
                break
            length = int.from_bytes(data[pos : pos + n_len_bytes], "big")
            pos += n_len_bytes
            val = data[pos : pos + length]
            pos += length
        else:
            length = l0
            val = data[pos : pos + length]
            pos += length

        if tag == 0x01 and val:  # Byte order: 0=BE, 1=LE
            endian = "<" if val[0] == 1 else ">"
        elif tag == 0x05 and val:  # Number of channels
            n_channels = max(1, int.from_bytes(val, "big" if endian == ">" else "little"))
        elif tag == 0x06 and val:  # Number of sequences
            n_seq = max(1, int.from_bytes(val, "big" if endian == ">" else "little"))
        elif tag == 0x0A and val:  # Pointer / data block length (samples per block)
            block_len = max(1, int.from_bytes(val, "big" if endian == ">" else "little"))
        elif tag == 0x04 and val:  # Data type
            data_type = val[0]
        elif tag == 0x0B and len(val) >= 3:  # Sampling rate / interval
            ucode, _, fval = _decode_mfer_unit_val(val)
            if fval > 0:
                if ucode == 0:  # Hz
                    sample_hz = fval
                elif ucode == 1:  # sec interval
                    sample_hz = round(1.0 / fval, 1)
        elif tag == 0x0C and len(val) >= 3:  # Sampling resolution (V -> mV)
            ucode, _, fval = _decode_mfer_unit_val(val)
            if fval > 0:
                # MFER ucode=18 is Volts -> convert V to mV (* 1000)
                mv_per_lsb = fval * 1000.0 if ucode == 18 else fval
        elif tag == 0x09 and val:  # Waveform attribute / Lead code
            lcode = int.from_bytes(val[: min(4, len(val))], "big" if endian == ">" else "little")
            if ch_idx is not None and lcode in MFER_LEAD_CODES:
                chan_lead_map[ch_idx] = MFER_LEAD_CODES[lcode]
        elif tag == 0x16 and val:  # Manufacturer / Model
            s = _decode_str(val)
            if s:
                device_name = s[:48]
        elif tag == 0x81 and val:  # Patient Name
            patient_name = _decode_str(val)
        elif tag == 0x82 and val:  # Patient ID
            patient_id = _decode_str(val)
        elif tag == 0x85 and len(val) >= 4:  # Measurement time: YYYY(2B) M(1B) D(1B) H(1B) m(1B) s(1B)
            yr = int.from_bytes(val[0:2], "big")
            mo = val[2]
            dy = val[3]
            hh = val[4] if len(val) > 4 else 0
            mm = val[5] if len(val) > 5 else 0
            ss = val[6] if len(val) > 6 else 0
            if 1970 <= yr <= 2100 and 1 <= mo <= 12 and 1 <= dy <= 31:
                exam_date = f"{yr:04d}/{mo:02d}/{dy:02d} {hh:02d}:{mm:02d}:{ss:02d}"
        elif tag == 0x1E and val:  # Waveform data
            wave_bytes += val

    if not wave_bytes:
        return None

    dtype_map = {0: "i2", 1: "u2", 2: "i4", 3: "u4", 4: "u1", 5: "i1"}
    np_dt = np.dtype(endian + dtype_map.get(data_type, "i2"))
    usable = (len(wave_bytes) // np_dt.itemsize) * np_dt.itemsize
    arr = np.frombuffer(wave_bytes[:usable], dtype=np_dt).astype(np.float32)
    total_samples = len(arr) // max(1, n_channels)
    if total_samples < 50:
        return None

    default_names = (
        ["I", "II", "V1", "V2", "V3", "V4", "V5", "V6"]
        if n_channels == 8
        else ["I", "II", "III", "aVR", "aVL", "aVF", "V1", "V2", "V3", "V4", "V5", "V6"]
    )
    raw_leads = {}
    if block_len > 0 and n_seq > 1 and block_len * n_channels * n_seq <= len(arr):
        reshaped = arr[: block_len * n_channels * n_seq].reshape((n_seq, n_channels, block_len))
        ch_first = np.transpose(reshaped, (1, 0, 2)).reshape((n_channels, n_seq * block_len))
    elif block_len == 1:
        ch_first = arr[: total_samples * n_channels].reshape((total_samples, n_channels)).T
    else:
        ch_first = arr[: total_samples * n_channels].reshape((n_channels, total_samples))

    samples_per_lead = int(ch_first.shape[1])
    for ci in range(n_channels):
        lname = chan_lead_map.get(ci) or (default_names[ci] if ci < len(default_names) else f"CH{ci+1}")
        raw_leads[lname] = (ch_first[ci] * mv_per_lsb).tolist()

    leads_12 = _derive_missing_limb_leads(raw_leads)
    hz = int(round(sample_hz)) if sample_hz > 0 else 500
    return {
        "vendor": "NIHON_KOHDEN_MFER",
        "device": device_name,
        "patient_id": patient_id,
        "patient_name": patient_name,
        "date": exam_date,
        "sample_rate_hz": hz,
        "duration_sec": round(samples_per_lead / max(1, hz), 1),
        "total_samples_per_lead": samples_per_lead,
        "measurements": {},
        "leads": leads_12,
    }


def parse_nkc_metadata(data: bytes) -> dict | None:
    """
    日本光電 旧独自形式 (.nkc: Magic 0xED 0x83) の被検者ID・自動計測値 (HR, PR, QRS, QT, QTc, Axis) 抽出。
    """
    if len(data) < 0x80 or data[:2] != b"\xed\x83":
        return None

    cnt = struct.unpack_from(">H", data, 0x10)[0]
    blocks = {}
    pos = 0x18
    for _ in range(min(cnt, 24)):
        if pos + 6 > len(data):
            break
        off, tag = struct.unpack_from(">IH", data, pos)
        pos += 6
        if 0 < off < len(data):
            blocks[tag] = off

    patient_id = ""
    if 0x0002 in blocks:
        b2 = data[blocks[0x0002] : blocks[0x0002] + 160]
        m = re.search(rb"([0-9]{1,12})\s+[12]?\x00", b2)
        if m:
            patient_id = m.group(1).decode("ascii", errors="ignore")

    measurements = {}
    if 0x0007 in blocks and blocks[0x0007] + 0x26 <= len(data):
        p = blocks[0x0007] + 0x14
        hr, pr, qrs, qt, qtc, p_ax, qrs_ax, t_ax = struct.unpack_from(">8h", data, p)
        if 20 <= hr <= 300:
            rr = int(round(60000.0 / hr))
            measurements = {
                "hr": hr,
                "rr_ms": rr,
                "rr_sec": round(rr / 1000.0, 3),
                "pr_ms": pr,
                "pr_sec": round(pr / 1000.0, 3) if pr > 0 else 0,
                "qrs_ms": qrs,
                "qrs_sec": round(qrs / 1000.0, 3) if qrs > 0 else 0,
                "qt_ms": qt,
                "qt_sec": round(qt / 1000.0, 3) if qt > 0 else 0,
                "qtc_b": round(qtc / 1000.0, 3) if qtc > 0 else 0,
                "qtc_f": round(qtc / 1000.0, 3) if qtc > 0 else 0,
                "axis_deg": qrs_ax,
            }

    return {
        "vendor": "NIHON_KOHDEN_NKC",
        "device": "Nihon Kohden Cardiofax (.nkc)",
        "patient_id": patient_id,
        "date": "",
        "measurements": measurements,
    }


def parse_fukuda_data(data: bytes) -> dict | None:
    if len(data) < 0x80:
        return None

    num_blocks = struct.unpack(">H", data[0x1E:0x20])[0]
    blocks = []
    offset = 0x70
    for b in range(min(num_blocks, 32)):
        entry_pos = 0x20 + b * 16
        if entry_pos + 8 > len(data):
            break
        tag = struct.unpack(">H", data[entry_pos : entry_pos + 2])[0]
        length = struct.unpack(">I", data[entry_pos + 4 : entry_pos + 8])[0]
        blocks.append((tag, offset, length))
        offset += length

    device_name = "Fukuda Denshi"
    idx_dev = data.find(b"FCP-")
    if idx_dev != -1:
        device_name = data[idx_dev : idx_dev + 16].split(b"\x00")[0].decode("ascii", errors="ignore")

    exam_date = ""
    for i in range(0x70, min(len(data) - 12, 0x200)):
        y_val = struct.unpack(">H", data[i : i + 2])[0]
        if 2005 <= y_val <= 2040:
            m, d, h, mi, s = struct.unpack(">5H", data[i + 2 : i + 12])
            if 1 <= m <= 12 and 1 <= d <= 31 and 0 <= h <= 23 and 0 <= mi <= 59 and 0 <= s <= 59:
                exam_date = f"{y_val:04d}/{m:02d}/{d:02d} {h:02d}:{mi:02d}:{s:02d}"
                break

    b3 = next((b for b in blocks if b[0] == 4), None)
    measurements = {}
    if b3 and b3[1] + 0x54 <= len(data):
        b3_offset = b3[1]
        p = b3_offset + 0x34
        hr, rr, pr, qrs, qt, qtc_b, qtc_f, axis = struct.unpack(">8h", data[p : p + 16])
        p2 = b3_offset + 0x44
        v_extra = struct.unpack(">8h", data[p2 : p2 + 16])
        qtc_f_val = v_extra[2] if v_extra[2] > 0 else qtc_f
        measurements = {
            "hr": hr,
            "rr_ms": rr,
            "rr_sec": round(rr / 1000.0, 3) if rr > 0 else 0,
            "pr_ms": pr,
            "pr_sec": round(pr / 1000.0, 3) if pr > 0 else 0,
            "qrs_ms": qrs,
            "qrs_sec": round(qrs / 1000.0, 3) if qrs > 0 else 0,
            "qt_ms": qt,
            "qt_sec": round(qt / 1000.0, 3) if qt > 0 else 0,
            "qtc_b": round(qtc_b / 1000.0, 3) if qtc_b > 0 else 0,
            "qtc_f": round(qtc_f_val / 1000.0, 3) if qtc_f_val > 0 else 0,
            "axis_deg": axis,
        }

    marker = b"\x00\x09\x02"
    mk = data.rfind(marker)
    if mk == -1 or mk + 44 > len(data):
        return None

    data_len = struct.unpack(">I", data[mk + 4 : mk + 8])[0]
    sample_interval_us = struct.unpack(">H", data[mk + 10 : mk + 12])[0]
    avm_nv = struct.unpack(">H", data[mk + 12 : mk + 14])[0]
    samples_per_lead = struct.unpack(">I", data[mk + 14 : mk + 18])[0]
    n_leads = struct.unpack(">H", data[mk + 20 : mk + 22])[0]
    start_bit = (mk + 44) * 8

    stream_data = data[: mk + data_len]
    br = BitReader(stream_data, start_bit)
    end_bit = (len(stream_data) // 2) * 16

    flat = []
    prev2 = 0
    prev1 = 0
    total = n_leads * samples_per_lead

    while br.pos < end_bit:
        node = HUFF_ROOT
        while node >= HUFF_ROOT:
            b = br.bit()
            node = HUFF_TREE[3 * node + b]
        if node < 0:
            break
        if node <= 10:
            delta = node - 5
        elif node == 11:
            raw_b = br.bits(8)
            delta = raw_b if raw_b < 128 else raw_b - 256
        else:
            raw_w = br.bits(16)
            delta = raw_w if raw_w < 32768 else raw_w - 65536
        x = 2 * prev1 - prev2 + delta
        prev2, prev1 = prev1, x
        flat.append(x)

    if len(flat) < total or n_leads <= 0:
        return None

    stride = len(flat) // n_leads
    lead_names_8 = ["I", "II", "V1", "V2", "V3", "V4", "V5", "V6"]
    m_leads = {}
    for i, name in enumerate(lead_names_8[:n_leads]):
        m_leads[name] = np.array(flat[i * stride : i * stride + samples_per_lead], dtype=np.float32)

    scale_to_mv = avm_nv / 1_000_000.0
    leads_12 = {
        "I": (m_leads["I"] * scale_to_mv).tolist(),
        "II": (m_leads["II"] * scale_to_mv).tolist(),
        "III": ((m_leads["II"] - m_leads["I"]) * scale_to_mv).tolist(),
        "aVR": ((-(m_leads["I"] + m_leads["II"]) / 2.0) * scale_to_mv).tolist(),
        "aVL": (((m_leads["I"] - m_leads["II"] / 2.0)) * scale_to_mv).tolist(),
        "aVF": (((m_leads["II"] - m_leads["I"] / 2.0)) * scale_to_mv).tolist(),
    }
    for v in ["V1", "V2", "V3", "V4", "V5", "V6"]:
        if v in m_leads:
            leads_12[v] = (m_leads[v] * scale_to_mv).tolist()

    return {
        "vendor": "FUKUDA",
        "device": device_name,
        "date": exam_date,
        "sample_rate_hz": 1_000_000 // sample_interval_us if sample_interval_us > 0 else 500,
        "duration_sec": round(samples_per_lead / (1_000_000 // sample_interval_us), 1) if sample_interval_us > 0 else 5.0,
        "total_samples_per_lead": samples_per_lead,
        "measurements": measurements,
        "leads": leads_12,
    }


def parse_ecg_file(path: str) -> dict | None:
    if not os.path.exists(path):
        return None
    ext = os.path.splitext(path)[1].lower().lstrip(".")
    with open(path, "rb") as f:
        data = f.read()
    if not data:
        return None

    # 1. MFER 標準波形 (.mwf または @MFER ヘッダ / Tag 0x40 'MFER')
    if ext == "mwf" or b"MFER" in data[:64] or data[0] in (0x01, 0x02, 0x40):
        res = parse_mfer_data(data)
        if res:
            return res

    # 2. 日本光電 旧形式 (.nkc / Magic 0xED 0x83)
    if ext == "nkc" or data[:2] == b"\xed\x83":
        return parse_nkc_metadata(data)

    # 3. フクダ電子 (.ECG / .001)
    return parse_fukuda_data(data)


def main():
    if len(sys.argv) < 2:
        print("Usage: ecg_render.py <path_to_ecg_file> [--json | --out <img_path>]")
        sys.exit(1)

    ecg_path = sys.argv[1]
    res = parse_ecg_file(ecg_path)
    if not res or "leads" not in res:
        sys.stderr.write("Failed to parse ECG waveform\n")
        sys.exit(2)

    if "--json" in sys.argv or len(sys.argv) == 2:
        print(json.dumps(res, ensure_ascii=False))
        sys.exit(0)


if __name__ == "__main__":
    main()
