<?php
ini_set('default_charset','UTF-8');
mb_internal_encoding('UTF-8');

session_start();

/**
 * ÖNEMLİ: POST geldiyse AJAX kabul et ve HER ZAMAN JSON dön.
 * (Eski davranış: sadece ajax=1 veya tail varsa AJAX sayılıyordu)
 */
$IS_AJAX = ($_SERVER['REQUEST_METHOD'] === 'POST') || isset($_GET['tail']);

function json_out(array $arr, int $http_code = 200){
  http_response_code($http_code);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

// ---- AYARLAR ----
$BASE   = '/home/proje/public_html/proje1';
$SCRIPT = $BASE . '/proxy_bot.py';
$PYTHON = '/usr/bin/python3';
$LOG    = $BASE . '/bot.log';
$PID    = $BASE . '/bot.pid';
// ------------------

// AUTH
if (empty($_SESSION['auth'])) {
  if ($IS_AJAX) json_out(['ok'=>false,'msg'=>'Yetkisiz (auth)','running'=>false], 401);
  header('Location: bot_panel.php'); exit;
}

// CSRF token (panel zaten üretiyor)
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// Yardımcılar
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_running($pid){
  $pid = (int)$pid; if ($pid <= 0) return false;
  if (function_exists('posix_kill')) return @posix_kill($pid, 0);
  $out = @shell_exec('ps -p '.intval($pid).' 2>&1');
  return is_string($out) && strpos($out, (string)$pid) !== false;
}
function get_pid($file){
  if(!is_file($file)) return null;
  $p = (int)trim(@file_get_contents($file));
  return $p>0 ? $p : null;
}
function save_atomic($path, $content){
  $tmp = $path.'.tmp-'.uniqid('', true);
  if (file_put_contents($tmp, $content) === false) return false;
  return rename($tmp, $path);
}
function tail_file($file,$lines=300){
  if(!is_file($file)) return "";
  $f = fopen($file,'r'); if(!$f) return "";
  $buffer=''; $chunk=4096; fseek($f,0,SEEK_END); $pos=ftell($f); $cnt=0;
  while($pos>0 && $cnt<=$lines){
    $read=min($chunk,$pos); $pos-=$read; fseek($f,$pos,SEEK_SET); $buffer=fread($f,$read).$buffer; $cnt=substr_count($buffer,"\n");
  }
  fclose($f); $arr=explode("\n",$buffer); return implode("\n",array_slice($arr,-$lines));
}

// CSRF kontrolü (POST ise zorunlu)
$valid_post = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $posted = (string)($_POST['csrf'] ?? '');
  if (hash_equals($csrf, $posted)) {
    $valid_post = true;
  } else {
    // POST geldi ama CSRF yok/yanlış → JSON ile dön (redirect yok)
    json_out(['ok'=>false,'msg'=>'CSRF doğrulaması başarısız','running'=>false], 403);
  }
}

/* ===== AJAX endpointleri ===== */

// TAIL (GET)
if (isset($_GET['tail'])) {
  $lines  = max(50, min(5000, (int)($_GET['lines'] ?? 400)));
  $pidNow = get_pid($PID);
  $running= $pidNow && is_running($pidNow);
  $logTail= tail_file($LOG, $lines);
  json_out(['ok'=>true,'running'=>$running,'pid'=>$pidNow?:null,'tail'=>$logTail,'csrf'=>$csrf]);
}

// STATUS (POST)
if ($IS_AJAX && $valid_post && ($_POST['action'] ?? '')==='status') {
  $pidNow = get_pid($PID);
  $running= $pidNow && is_running($pidNow);
  json_out(['ok'=>true,'running'=>$running,'pid'=>$pidNow?:null]);
}

// START / STOP / CLEAR (POST)
if ($IS_AJAX && $valid_post && isset($_POST['action'])) {

  // Hem "clear_log" hem "clearlog" kabul
  $action = $_POST['action'];
  if ($action === 'clearlog') $action = 'clear_log';

  if ($action === 'start') {
    if (!is_file($SCRIPT)) json_out(['ok'=>false,'msg'=>"Python betiği yok: $SCRIPT"]);
    if (!is_file($PYTHON)) json_out(['ok'=>false,'msg'=>"Python yolu yok: $PYTHON"]);

    $pid = get_pid($PID);
    if ($pid && is_running($pid)) json_out(['ok'=>true,'msg'=>"Zaten çalışıyor (PID: $pid)",'running'=>true,'pid'=>$pid]);

    @touch($LOG); @chmod($LOG,0664);

    /**
     * Başlatma: PID’i güvenilir yazmak için echo $! > $PID
     * küçük gecikmeyle doğrula
     */
    $cmd = sprintf(
      'nohup %s %s >> %s 2>&1 & echo $! > %s',
      escapeshellcmd($PYTHON),
      escapeshellarg($SCRIPT),
      escapeshellarg($LOG),
      escapeshellarg($PID)
    );
    @shell_exec($cmd);
    usleep(250000); // 0.25s

    $newPid = get_pid($PID);
    if ($newPid && is_running($newPid)) {
      @chmod($PID,0660);
      json_out(['ok'=>true,'msg'=>"Başlatıldı (PID: $newPid)",'running'=>true,'pid'=>$newPid]);
    }
    json_out(['ok'=>false,'msg'=>"Başlatılamadı. (İzinler/komut kontrol)"]);
  }

  if ($action === 'stop') {
    $pid = get_pid($PID);
    if ($pid && is_running($pid)) {
      @shell_exec('kill -TERM '.intval($pid).' 2>&1');
      usleep(300000);
      if (is_running($pid)) @shell_exec('kill -KILL '.intval($pid).' 2>&1');
      if (!is_running($pid)) { @unlink($PID); json_out(['ok'=>true,'msg'=>"Durduruldu (PID: $pid)",'running'=>false]); }
      json_out(['ok'=>false,'msg'=>"Durdurulamadı (PID: $pid)"]);
    }
    json_out(['ok'=>true,'msg'=>"Çalışan süreç yok.",'running'=>false]);
  }

  if ($action === 'clear_log') {
    if (save_atomic($LOG, "")) json_out(['ok'=>true,'msg'=>"Log temizlendi."]);
    json_out(['ok'=>false,'msg'=>"Log temizlenemedi (izin?)."]);
  }

  // bilinmeyen action
  json_out(['ok'=>false,'msg'=>'Bilinmeyen action.']);
}

