#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import time
import signal
import traceback
import atexit
import errno
from typing import List, Tuple

# === YOLLAR & AYARLAR ===
BASE_DIR  = "/var/www/proje.amrdanismanlik.com/proje1/"
URL_FILE  = os.path.join(BASE_DIR, "url.txt")
LOG_FILE  = os.path.join(BASE_DIR, "yt_clicker.log")
PID_FILE  = "/tmp/yt_clicker.pid"    # run_bot.php bunu yazar; biz de tek-instans için ayrı lock tutacağız
LOCK_FILE = "/tmp/yt_clicker.lock"   # double-run engelleme

HEADLESS       = True
WAIT_BETWEEN   = 1.0
CLICK_SELECTOR = ".ytp-play-button.ytp-button"  # YouTube player play/pause
UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/118 Safari/537.36")

# Playwright cache/ev dizini: run_bot.php PW_HOME verirse onu kullan
PW_HOME = os.environ.get("PW_HOME") or os.path.join(BASE_DIR, ".pw")
os.makedirs(PW_HOME, exist_ok=True)
os.environ.setdefault("PLAYWRIGHT_BROWSERS_PATH", "0")  # projeye indirilen tarayıcıyı kullan
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

# ------------------------------ helpers ------------------------------
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
            items.append((url, max(0.0, mins)))
    return items

def click_consent_if_any(page) -> None:
    """YouTube/GDPR çerez pencerelerini kapatmaya çalış."""
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

def try_play(page) -> bool:
    """Play butonuna bas; olmazsa alternatifleri dene. Başardıysa True döner."""
    # 1) Standart play tuşu
    try:
        btn = page.wait_for_selector(CLICK_SELECTOR, timeout=6000)
        btn.click()
        log_line("    ▶ play butonuna tıklandı")
        return True
    except Exception as e:
        log_line(f"    [WARN] play tıklama/locator: {e}")

    # 2) Video yüzeyine tıkla
    try:
        vid = page.query_selector("video.html5-main-video")
        if vid:
            vid.click()
            log_line("    ▶ video yüzeyine tıklandı")
            return True
    except Exception:
        pass

    # 3) Klavye kısayolu (k = play/pause)
    try:
        page.keyboard.press("k")
        log_line("    ▶ klavye 'k' gönderildi")
        return True
    except Exception:
        pass

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
    # Başka instans var mı?
    if os.path.exists(LOCK_FILE):
        try:
            with open(LOCK_FILE, "r") as f:
                other = int((f.read() or "0").strip() or "0")
            if other and _pid_running(other):
                log_line(f"[ERROR] Another yt_clicker instance is running (PID {other}). Exiting.")
                return False
        except Exception:
            pass
    # Lock yaz
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

# ------------------------------- Playwright import --------------------------
try:
    from playwright.sync_api import sync_playwright, TimeoutError as PWTimeout  # noqa: E402
except Exception as e:
    ts = time.strftime("%Y-%m-%d %H:%M:%S")
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as f:
            f.write(f"{ts} [ERROR] Playwright import edilemedi: {e}\n")
    finally:
        raise

# ----------------------------------- main -----------------------------------
def main() -> None:
    if not acquire_lock():
        return

    urls = read_urls(URL_FILE)
    if not urls:
        log_line("[ERROR] url.txt boş")
        return

    log_line(f"[INFO] {len(urls)} URL işlenecek.")
    log_line(f"[INFO] PW_HOME={PW_HOME}  HEADLESS={HEADLESS}")

    with sync_playwright() as p:
        browser = p.chromium.launch(
            headless=HEADLESS,
            args=[
                "--no-sandbox",
                "--disable-dev-shm-usage",
                "--mute-audio",  # güvenli sunucu tarafı
                "--autoplay-policy=no-user-gesture-required",
            ],
        )
        context = browser.new_context(
            user_agent=UA,
            viewport={"width": 1280, "height": 800},
            java_script_enabled=True,
        )
        page = context.new_page()

        for idx, (url, mins) in enumerate(urls, start=1):
            if _stop:
                break
            try:
                log_line(f"=== [{idx}] OPEN {url} ===")
                page.goto(url, wait_until="domcontentloaded", timeout=60000)

                click_consent_if_any(page)

                ok = try_play(page)
                if not ok:
                    log_line("    [WARN] oynatma başlatılamadı")

                wait_s = int(mins * 60)
                if wait_s > 0:
                    log_line(f"    {wait_s} saniye bekleniyor…")
                    interruptible_sleep(wait_s, 1.0)

                log_line(f"=== [{idx}] DONE {url} ===")

            except Exception as e:
                tb = traceback.format_exc(limit=2)
                log_line(f"[ERROR] {url}: {e}\n{tb}")

            interruptible_sleep(WAIT_BETWEEN, 0.2)

        try:
            context.close()
        except Exception:
            pass
        try:
            browser.close()
        except Exception:
            pass

if __name__ == "__main__":
    main()
