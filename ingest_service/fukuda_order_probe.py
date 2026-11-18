import os
import sys
import json
import socket
import struct
import threading
from datetime import datetime

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from ingest_service.common import load_config, get_db_conn, log_ingest_event

TMP_DIR = os.path.join(BASE_DIR, "tmp")
os.makedirs(TMP_DIR, exist_ok=True)
JSONL_PATH = os.path.join(TMP_DIR, "fukuda_order_log.jsonl")

_seq = 0
_lock = threading.Lock()


def handle_order_connection(conn: socket.socket, addr):
    global _seq
    with _lock:
        _seq += 1
        seq_id = _seq

    ip, rport = addr[0], addr[1]
    chunks = []
    conn.settimeout(1.8)
    try:
        while True:
            try:
                data = conn.recv(65536)
                if not data:
                    break
                chunks.append(data)
                joined = b"".join(chunks)
                if len(joined) >= 4:
                    decl_len = struct.unpack(">I", joined[:4])[0]
                    if len(joined) == decl_len or len(joined) == decl_len + 4:
                        conn.settimeout(0.3)
            except socket.timeout:
                break
    except Exception:
        pass

    payload = b"".join(chunks)
    if not payload and ip == "127.0.0.1":
        try:
            conn.close()
        except Exception:
            pass
        return

    bin_file = os.path.join(TMP_DIR, f"fukuda_order_req_{seq_id:03d}.bin")
    with open(bin_file, "wb") as bf:
        bf.write(payload)

    cp932_preview = "".join(
        ch if (ch.isprintable() or ch in "\r\n\t") else "."
        for ch in payload.decode("cp932", errors="replace")
    )
    rec = {
        "seq": seq_id,
        "timestamp": datetime.now().astimezone().isoformat(),
        "remote_ip": ip,
        "remote_port": rport,
        "length": len(payload),
        "hex": payload.hex(),
        "cp932_preview": cp932_preview[:500],
    }
    with _lock:
        with open(JSONL_PATH, "a", encoding="utf-8") as jf:
            jf.write(json.dumps(rec, ensure_ascii=False) + "\n")

    try:
        cfg = load_config()
        dbc = get_db_conn(cfg)
        try:
            log_ingest_event(
                dbc,
                "INFO",
                "FUKUDA_ORDER",
                "ORDER_REQ_LOGGED",
                f"心電計 ({ip}) からオーダリング要求を受信: {len(payload)} bytes [#{seq_id}]",
                detail={"hex_head": payload[:160].hex(), "preview": cp932_preview[:200]},
            )
            dbc.commit()
        finally:
            dbc.close()
    except Exception:
        pass

    try:
        conn.close()
    except Exception:
        pass


def main():
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 60099
    srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind(("0.0.0.0", port))
    srv.listen(10)
    with open(JSONL_PATH, "a", encoding="utf-8") as jf:
        jf.write(json.dumps({"event": "READY", "port": port, "pid": os.getpid(), "at": datetime.now().astimezone().isoformat()}) + "\n")
    while True:
        c, a = srv.accept()
        threading.Thread(target=handle_order_connection, args=(c, a), daemon=True).start()


if __name__ == "__main__":
    main()
