<?php
declare(strict_types=1);
ini_set('default_charset','UTF-8');
header('Content-Type: text/html; charset=UTF-8');
session_start();

if (empty($_SESSION['auth'])) { header('Location: login.php'); exit; }

$BASE       = '/home/proje/public_html/proje1';
$PROXY      = $BASE . '/proxy.txt';
$BAD        = $BASE . '/hatali_proxy.txt';
$DEF_BAD    = $BASE . '/kesin_hatali_proxy.txt';
$COUNTS     = $BASE . '/bad_counts.json';
$URLS       = $BASE . '/url.txt';
$LOG        = $BASE . '/bot.log';
$FAIL_LIMIT = 5;

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$LOG_TOKEN = md5($csrf); // log_view.php erişim anahtarı

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function read_lines(string $p): array {
  if (!is_file($p)) return [];
  $out=[];
  foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln){
    $ln=trim($ln);
    if ($ln==='' || $ln[0]==='#') continue;
    $out[$ln]=true;
  }
  return array_keys($out);
}
function write_lines(string $p, array $arr): void {
  $arr=array_values(array_unique(array_map('trim',$arr)));
  $tmp=$p.'.tmp-'.uniqid('',true);
  file_put_contents($tmp, implode("\n", array_filter($arr))."\n");
  rename($tmp,$p);
}
function load_counts(string $p): array {
  if (!is_file($p)) return [];
  $raw = file_get_contents($p);
  $j = json_decode($raw,true);
  return is_array($j) ? $j : [];
}

/**
 * Listeler arası çakışmaları temizler ve gerekirse dosyaları günceller.
 * Öncelik: DEF_BAD > BAD > PROXY
 * - DEF_BAD’da olan IP, BAD ve PROXY’den çıkarılır.
 * - BAD’de olan IP, PROXY’den çıkarılır.
 * Dönen dizi: ['proxy'=>[], 'bad'=>[], 'def'=>[]]
 */
function reconcile_and_persist(string $proxyFile, string $badFile, string $defFile): array {
  $proxy = read_lines($proxyFile);
  $bad   = read_lines($badFile);
  $def   = read_lines($defFile);

  $proxy0 = $proxy; $bad0 = $bad; $def0 = $def;

  // def'ler bad ve proxy'den silinir
  if ($def) {
    $bad   = array_values(array_diff($bad,   $def));
    $proxy = array_values(array_diff($proxy, $def));
  }
  // bad'de olanlar proxy'den silinir
  if ($bad) {
    $proxy = array_values(array_diff($proxy, $bad));
  }

  // Sadece değişmişse yaz
  if ($proxy !== $proxy0) write_lines($proxyFile, $proxy);
  if ($bad   !== $bad0)   write_lines($badFile,   $bad);
  // $def değişmez (yazmaya gerek yok)

  return ['proxy'=>$proxy,'bad'=>$bad,'def'=>$def];
}

$msg = []; $err = [];

