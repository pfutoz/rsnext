import socket
import struct
import time
import logging
import threading

logger = logging.getLogger("rsnext.snmp")

# ASN.1 / SNMP BER Tags
TAG_INTEGER = 0x02
TAG_OCTET_STR = 0x04
TAG_NULL = 0x05
TAG_OID = 0x06
TAG_SEQUENCE = 0x30
TAG_TIMETICKS = 0x43
TAG_GAUGE32 = 0x42
TAG_NOSUCHOBJECT = 0x80
TAG_END_OF_MIB = 0x82
PDU_GET_REQ = 0xA0
PDU_GETNEXT_REQ = 0xA1
PDU_GET_RESP = 0xA2


def _ber_len(length: int) -> bytes:
    if length < 0x80:
        return bytes([length])
    b = []
    n = length
    while n > 0:
        b.insert(0, n & 0xFF)
        n >>= 8
    return bytes([0x80 | len(b)] + b)


def _ber_tlv(tag: int, val: bytes) -> bytes:
    return bytes([tag]) + _ber_len(len(val)) + val


def ber_int(val: int, tag: int = TAG_INTEGER) -> bytes:
    v = int(val)
    if v == 0:
        return _ber_tlv(tag, b"\x00")
    out = []
    neg = v < 0
    if not neg:
        n = v
        while n > 0:
            out.insert(0, n & 0xFF)
            n >>= 8
        if out[0] & 0x80:
            out.insert(0, 0x00)
    else:
        bitlen = v.bit_length() + 1
        bytelen = (bitlen + 7) // 8
        n = (1 << (bytelen * 8)) + v
        for _ in range(bytelen):
            out.insert(0, n & 0xFF)
            n >>= 8
    return _ber_tlv(tag, bytes(out))


def ber_str(s: str | bytes) -> bytes:
    b = s.encode("utf-8", errors="replace") if isinstance(s, str) else bytes(s)
    return _ber_tlv(TAG_OCTET_STR, b)


def ber_null() -> bytes:
    return b"\x05\x00"


def oid_to_tuple(oid: str) -> tuple[int, ...]:
    return tuple(int(x) for x in oid.strip(".").split(".") if x)


def tuple_to_oid(t: tuple[int, ...]) -> str:
    return ".".join(str(x) for x in t)


def ber_oid(oid: str | tuple[int, ...]) -> bytes:
    parts = oid_to_tuple(oid) if isinstance(oid, str) else oid
    if len(parts) < 2:
        parts = (1, 3)
    body = [parts[0] * 40 + parts[1]]
    for sub in parts[2:]:
        if sub == 0:
            body.append(0)
        else:
            enc = []
            n = int(sub)
            while n > 0:
                enc.insert(0, (n & 0x7F) | (0x80 if enc else 0))
                n >>= 7
            body.extend(enc)
    return _ber_tlv(TAG_OID, bytes(body))


def _read_tlv(buf: bytes, pos: int = 0) -> tuple[int, bytes, int]:
    if pos >= len(buf):
        raise ValueError("Unexpected EOF in BER")
    tag = buf[pos]
    pos += 1
    if pos >= len(buf):
        raise ValueError("Missing BER length")
    l0 = buf[pos]
    pos += 1
    if l0 < 0x80:
        length = l0
    else:
        num_octets = l0 & 0x7F
        if pos + num_octets > len(buf):
            raise ValueError("Truncated BER length")
        length = 0
        for b in buf[pos : pos + num_octets]:
            length = (length << 8) | b
        pos += num_octets
    end = pos + length
    if end > len(buf):
        raise ValueError("Truncated BER value")
    return tag, buf[pos:end], end


def _decode_int(val: bytes) -> int:
    if not val:
        return 0
    return int.from_bytes(val, byteorder="big", signed=bool(val[0] & 0x80))


