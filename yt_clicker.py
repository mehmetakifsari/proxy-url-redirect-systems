#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import time
import signal
import atexit
import errno
from typing import List, Tuple, Optional, Dict
from urllib.parse import urlparse

# === YOLLAR & AYARLAR ===
BASE_DIR  = "/var/www/proje.amrdanismanlik.com/proje1/"
URL_FILE  = os.path.join(BASE_DIR, "url.txt")
PROXY_FILE = os.path.join(BASE_DIR, "proxy.txt")
LOG_FILE  = os.path.join(BASE_DIR, "yt_clicker.log")
PID_FILE  = "/tmp/yt_clicker.pid"
LOCK_FILE = "/tmp/yt_clicker.lock"

HEADLESS       = True
WAIT_BETWEEN   = 1.0
CLICK_SELECTOR = ".ytp-play-button.ytp-button"
UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/118 Safari/537.36")

PW_HOME = os.environ.get("PW_HOME") or os.path.join(BASE_DIR, ".pw")
os.makedirs(PW_HOME, exist_ok=True)
os.environ.setdefault("PLAYWRIGHT_BROWSERS_PATH", "0")
os.environ.setdefault("HOME", PW_HOME)

# ------------------------------ logging ------------------------------
def log_line(msg: str) -> None:
    ts = time.strftime("%Y-%m-%d %H:%M:%S")
    line = f"{ts} {msg}"
    try:
        print(line, flush=True)
    except Exception:
        pass
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except Exception:
        pass

def short_err_text(e: Exception, maxlen: int = 200) -> str:
    txt = str(e) if e else ""
    if len(txt) > maxlen:
        txt = txt[:maxlen] + "…"
    return f"{type(e).__name__}: {txt}" if e else ""

def log_error(prefix: str, e: Optional[Exception] = None) -> None:
    # Detay yok: tek satır sabit mesaj
    log_line(f"[ERROR] {prefix} Erişim hatası")

# ------------------------------ helpers ------------------------------
def should_skip_url(u: str) -> bool:
    """
    Yolu 'ip_logger.php' içeren URL'leri (domain fark etmeksizin) atla.
    Örn: example.com/ip_logger.php, https://sirtkoyu.org/ip_logger.php?x=1
    """
    s = (u or "").strip()
    if not s:
        return False
    to_parse = s if "://" in s else "http://" + s
    try:
        parsed = urlparse(to_parse)
        path = (parsed.path or "").lower()
        if "ip_logger.php" in path:
            return True
    except Exception:
        if "ip_logger.php" in s.lower():
            return True
    return False

def read_urls(path: str) -> List[Tuple[str, float]]:
    items: List[Tuple[str, float]] = []
    if not os.path.exists(path):
        return items
    with open(path, "r", encoding="utf-8", errors="ignore") as f:
        for raw in f:
            s = (raw or "").strip()
            if not s or s.startswith("#"):
                continue

            url, mins = s, 1.0
            if "," in s:
                a, b = s.split(",", 1)
                url = a.strip()
                try:
                    mins = float(b.strip().replace(",", "."))
                except Exception:
                    mins = 1.0
            else:
                url = s

            if should_skip_url(url):
                log_line(f"[SKIP] ip_logger.php içeren URL atlandı: {url}")
                continue

            items.append((url, max(0.0, mins)))
    return items

def parse_proxy_line(line: str) -> Optional[Dict[str, str]]:
    """
    Desteklenen örnekler:
      - 1.2.3.4:8080
      - http://1.2.3.4:8080
      - socks5://1.2.3.4:1080
      - user:pass@1.2.3.4:8080
      - http://user:pass@1.2.3.4:8080
    """
    s = line.strip()
    if not s or s.startswith("#"):
        return None
    if "://" not in s:
        s = "http://" + s  # varsayılan protokol
    try:
        proto, rest = s.split("://", 1)
        username = password = None
        if "@" in rest:
            creds, hostport = rest.split("@", 1)
            if ":" in creds:
                username, password = creds.split(":", 1)
            else:
                username = creds
        else:
            hostport = rest
        server = f"{proto}://{hostport}"
        out = {"server": server}
        if username:
            out["username"] = username
        if password:
            out["password"] = password
        return out
    except Exception:
        return None

