#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import json
import time
import signal
import atexit
import errno
from urllib.parse import urlparse

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

# === SETTINGS ===
BASE_DIR    = "/var/www/proje.amrdanismanlik.com/proje1/"
URL_FILE    = os.path.join(BASE_DIR, "url.txt")
PROXY_FILE  = os.path.join(BASE_DIR, "proxy.txt")
BAD_FILE    = os.path.join(BASE_DIR, "hatali_proxy.txt")           # temporary bad proxies
DEF_BAD     = os.path.join(BASE_DIR, "kesin_hatali_proxy.txt")     # 5+
BAD_COUNTS  = os.path.join(BASE_DIR, "bad_counts.json")            # { "ip:port": int }
LOG_FILE    = os.path.join(BASE_DIR, "bot.log")

PID_FILE    = "/tmp/proxy_bot.pid"                                 # run_bot.php ile uyumlu
LOCK_FILE   = "/tmp/proxy_bot.lock"

BEACON_HOST = "sirtkoyu.org"
BEACON_URL  = f"https://{BEACON_HOST}/ip_log_beacon.php"

SESSION_UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
              "(KHTML, like Gecko) Chrome/118 Safari/537.36")

GET_TIMEOUT   = 20
POST_TIMEOUT  = 20
PAUSE_BETWEEN = 0.5
FAIL_LIMIT    = 5   # 5 or more: definitely bad
SLEEP_SLICE   = 1.0 # interruptible wait

_stop = False
def _sig_handler(signum, frame):
    # işaretle ve logla
    global _stop
    _stop = True
    log_line(f"[INFO] Signal received ({signum}), graceful shutdown requested.")

# sinyalleri bağla
for _sig in (signal.SIGTERM, signal.SIGINT):
    try:
        signal.signal(_sig, _sig_handler)
    except Exception:
        pass

# ------------- logging -------------
def log_line(msg: str):
    ts = time.strftime("%Y-%m-%d %H:%M:%S")
    line = f"{ts} {msg}\n"
    try:
        print(line, end="", flush=True)
    except Exception:
        pass
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as f:
            f.write(line)
    except Exception:
        pass

def log_route(url: str, ip: str):
    # single-line, easy to parse in UI
    log_line(f"[ROUTE] URL {url} -> IP {ip}")

# ------------- helpers -------------
def read_lines(path):
    items = []
    if not os.path.exists(path):
        return items
    with open(path, "r", encoding="utf-8", errors="ignore") as f:
        for raw in f:
            line = (raw or "").strip()
            if not line or line.startswith("#"):
                continue
            items.append(line)
    return items

def write_lines(path, lines):
    tmp = f"{path}.tmp-{int(time.time()*1000)}"
    with open(tmp, "w", encoding="utf-8") as f:
        for line in lines:
            f.write((line or "").strip() + "\n")
    os.replace(tmp, path)

def append_unique(path, line):
    line = (line or "").strip()
    if not line:
        return
    exist = set(read_lines(path))
    if line not in exist:
        with open(path, "a", encoding="utf-8") as f:
            f.write(line + "\n")

def load_counts(path):
    if not os.path.exists(path):
        return {}
    try:
        with open(path, "r", encoding="utf-8") as f:
            return json.load(f) or {}
    except Exception:
        return {}

def save_counts(path, data):
    tmp = f"{path}.tmp-{int(time.time()*1000)}"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    os.replace(tmp, path)

def parse_url_and_minutes(line):
    url = line
    mins = 1.0
    if "," in line:
        a, b = line.split(",", 1)
        url = a.strip()
        try:
            mins = float(b.strip().replace(",", "."))
        except Exception:
            mins = 1.0
    return url, max(0.0, mins)

def make_proxy_map(p):
    p = (p or "").strip()
    if "://" in p:
        return {"http": p, "https": p}
    return {"http": "http://" + p, "https": "http://" + p}

def extract_ip_from_proxy(p: str) -> str:
    """
    Accepts formats:
      1.2.3.4:8080
      http://1.2.3.4:8080
      http://user:pass@1.2.3.4:8080
      https://1.2.3.4:8080
    Returns only host/IP part (without port).
    """
    s = (p or "").strip()
    if "://" not in s:
        s = "http://" + s
    try:
        u = urlparse(s)
        host = (u.hostname or "").strip()
        return host
    except Exception:
        # last resort: split by @ then by :
        tail = s.split("@")[-1]
        return tail.replace("http://", "").replace("https://", "").split(":")[0].strip()

def new_session() -> requests.Session:
    sess = requests.Session()
    sess.headers.update({"User-Agent": SESSION_UA})
    # hafif retry: bağlantı hataları + 429/5xx için
    retry = Retry(
        total=2,
        connect=2,
        read=2,
        backoff_factor=0.5,
        status_forcelist=(429, 500, 502, 503, 504),
        allowed_methods=frozenset(["HEAD", "GET", "POST"])
    )
    adapter = HTTPAdapter(max_retries=retry, pool_connections=50, pool_maxsize=50)
    sess.mount("http://", adapter)
    sess.mount("https://", adapter)
    return sess