// --- POST işlemleri
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['csrf'] ?? '') === $csrf)) {
  $action = $_POST['action'] ?? '';

  if ($action === 'save_proxy') {
    $text = (string)($_POST['proxy_text'] ?? '');
    $lines = array_filter(array_map('trim', preg_split('/\R+/', $text)));
    write_lines($PROXY, $lines);

    // Kaydettikten sonra da çakışmaları temizle
    reconcile_and_persist($PROXY,$BAD,$DEF_BAD);
    $msg[] = "proxy.txt kaydedildi (".count(read_lines($PROXY))." satır).";
  }
  elseif ($action === 'save_urls') {
    $text = (string)($_POST['url_text'] ?? '');
    $lines = array_filter(array_map('trim', preg_split('/\R+/', $text)));
    write_lines($URLS, $lines);
    $msg[] = "url.txt kaydedildi (".count($lines)." satır).";
  }
  elseif ($action === 'restore_selected') {
    $sel = $_POST['sel'] ?? [];
    $sel = is_array($sel)? $sel : [];

    // Mevcut listeleri oku
    $bad   = read_lines($BAD);
    $def   = read_lines($DEF_BAD);
    $proxy = read_lines($PROXY);

    // Kesin hatalı olanlar hiçbir şekilde geri dönmez
    $eligible = array_values(array_diff($sel, $def));

    // Aktife ekle (unique), BAD'den çıkar
    if ($eligible) {
      $proxy = array_values(array_unique(array_merge($proxy, $eligible)));
      write_lines($PROXY, $proxy);

      $new_bad = array_values(array_diff($bad, $eligible));
      write_lines($BAD, $new_bad);
    }

    // Son kez çakışmaları temizle
    $after = reconcile_and_persist($PROXY,$BAD,$DEF_BAD);
    $msg[] = count($eligible)." proxy geri aktarıldı.";
  }
  elseif ($action === 'restore_all_eligible') {
    $bad    = read_lines($BAD);
    $def    = read_lines($DEF_BAD);
    $counts = load_counts($COUNTS);
    $proxy  = read_lines($PROXY);

    $eligible = [];
    foreach ($bad as $p){
      $c = (int)($counts[$p] ?? 0);
      if ($c < $FAIL_LIMIT && !in_array($p,$def,true)){
        $eligible[] = $p;
      }
    }
    if ($eligible){
      $proxy = array_values(array_unique(array_merge($proxy, $eligible)));
      write_lines($PROXY, $proxy);

      $remaining = array_values(array_diff($bad, $eligible));
      write_lines($BAD, $remaining);

      // Çakışmaları temizle
      reconcile_and_persist($PROXY,$BAD,$DEF_BAD);
      $msg[] = count($eligible)." proxy geri aktarıldı.";
    } else {
      $msg[] = "Geri aktarılacak uygun proxy yok.";
    }
  }
} elseif ($_SERVER['REQUEST_METHOD']==='POST') {
  $err[] = 'Oturum/CSRF doğrulaması başarısız. Sayfayı yenileyin.';
}

// --- Render'dan önce mutlaka senkronize et ---
$lists = reconcile_and_persist($PROXY,$BAD,$DEF_BAD);