def read_proxies(path: str) -> List[Dict[str, str]]:
    if not os.path.exists(path):
        return []
    proxies: List[Dict[str, str]] = []
    with open(path, "r", encoding="utf-8", errors="ignore") as f:
        for raw in f:
            p = parse_proxy_line(raw)
            if p:
                proxies.append(p)
    return proxies

def click_consent_if_any(page) -> None:
    try:
        selectors = [
            "button:has-text('Kabul et')",
            "button:has-text('Tümünü kabul et')",
            "button:has-text('Hepsini kabul et')",
            "button:has-text('Accept all')",
            "button:has-text('I agree')",
            "button:has-text('Reject all')",
            "form [role=button]:has-text('Accept')",
        ]
        for sel in selectors:
            btn = page.locator(sel)
            if btn.count() > 0:
                try:
                    b = btn.first
                    if b.is_visible():
                        b.click(timeout=2000)
                        log_line("    çerez bildirimi kapatıldı")
                        time.sleep(0.3)
                        break
                except Exception:
                    continue
    except Exception:
        pass

# ------------------------------- Playwright import --------------------------
try:
    from playwright.sync_api import sync_playwright, TimeoutError as PWTimeout  # noqa: E402
except Exception as e:
    try:
        log_error("Playwright import edilemedi", e)
    finally:
        raise

def try_play(page) -> bool:
    # 1) Görünür play butonu dene
    try:
        btn = page.wait_for_selector(CLICK_SELECTOR, timeout=6000, state="visible")
        btn.click()
        log_line("    ▶ play butonuna tıklandı")
        return True
    except PWTimeout:
        log_line("    [WARN] play tıklama/locator: zaman aşımı (6000 ms).")
    except Exception as e:
        # Uyarılarda kısa metin yeterli
        log_line(f"    [WARN] play tıklama/locator: {short_err_text(e)}")

    # 2) Video yüzeyine tıkla
    try:
        vid = page.query_selector("video.html5-main-video")
        if vid:
            vid.click()
            log_line("    ▶ video yüzeyine tıklandı")
            return True
    except Exception as e:
        log_line(f"    [WARN] video click: {short_err_text(e)}")

    # 3) Klavye k
    try:
        page.keyboard.press("k")
        log_line("    ▶ klavye 'k' gönderildi")
        return True
    except Exception as e:
        log_line(f"    [WARN] keyboard 'k': {short_err_text(e)}")

    return False

def interruptible_sleep(total_seconds: float, slice_seconds: float = 1.0) -> None:
    end = time.time() + max(0.0, total_seconds)
    while time.time() < end and not _stop:
        time.sleep(min(slice_seconds, max(0.0, end - time.time())))

# -------------------------- single-instance lock --------------------------
def _pid_running(pid: int) -> bool:
    try:
        if pid > 0:
            os.kill(pid, 0)
            return True
        return False
    except OSError:
        return False

def acquire_lock() -> bool:
    if os.path.exists(LOCK_FILE):
        try:
            with open(LOCK_FILE, "r") as f:
                other = int((f.read() or "0").strip() or "0")
            if other and _pid_running(other):
                log_line(f"[ERROR] Another yt_clicker instance is running (PID {other}). Exiting.")
                return False
        except Exception:
            pass
    try:
        with open(LOCK_FILE, "w") as f:
            f.write(str(os.getpid()))
        return True
    except OSError as e:
        log_error("lock oluşturulamadı", e)
        return False

def release_lock():
    try:
        if os.path.exists(LOCK_FILE):
            os.remove(LOCK_FILE)
    except Exception:
        pass

@atexit.register
def _cleanup():
    release_lock()

# ------------------------------- graceful stop ------------------------------
_stop = False
def _sig_handler(signum, frame):
    global _stop
    _stop = True
    log_line(f"[INFO] Sinyal alındı ({signum}), temiz kapanış…")