# ------------- single instance lock -------------
def acquire_lock():
    if os.path.exists(LOCK_FILE):
        # stale check: pid yazıyorsa ve çalışıyorsa çık
        try:
            with open(LOCK_FILE, "r") as f:
                pid = int((f.read() or "0").strip() or "0")
            if pid > 0 and _pid_running(pid):
                log_line(f"[ERROR] Another proxy_bot instance is running (PID {pid}). Exiting.")
                return False
        except Exception:
            pass
    try:
        with open(LOCK_FILE, "w") as f:
            f.write(str(os.getpid()))
        return True
    except OSError as e:
        if e.errno != errno.EEXIST:
            log_line(f"[ERROR] cannot create lock: {e}")
        return False

def release_lock():
    try:
        if os.path.exists(LOCK_FILE):
            os.remove(LOCK_FILE)
    except Exception:
        pass

def _pid_running(pid: int) -> bool:
    try:
        os.kill(pid, 0)
        return True
    except OSError:
        return False

@atexit.register
def _cleanup_at_exit():
    release_lock()

# ------------- main flow -------------
def main():
    if not acquire_lock():
        return

    urls        = read_lines(URL_FILE)
    proxies_in  = read_lines(PROXY_FILE)
    def_bads    = set(read_lines(DEF_BAD))         # definitely bad: never test
    counts      = load_counts(BAD_COUNTS)

    if not urls:
        log_line(f"[ERROR] URL list is empty: {URL_FILE}")
        return
    if not proxies_in:
        log_line(f"[ERROR] Proxy list is empty: {PROXY_FILE}")
        return

    urls_with_minutes = [parse_url_and_minutes(ln) for ln in urls]
    log_line(f"[INFO] {len(proxies_in)} proxies will be tested with {len(urls_with_minutes)} URLs.")

    good_proxies    = []
    new_bad_proxies = []

    # Çalışma listesi kopyası (kesintide kalanların korunması için)
    remaining = list(proxies_in)

    try:
        for idx, p in enumerate(proxies_in, start=1):
            if _stop:
                break

            if p in def_bads:
                log_line(f"[SKIPPED] {p} is in definitely-bad list (>= {FAIL_LIMIT}).")
                remaining.pop(0)
                continue

            proxy_map = make_proxy_map(p)
            log_line(f"=== PROXY #{idx}: {proxy_map['http']} ===")

            sess = new_session()
            proxy_failed = False
            proxy_ip = extract_ip_from_proxy(p)

            for u_idx, (url, minutes) in enumerate(urls_with_minutes, start=1):
                if _stop:
                    break

                # ROUTE note (before request)
                log_route(url, proxy_ip)

                # START
                try:
                    r = sess.get(url, proxies=proxy_map, timeout=GET_TIMEOUT, allow_redirects=True)
                    size = len(r.content) if r.content is not None else 0
                    log_line(f"  [{u_idx}] START {url} -> {r.status_code} ({size} bytes)")
                except Exception as e:
                    log_line(f"  [{u_idx}] START {url} -> ERROR: {e}")
                    proxy_failed = True
                    break

                # Wait for requested duration (interruptible)
                wait_s = int(minutes * 60)
                t_end = time.time() + wait_s
                while time.time() < t_end:
                    if _stop:
                        break
                    time.sleep(min(SLEEP_SLICE, max(0.0, t_end - time.time())))

                # Only for sirtkoyu.org beacon
                host = (urlparse(url).hostname or "").lower()
                if host == BEACON_HOST and not _stop:
                    try:
                        path_only = urlparse(url).path or "/"
                        data = {"sid": "bot", "duration_ms": str(wait_s * 1000), "path": path_only}
                        r2 = sess.post(BEACON_URL, data=data, proxies=proxy_map, timeout=POST_TIMEOUT)
                        log_line(f"  [{u_idx}] END   {BEACON_URL} -> {r2.status_code}")
                    except Exception as e:
                        log_line(f"  [{u_idx}] END   {BEACON_URL} -> ERROR: {e}")
                        proxy_failed = True
                        break

                # kısa ara (interruptible)
                t_end = time.time() + PAUSE_BETWEEN
                while time.time() < t_end:
                    if _stop:
                        break
                    time.sleep(0.1)

            try:
                sess.close()
            except Exception:
                pass

            # sonuç
            if proxy_failed:
                new_count = int(counts.get(p, 0)) + 1
                counts[p] = new_count
                log_line(f"[WARN] Proxy failed: {p} (fail count: {new_count})")
                if new_count >= FAIL_LIMIT:
                    append_unique(DEF_BAD, p)   # definitely bad
                else:
                    append_unique(BAD_FILE, p)  # temporary bad
                new_bad_proxies.append(p)
            else:
                # Tamamı başarıyla dolaştıysa iyi listesine ekle
                if not _stop:
                    good_proxies.append(p)

            # İşlenen proxy’yi remaining’den düş
            if remaining and remaining[0] == p:
                remaining.pop(0)

            if _stop:
                break

    finally:
        # sayacı yaz
        save_counts(BAD_COUNTS, counts)

        # Kesinti durumunda veya normalde:
        # - iyi proxy’ler + test edilmemiş (remaining) proxy’ler birlikte yazılır.
        # Böylece panelde IP listesi "kaybolmaz".
        final_list = good_proxies + remaining
        write_lines(PROXY_FILE, final_list)

        if new_bad_proxies:
            log_line(f"[INFO] {len(new_bad_proxies)} bad proxies detected.")
        log_line(f"[INFO] proxy.txt updated. Remaining {len(final_list)} proxies.")

        release_lock()

if __name__ == "__main__":
    main()