$proxy_lines  = $lists['proxy'];
$bad_lines    = $lists['bad'];
$def_lines    = $lists['def'];
$counts       = load_counts($COUNTS);
$url_lines    = read_lines($URLS);
$log_size     = is_file($LOG) ? filesize($LOG) : 0;
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Proxy Yönetim Paneli (AJAX)</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:1200px;margin:24px auto;padding:0 12px}
  h2{margin:8px 0 12px}
  h3{margin:0 0 8px}
  textarea{width:100%;min-height:160px;font-family:ui-monospace,Menlo,Consolas,monospace}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .row3{display:grid;grid-template-columns:1fr;gap:16px}
  .card{border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff}
  table{width:100%;border-collapse:collapse}
  th,td{border-bottom:1px solid #eee;padding:8px;text-align:left}
  th{background:#f9fafb}
  .muted{color:#6b7280}
  .btn{padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#f9fafb;cursor:pointer;text-decoration:none;color:#111827}
  .btn.primary{background:#2563eb;color:#fff;border-color:#2563eb}
  .btn.danger{background:#ef4444;color:#fff;border-color:#ef4444}
  .btn.green{background:#10b981;color:#fff;border-color:#10b981}
  .msg{padding:8px 10px;border-radius:8px;margin:6px 0}
  .ok{background:#dcfce7;color:#065f46}.bad{background:#fee2e2;color:#991b1b}
  .pill{display:inline-block;background:#f3f4f6;border-radius:999px;padding:4px 10px;font-size:12px}
  #logbox{background:#0b1020;color:#c7ffe0;border:1px solid #132347;border-radius:12px;padding:10px;height:360px;overflow:auto;font-family:ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap}
  .flex{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .topbar{position:sticky; top:0; z-index:10; display:flex; gap:10px; align-items:center; justify-content:flex-end; background:#111827; color:#fff; padding:8px 12px; border-radius:12px; box-shadow:0 6px 18px rgba(0,0,0,.15); margin-bottom:12px}
  .topbar .pill{background:#0f172a;color:#a7f3d0}
  .topbar .btn{background:#0b1222;color:#fff;border-color:#334155}
  .topbar select{padding:6px 8px;border:1px solid #334155;border-radius:8px;background:#0b1222;color:#fff}
  .topbar label{display:flex;align-items:center;gap:6px;font-size:14px}
</style>
</head>
<body>
  <h2>Proxy Yönetim Paneli (AJAX)</h2>

  <div class="topbar">
    <span>Oto yenile:</span>
    <select id="refreshSelect" title="Yenileme aralığı (saniye)">
      <option value="5">5 sn</option>
      <option value="10" selected>10 sn</option>
      <option value="15">15 sn</option>
      <option value="30">30 sn</option>
    </select>
    <span class="pill" style="padding:4px 10px;border-radius:999px">
      <span id="countdown">10</span> sn
    </span>
    <label title="Açıkken log + proxy listeleri yenilenir; kapalıyken tam sayfa yenilenir.">
      <input type="checkbox" id="softMode" checked> Sadece log & proxy listesini yenile
    </label>
    <button class="btn" id="btnDoRefresh" type="button">Şimdi Yenile</button>
  </div>

  <?php foreach($msg as $m) echo '<div class="msg ok">✅ '.h($m).'</div>'; ?>
  <?php foreach($err as $e) echo '<div class="msg bad">⚠️ '.h($e).'</div>'; ?>

  <div class="row3">
    <div class="card">
      <div class="flex" style="justify-content:space-between">
        <h3>Bot Kontrol</h3>
        <div class="muted">Log boyutu: <?=number_format((float)$log_size)?> bayt</div>
      </div>
      <div class="flex" style="margin-bottom:8px">
        <button class="btn green"  id="btnStart">▶ Başlat</button>
        <button class="btn danger" id="btnStop">■ Durdur</button>
        <button class="btn"        id="btnClearLog">Log Temizle</button>
        <button class="btn"        id="btnRefreshLog">Log Yenile</button>
        <span class="muted" id="statusText">hazır</span>
      </div>
      <div id="logbox">(log burada akacak)</div>
    </div>
  </div>

  <!-- 1. SATIR: Aktif Proxy (sol) + URL (sağ) -->
  <div class="row" style="margin-top:16px">
    <div id="activeCard" class="card">
      <h3>Aktif Proxy Listesi (<span class="muted"><?=count($proxy_lines)?> satır</span>)</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <textarea name="proxy_text" spellcheck="false"><?=h(implode("\n",$proxy_lines))?></textarea>
        <div style="margin-top:8px" class="flex">
          <button class="btn primary" name="action" value="save_proxy">Kaydet</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h3>URL Listesi (<span class="muted"><?=count($url_lines)?> satır</span>)</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <textarea name="url_text" spellcheck="false"><?=h(implode("\n",$url_lines))?></textarea>
        <div style="margin-top:8px" class="flex">
          <button class="btn primary" name="action" value="save_urls">Kaydet</button>
        </div>
      </form>
    </div>
  </div>

  <!-- 2. SATIR: Hatalı (sol) + Kesin Hatalı (sağ) -->
  <div class="row" style="margin-top:16px">
    <div id="badCard" class="card">
      <h3>Hatalı Proxyler (tekrar denenebilir)</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <table>
          <tr>
            <th style="width:28px"></th>
            <th>Proxy</th>
            <th>Hata Sayısı</th>
            <th>Durum</th>
          </tr>
          <?php if(!$bad_lines): ?>
            <tr><td></td><td colspan="3" class="muted">Kayıt yok</td></tr>
          <?php else: foreach ($bad_lines as $p):
            $c = (int)($counts[$p] ?? 0);
            $eligible = $c < $FAIL_LIMIT && !in_array($p,$def_lines,true);
          ?>
          <tr>
            <td><?php if($eligible): ?><input type="checkbox" name="sel[]" value="<?=h($p)?>"><?php endif; ?></td>
            <td><span class="pill"><?=h($p)?></span></td>
            <td><?=$c?></td>
            <td><?=$eligible ? 'Tekrar denenebilir' : 'Limit aşıldı'?></td>
          </tr>
          <?php endforeach; endif; ?>
        </table>
        <div style="margin-top:8px" class="flex">
          <button class="btn" name="action" value="restore_selected">Seçili hatalıları geri aktar</button>
          <button class="btn" name="action" value="restore_all_eligible">Uygun olanların hepsini geri aktar</button>
          <span class="muted">• Limit: <?=$FAIL_LIMIT?></span>
        </div>
      </form>
    </div>

    <div id="defBadCard" class="card">
      <h3>Kesin Hatalı Proxyler (<?=$FAIL_LIMIT?>+ hata)</h3>
      <table>
        <tr><th>Proxy</th><th>Hata Sayısı</th></tr>
        <?php if(!$def_lines): ?>
          <tr><td colspan="2" class="muted">Kayıt yok</td></tr>
        <?php else: foreach ($def_lines as $p): ?>
          <tr>
            <td><span class="pill"><?=h($p)?></span></td>
            <td><?= (int)($counts[$p] ?? $FAIL_LIMIT) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </table>
    </div>
  </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // === PHP değişkenleri ===
  var csrf = <?php echo json_encode($csrf); ?>;
  var LOG_TOKEN = <?php echo json_encode($LOG_TOKEN); ?>;

  // === Elemanlar ===
  var btnStart = document.getElementById('btnStart');
  var btnStop = document.getElementById('btnStop');
  var btnClearLog = document.getElementById('btnClearLog');
  var btnRefreshLog = document.getElementById('btnRefreshLog');
  var statusText = document.getElementById('statusText');
  var logbox = document.getElementById('logbox');

  var countdownEl = document.getElementById('countdown');
  var selectEl    = document.getElementById('refreshSelect');
  var softModeEl  = document.getElementById('softMode');
  var btnDoRef    = document.getElementById('btnDoRefresh');

  var pollTimer = null;
  var countdownTimer = null;
  var refreshSeconds = 10;
  var left = refreshSeconds;

  function setStatus(t){ if (statusText) statusText.textContent = t; }
  function scrollLogToBottom(){ if (logbox) logbox.scrollTop = logbox.scrollHeight; }

  // --- Bot API
  async function apiRun(action){
    setStatus('çalışıyor…');
    try{
      var fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', action);
      var res = await fetch('run_bot.php', { method: 'POST', body: fd, cache: 'no-store' });

      if (res.status === 401){ setStatus('Oturum yok. Girişe yönlendiriliyor…'); window.location='login.php'; return false; }
      if (res.status === 403){ setStatus('CSRF hatası. Sayfayı yenileyin.'); return false; }

      var ctype = (res.headers.get('content-type') || '').toLowerCase();
      if (ctype.indexOf('application/json') !== -1) {
        var j = await res.json();
        setStatus(j.ok ? (j.msg || 'ok') : ('Hata: ' + (j.msg || 'bilinmiyor')));
        return !!j.ok;
      } else {
        var txt = await res.text();
        setStatus('Hata: JSON bekleniyordu. Gelen: ' + (txt || '(boş)'));
        return false;
      }
    }catch(e){
      setStatus('Hata: ' + e);
      return false;
    }
  }

  // --- LOG
  async function fetchLog(){
    try{
      var res = await fetch('log_view.php?tail=5000&token=' + encodeURIComponent(LOG_TOKEN), {
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      var txt = await res.text();

      // Renklendirme
      var lines = txt.split(/\r?\n/).map(function(l){
        if (l.indexOf('[ROUTE]') !== -1) {
          return l
            .replace('[ROUTE]', '<span style="color:#93c5fd">[ROUTE]</span>')
            .replace(/(URL\s+)(\S+)/, '$1<span style="color:#a7f3d0">$2</span>')
            .replace(/(IP\s+)(\S+)/, '$1<span style="color:#fca5a5">$2</span>');
        } else if (/\[ERROR\]|\[WARN\]/.test(l)) {
          return '<span style="color:#fca5a5">'+l+'</span>';
        } else if (/\[INFO\]|\[SKIPPED\]/.test(l)) {
          return '<span style="color:#a7f3d0">'+l+'</span>';
        }
        return l;
      }).join('\n');

      logbox.innerHTML = lines; // renklendirme için HTML
      scrollLogToBottom();
    }catch(e){
      // Sessiz
    }
  }

  // --- Parça yenileme (proxy/defbad kartları)
  async function replaceHTML(id, url){
    var el = document.getElementById(id);
    if (!el) return;
    try{
      var res = await fetch(url + (url.indexOf('?') !== -1 ? '&' : '?') + '_=' + Date.now(), { cache:'no-store' });
      if (!res.ok) return;
      var html = await res.text();
      el.innerHTML = html;
    }catch(e){}
  }
  async function refreshProxyFragments(){
    await Promise.all([
      replaceHTML('activeCard', 'proxy_view.php?part=active'),
      replaceHTML('badCard',    'proxy_view.php?part=bad'),
      replaceHTML('defBadCard', 'proxy_view.php?part=def')
    ]);
  }

  // --- Oto yenile sayaç
  function setCountdownUI(){ if (countdownEl) countdownEl.textContent = String(left); }
  function restartCountdown(){
    var selVal = selectEl ? parseInt(selectEl.value, 10) : 10;
    refreshSeconds = (isFinite(selVal) && selVal >= 3) ? selVal : 10;
    left = refreshSeconds; setCountdownUI();
  }
  async function doSoftRefresh(){
    await Promise.all([ fetchLog(), refreshProxyFragments() ]);
  }
  function tickOnce(){
    left -= 1;
    if (left <= 0){
      var soft = !!(softModeEl && softModeEl.checked);
      if (soft){
        doSoftRefresh().finally(function(){ restartCountdown(); });
      }else{
        window.location.reload();
        return;
      }
    }
    setCountdownUI();
  }
  function startCountdown(){
    if (countdownTimer) clearInterval(countdownTimer);
    restartCountdown();
    countdownTimer = setInterval(tickOnce, 1000);
  }

  // --- Event bağlama
  if (btnStart) btnStart.addEventListener('click', async function(){
    var ok = await apiRun('start');
    if (ok){
      await fetchLog();
      if (pollTimer) clearInterval(pollTimer);
      pollTimer = setInterval(fetchLog, 2000);
    }
  });
  if (btnStop) btnStop.addEventListener('click', async function(){
    var ok = await apiRun('stop');
    if (ok && pollTimer){ clearInterval(pollTimer); pollTimer=null; }
  });
  if (btnClearLog) btnClearLog.addEventListener('click', async function(){
    var ok = await apiRun('clearlog');
    if (ok){ logbox.textContent = ''; }
  });
  if (btnRefreshLog) btnRefreshLog.addEventListener('click', fetchLog);

  if (selectEl) selectEl.addEventListener('change', restartCountdown);
  if (btnDoRef) btnDoRef.addEventListener('click', function(){
    var soft = !!(softModeEl && softModeEl.checked);
    if (soft){ doSoftRefresh().then(restartCountdown); }
    else { window.location.reload(); }
  });

  // Varsayılan: yumuşak yenile
  if (softModeEl) softModeEl.checked = true;

  // İlk yükleme
  fetchLog();
  startCountdown();

  document.addEventListener('visibilitychange', function(){
    if (document.hidden){
      if (countdownTimer) clearInterval(countdownTimer);
    }else{
      startCountdown();
    }
  });
});
</script>
</body>
</html>