for _sig in (signal.SIGTERM, signal.SIGINT):
    try:
        signal.signal(_sig, _sig_handler)
    except Exception:
        pass

# ----------------------------------- main -----------------------------------
def launch_with_proxy(p, proxy_conf: Optional[Dict[str, str]]):
    kw = dict(headless=HEADLESS, args=[
        "--no-sandbox",
        "--disable-dev-shm-usage",
        "--mute-audio",
        "--autoplay-policy=no-user-gesture-required",
    ])
    if proxy_conf:
        kw["proxy"] = proxy_conf
    browser = p.chromium.launch(**kw)
    context = browser.new_context(
        user_agent=UA,
        viewport={"width": 1280, "height": 800},
        java_script_enabled=True,
    )
    page = context.new_page()
    return browser, context, page

def process_once(p, url: str, mins: float, proxy_conf: Optional[Dict[str, str]], u_idx: int, u_total: int, p_idx: int, p_total: int):
    """Tek deneme (belirli URL + belirli proxy)"""
    tag = f"[U{u_idx}/{u_total}][P{p_idx}/{p_total}]"
    if proxy_conf:
        log_line(f"=== {tag} OPEN {url} via {proxy_conf.get('server','?')} ===")
    else:
        log_line(f"=== {tag} OPEN {url} (direct) ===")

    from playwright.sync_api import TimeoutError as PWTimeout

    browser, context, page = launch_with_proxy(p, proxy_conf)
    try:
        try:
            page.goto(url, wait_until="domcontentloaded", timeout=60000)
        except PWTimeout:
            # Detaysız uyarı
            log_line("    [WARN] sayfa açılışı zaman aşımı (60s)")

        click_consent_if_any(page)
        ok = try_play(page)
        if not ok:
            log_line("    [WARN] oynatma başlatılamadı")

        wait_s = int(mins * 60)
        if wait_s > 0:
            log_line(f"    {wait_s} saniye bekleniyor…")
            interruptible_sleep(wait_s, 1.0)

        log_line(f"=== {tag} DONE {url} ===")

    except Exception as e:
        # Tek satır, sabit hata mesajı
        log_error(f"{tag} {url}", e)
    finally:
        try:
            context.close()
        except Exception as e:
            log_error("context.close()", e)
        try:
            browser.close()
        except Exception as e:
            log_error("browser.close()", e)

def main() -> None:
    if not acquire_lock():
        return

    urls = read_urls(URL_FILE)          # List[Tuple[url, mins]]
    proxies = read_proxies(PROXY_FILE)  # List[Dict]
    use_proxy = len(proxies) > 0

    if not urls:
        log_line("[ERROR] url.txt boş (veya tüm girdiler ip_logger.php nedeniyle atlandı)")
        return

    total_urls = len(urls)
    total_proxies = len(proxies) if use_proxy else 1

    log_line(f"[INFO] {total_urls} URL işlenecek.")
    if use_proxy:
        log_line(f"[INFO] {total_proxies} proxy bulundu; HER URL tüm proxy'lerle denenecek.")
    else:
        log_line("[INFO] proxy.txt boş; her URL doğrudan bağlantı ile 1 kez denenecek.")
    log_line(f"[INFO] PW_HOME={PW_HOME}  HEADLESS={HEADLESS}")

    with sync_playwright() as p:
        for u_idx, (url, mins) in enumerate(urls, start=1):
            if _stop:
                break

            if use_proxy:
                for p_idx, proxy_conf in enumerate(proxies, start=1):
                    if _stop:
                        break
                    process_once(p, url, mins, proxy_conf, u_idx, total_urls, p_idx, total_proxies)
                    interruptible_sleep(WAIT_BETWEEN, 0.2)
            else:
                process_once(p, url, mins, None, u_idx, total_urls, 1, 1)
                interruptible_sleep(WAIT_BETWEEN, 0.2)

if __name__ == "__main__":
    main()