/* ====== HTML MODU (SADECE GET) ======
   POST isteklerde asla buraya düşülmez → JSON garanti */
header('Content-Type: text/html; charset=UTF-8');

$pid     = get_pid($PID);
$running = $pid && is_running($pid);
$logTail = tail_file($LOG, 400);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Bot Çalıştır (Arka Plan)</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:1000px;margin:24px auto;padding:0 12px}
.btn{padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#f9fafb;cursor:pointer}
.btn.primary{background:#2563eb;color:#fff;border-color:#2563eb}
.status{padding:6px 10px;border-radius:999px;display:inline-block}
.ok{background:#dcfce7;color:#065f46}.bad{background:#fee2e2;color:#991b1b}
pre{white-space:pre-wrap;background:#0b1021;color:#e5e7eb;padding:12px;border-radius:8px;max-height:65vh;overflow:auto}
.msgs span{display:block;margin:6px 0}
small.mono{font-family:ui-monospace,Menlo,Consolas,monospace;color:#6b7280}
#statusTxt{margin-left:8px;color:#6b7280}
</style>
</head>
<body>
  <h2>Python Bot – Arkaplan Çalıştırma</h2>

  <p>Durum:
    <?php if ($running): ?>
      <span class="status ok">ÇALIŞIYOR (PID: <?=h($pid)?>)</span>
    <?php else: ?>
      <span class="status bad">DURDU</span>
    <?php endif; ?>
    <br><small class="mono">PYTHON: <?=h($PYTHON)?></small>
    <br><small class="mono">SCRIPT: <?=h($SCRIPT)?></small>
    <br><small class="mono">LOG: <?=h($LOG)?></small>
  </p>

  <div class="msgs" id="flash"></div>

  <form id="botForm" method="post" style="margin:10px 0">
    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
    <input type="hidden" name="ajax" value="1">
    <button type="button" class="btn primary" data-action="start" <?= $running?'disabled':''?>>Başlat</button>
    <button type="button" class="btn"        data-action="stop"  <?= $running?'':'disabled'?>>Durdur</button>
    <button type="button" class="btn"        data-action="clear_log">Log Temizle</button>
    <span id="statusTxt">hazır</span>
  </form>

  <h3>Son Log</h3>
  <pre id="logbox"><?=h($logTail)?></pre>

<script>
const csrf = <?= json_encode($csrf) ?>;
const form = document.getElementById('botForm');
const logBox = document.getElementById('logbox');
const statusTxt = document.getElementById('statusTxt');
const flash = document.getElementById('flash');

function setStatus(t){ statusTxt.textContent = t; }
function flashMsg(text, ok=true){
  const s = document.createElement('span');
  s.className = ok ? 'ok' : 'bad';
  s.textContent = (ok ? '✅ ' : '⚠️ ') + text;
  flash.appendChild(s);
  window.scrollTo({top: document.body.scrollHeight, behavior: 'smooth'});
}
function setButtons(running){
  const btnStart = form.querySelector('[data-action="start"]');
  const btnStop  = form.querySelector('[data-action="stop"]');
  if (running) { btnStart.disabled = true; btnStop.disabled = false; }
  else { btnStart.disabled = false; btnStop.disabled = true; }
}
async function postAction(action){
  setStatus('işleniyor…');
  const fd = new FormData();
  fd.append('csrf', csrf);
  fd.append('ajax', '1');
  fd.append('action', action);
  try{
    const res = await fetch(location.href, { method: 'POST', body: fd, cache:'no-store' });
    if (res.status === 401) { setStatus('Oturum yok. Girişe yönlendiriliyor…'); location.href='login.php'; return false; }
    if (res.status === 403) { setStatus('CSRF hatası. Sayfayı yenileyin.'); return false; }
    const j = await res.json();
    setStatus(j.ok ? (j.msg || 'ok') : ('Hata: '+(j.msg||'bilinmiyor')));
    setButtons(!!j.running);
    if (j.msg) flashMsg(j.msg, j.ok);
    return !!j.ok;
  }catch(e){
    setStatus('Hata: ' + e);
    return false;
  }
}
async function fetchTail(lines=400){
  try{
    const res = await fetch(location.pathname + '?tail=1&lines='+lines, { cache:'no-store' });
    const j = await res.json();
    if (j && typeof j.tail === 'string') {
      logBox.textContent = j.tail;
      logBox.scrollTop = logBox.scrollHeight;
      setButtons(!!j.running);
    }
  }catch(e){}
}
form.querySelectorAll('button[data-action]').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    const action = btn.getAttribute('data-action');
    if (action === 'stop' && !confirm('Durdurulsun mu?')) return;
    const ok = await postAction(action);
    await fetchTail(400);
  });
});
setInterval(()=>fetchTail(400), 2000);
fetchTail(400);
</script>
</body>
</html>