def _decode_oid(val: bytes) -> str:
    if not val:
        return ""
    first = val[0]
    first_a = min(2, first // 40)
    first_b = first - (first_a * 40)
    parts = [first_a, first_b]
    cur = 0
    for b in val[1:]:
        cur = (cur << 7) | (b & 0x7F)
        if not (b & 0x80):
            parts.append(cur)
            cur = 0
    return ".".join(str(x) for x in parts)


def _decode_any(tag: int, val: bytes):
    if tag in (TAG_INTEGER, TAG_TIMETICKS, TAG_GAUGE32, 0x41):
        return int.from_bytes(val, byteorder="big", signed=(tag == TAG_INTEGER and bool(val and (val[0] & 0x80))))
    if tag == TAG_OCTET_STR:
        for enc in ("utf-8", "cp932", "latin1"):
            try:
                return val.decode(enc).strip("\x00").strip()
            except Exception:
                continue
        return val.hex()
    if tag == TAG_OID:
        return _decode_oid(val)
    return None


def format_timeticks(ticks: int) -> str:
    sec = max(0, int(ticks) // 100)
    d, rem = divmod(sec, 86400)
    h, rem = divmod(rem, 3600)
    m, s = divmod(rem, 60)
    if d > 0:
        return f"{d}日 {h}時間 {m}分"
    return f"{h}時間 {m}分 {s}秒"


def snmp_get(host: str, oids: list[str], port: int = 161, community: str = "public", timeout: float = 1.5) -> dict:
    req_id = int(time.time() * 1000) & 0x7FFFFFFF
    vbs = b"".join(_ber_tlv(TAG_SEQUENCE, ber_oid(o) + ber_null()) for o in oids)
    vb_seq = _ber_tlv(TAG_SEQUENCE, vbs)
    pdu = _ber_tlv(PDU_GET_REQ, ber_int(req_id) + ber_int(0) + ber_int(0) + vb_seq)
    pkt = _ber_tlv(TAG_SEQUENCE, ber_int(1) + ber_str(community) + pdu)

    t0 = time.perf_counter()
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.settimeout(timeout)
    try:
        sock.sendto(pkt, (host, int(port)))
        resp, _ = sock.recvfrom(8192)
        rtt_ms = round((time.perf_counter() - t0) * 1000.0, 1)
    finally:
        sock.close()

    t_msg, msg_body, _ = _read_tlv(resp, 0)
    if t_msg != TAG_SEQUENCE:
        raise ValueError("Invalid SNMP packet")
    _, _, p = _read_tlv(msg_body, 0)  # version
    _, _, p = _read_tlv(msg_body, p)  # community
    t_pdu, pdu_body, _ = _read_tlv(msg_body, p)
    if t_pdu != PDU_GET_RESP:
        raise ValueError(f"Unexpected PDU tag: {hex(t_pdu)}")
    _, _, pp = _read_tlv(pdu_body, 0)  # req_id
    _, err_b, pp = _read_tlv(pdu_body, pp)
    _, _, pp = _read_tlv(pdu_body, pp)  # err_idx
    if _decode_int(err_b) != 0:
        raise ValueError(f"SNMP error status {_decode_int(err_b)}")
    _, vbl_body, _ = _read_tlv(pdu_body, pp)

    values = {}
    pos = 0
    while pos < len(vbl_body):
        _, vb_body, pos = _read_tlv(vbl_body, pos)
        _, oid_b, vp = _read_tlv(vb_body, 0)
        v_tag, v_val, _ = _read_tlv(vb_body, vp)
        values[_decode_oid(oid_b)] = _decode_any(v_tag, v_val)
    return {"rtt_ms": rtt_ms, "values": values}


def snmp_probe_device(host: str, port: int = 161, community: str = "public", timeout: float = 1.4) -> dict:
    oids = [
        "1.3.6.1.2.1.1.1.0",  # sysDescr
        "1.3.6.1.2.1.1.3.0",  # sysUpTime
        "1.3.6.1.2.1.1.5.0",  # sysName
        "1.3.6.1.2.1.1.6.0",  # sysLocation
    ]
    try:
        res = snmp_get(host, oids, port=port, community=community, timeout=timeout)
        vals = res["values"]
        ticks = int(vals.get("1.3.6.1.2.1.1.3.0") or 0)
        return {
            "snmp_ok": True,
            "rtt_ms": res["rtt_ms"],
            "sys_descr": str(vals.get("1.3.6.1.2.1.1.1.0") or ""),
            "sys_uptime_ticks": ticks,
            "sys_uptime_str": format_timeticks(ticks) if ticks else "",
            "sys_name": str(vals.get("1.3.6.1.2.1.1.5.0") or ""),
            "sys_location": str(vals.get("1.3.6.1.2.1.1.6.0") or ""),
            "error": "",
        }
    except Exception as e:
        return {
            "snmp_ok": False,
            "rtt_ms": None,
            "sys_descr": "",
            "sys_uptime_ticks": 0,
            "sys_uptime_str": "",
            "sys_name": "",
            "sys_location": "",
            "error": str(e),
        }


class SnmpV2cAgent:
    """
    軽量 Pure-Python SNMP v1/v2c エージェント (UDP 161)。
    外部SNMPマネージャ (Zabbix / PRTG / Synology / snmpwalk) からの GetRequest / GetNextRequest に応答する。
    """

    def __init__(self, get_mib_callback, bind_host: str = "0.0.0.0", port: int = 161, community: str = "public"):
        self.get_mib_callback = get_mib_callback
        self.bind_host = bind_host
        self.port = int(port)
        self.community = community
        self._sock: socket.socket | None = None
        self._stop_evt = threading.Event()
        self._thread: threading.Thread | None = None
        self.active = False
        self.last_error = ""
        self.requests_served = 0

    def start(self):
        if self._thread and self._thread.is_alive():
            return
        self._stop_evt.clear()
        try:
            s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            s.bind((self.bind_host, self.port))
            s.settimeout(1.0)
            self._sock = s
            self.active = True
            self.last_error = ""
            self._thread = threading.Thread(target=self._loop, name="SnmpV2cAgent", daemon=True)
            self._thread.start()
            logger.info("SNMP v2c Agent listening on %s:%d (community=%s)", self.bind_host, self.port, self.community)
        except Exception as e:
            self.active = False
            self.last_error = str(e)
            logger.warning("SNMP v2c Agent could not bind UDP %d: %s", self.port, e)

    def stop(self):
        self._stop_evt.set()
        self.active = False
        if self._sock:
            try:
                self._sock.close()
            except Exception:
                pass
            self._sock = None
        if self._thread and self._thread.is_alive():
            self._thread.join(timeout=2.5)

    def _encode_mib_val(self, kind: str, val) -> bytes:
        if kind == "str":
            return ber_str(str(val))
        if kind == "oid":
            return ber_oid(str(val))
        if kind == "ticks":
            return ber_int(max(0, int(val)), tag=TAG_TIMETICKS)
        return ber_int(int(val), tag=TAG_INTEGER)

    def _handle_packet(self, data: bytes) -> bytes | None:
        t_msg, msg_body, _ = _read_tlv(data, 0)
        if t_msg != TAG_SEQUENCE:
            return None
        _, ver_b, p = _read_tlv(msg_body, 0)
        version = _decode_int(ver_b)  # 0=v1, 1=v2c
        if version not in (0, 1):
            return None
        _, comm_b, p = _read_tlv(msg_body, p)
        comm = comm_b.decode("utf-8", errors="ignore")
        if comm != self.community:
            return None

        t_pdu, pdu_body, _ = _read_tlv(msg_body, p)
        if t_pdu not in (PDU_GET_REQ, PDU_GETNEXT_REQ):
            return None

        _, req_id_b, pp = _read_tlv(pdu_body, 0)
        req_id = _decode_int(req_id_b)
        _, _, pp = _read_tlv(pdu_body, pp)
        _, _, pp = _read_tlv(pdu_body, pp)
        _, vbl_body, _ = _read_tlv(pdu_body, pp)

        mib_entries = self.get_mib_callback()  # dict[oid_str, (kind, val)]
        sorted_keys = sorted((oid_to_tuple(k), k) for k in mib_entries.keys())

        resp_vbs = []
        pos = 0
        while pos < len(vbl_body):
            _, vb_body, pos = _read_tlv(vbl_body, pos)
            _, oid_b, _ = _read_tlv(vb_body, 0)
            req_oid_str = _decode_oid(oid_b)
            req_oid_tup = oid_to_tuple(req_oid_str)

            if t_pdu == PDU_GET_REQ:
                if req_oid_str in mib_entries:
                    kind, val = mib_entries[req_oid_str]
                    resp_vbs.append(_ber_tlv(TAG_SEQUENCE, ber_oid(req_oid_str) + self._encode_mib_val(kind, val)))
                else:
                    resp_vbs.append(_ber_tlv(TAG_SEQUENCE, ber_oid(req_oid_str) + _ber_tlv(TAG_NOSUCHOBJECT, b"")))
            else:
                next_hit = None
                for ktup, kstr in sorted_keys:
                    if ktup > req_oid_tup:
                        next_hit = kstr
                        break
                if next_hit:
                    kind, val = mib_entries[next_hit]
                    resp_vbs.append(_ber_tlv(TAG_SEQUENCE, ber_oid(next_hit) + self._encode_mib_val(kind, val)))
                else:
                    resp_vbs.append(_ber_tlv(TAG_SEQUENCE, ber_oid(req_oid_str) + _ber_tlv(TAG_END_OF_MIB, b"")))

        out_vbl = _ber_tlv(TAG_SEQUENCE, b"".join(resp_vbs))
        out_pdu = _ber_tlv(PDU_GET_RESP, ber_int(req_id) + ber_int(0) + ber_int(0) + out_vbl)
        return _ber_tlv(TAG_SEQUENCE, ber_int(version) + ber_str(comm) + out_pdu)

    def _loop(self):
        while not self._stop_evt.is_set() and self._sock:
            try:
                data, addr = self._sock.recvfrom(4096)
            except socket.timeout:
                continue
            except OSError:
                break
            try:
                reply = self._handle_packet(data)
                if reply and self._sock:
                    self._sock.sendto(reply, addr)
                    self.requests_served += 1
            except Exception as e:
                logger.debug("SNMP packet ignore: %s", e)

