<?php
ini_set('default_charset','UTF-8');
mb_internal_encoding('UTF-8');
session_start();

/* ------------------ AJAX tespiti ------------------ */
$IS_AJAX = ($_SERVER['REQUEST_METHOD'] === 'POST') || isset($_GET['tail']);

function json_out(array $arr, int $http_code = 200){
  http_response_code($http_code);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ================== AYARLAR ================== */
$BASE = realpath(__DIR__) ?: __DIR__;

$MAP = [
  'proxy' => [
    'script' => $BASE . '/proxy_bot.py',
    'log'    => $BASE . '/bot.log',
    'pid'    => '/tmp/proxy_bot.pid',
  ],
  'yt' => [
    'script' => $BASE . '/yt_clicker.py',
    'log'    => $BASE . '/yt_clicker.log',
    'pid'    => '/tmp/yt_clicker.pid',
  ],
];
$DEFAULT_BOT = 'proxy';

/* Her bot için python haritası */
$PY_MAP = [
  'proxy' => '/usr/bin/python3',
  'yt'    => '/opt/ytenv/bin/python', // Playwright yüklü venv
];

/* Her bot için ortam değişkenleri (export edilecek) */
$ENV_MAP = [
  'proxy' => [
    // proxy için özel env gerekmiyorsa boş bırak
  ],
  'yt' => [
    // YT için env'ler runtime'da eklenecek: PW_HOME, HOME, PLAYWRIGHT_BROWSERS_PATH
  ],
];

/** Python’ı otomatik bul (proxy için fallback) */
function detect_python(): ?string {
  $candidates = [
    '/usr/bin/python3',
    '/usr/bin/python3.12',
    trim((string)@shell_exec('command -v python3 2>/dev/null')),
  ];
  foreach ($candidates as $p){
    $p = trim((string)$p);
    if ($p !== '' && is_file($p)) return $p;
  }
  return null;
}
$PYTHON_FALLBACK = detect_python() ?? '/usr/bin/python3';

/* Playwright cache/HOME klasörü (yt için) */
$PW_HOME = $BASE . '/.pw';
@is_dir($PW_HOME) || @mkdir($PW_HOME, 0775, true);
/* Tarayıcıların indirileceği klasör */
$PW_BROWSERS = $PW_HOME . '/browsers';
@is_dir($PW_BROWSERS) || @mkdir($PW_BROWSERS, 0775, true);

/* =============== AUTH & CSRF =============== */
if (empty($_SESSION['auth'])) {
  if ($IS_AJAX) json_out(['ok'=>false,'msg'=>'Yetkisiz (auth)','running'=>false], 401);
  header('Location: bot_panel.php'); exit;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* =============== Yardımcılar =============== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_running($pid){
  $pid = (int)$pid; if ($pid <= 0) return false;
  if (function_exists('posix_kill')) return @posix_kill($pid, 0);
  $out = @shell_exec('ps -p '.intval($pid).' 2>&1');
  return is_string($out) && strpos($out, (string)$pid) !== false;
}
function get_pid($file){
  if(!is_file($file)) return null;
  $p = (int)trim((string)@file_get_contents($file));
  return $p>0 ? $p : null;
}
function save_atomic($path, $content){
  $tmp = $path.'.tmp-'.uniqid('', true);
  if (file_put_contents($tmp, $content) === false) return false;
  return @rename($tmp, $path);
}
function tail_file($file,$lines=300){
  if(!is_file($file)) return "";
  $f = @fopen($file,'r'); if(!$f) return "";
  $buffer=''; $chunk=4096; fseek($f,0,SEEK_END); $pos=ftell($f); $cnt=0;
  while($pos>0 && $cnt<=$lines){
    $read=min($chunk,$pos); $pos-=$read; fseek($f,$pos,SEEK_SET); $buffer=fread($f,$read).$buffer; $cnt=substr_count($buffer,"\n");
  }
  fclose($f); $arr=explode("\n",$buffer); return implode("\n",array_slice($arr,-$lines));
}
function rotate_log_if_big($log, $max_bytes = 5242880){ // 5 MB
  if (is_file($log) && filesize($log) > $max_bytes) {
    @rename($log, $log.'.1');
  }
}

/** Bot seçimi (tek bot veya tümü) */
function pick_bots(array $MAP, ?string $bot_param): array {
  if ($bot_param && isset($MAP[$bot_param])) return [$bot_param=>$MAP[$bot_param]];
  return $MAP;
}

/* =============== CSRF (POST) =============== */
$valid_post = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $posted = (string)$_POST['csrf'] ?? '';
  if (hash_equals($GLOBALS['csrf'], $posted)) {
    $valid_post = true;
  } else {
    json_out(['ok'=>false,'msg'=>'CSRF doğrulaması başarısız','running'=>false], 403);
  }
}

/* =============== AJAX endpointleri =============== */

/* TAIL (GET) — ?bot=proxy|yt */
if (isset($_GET['tail'])) {
  $bot = $_GET['bot'] ?? $DEFAULT_BOT;
  if (!isset($MAP[$bot])) $bot = $DEFAULT_BOT;

  $LOG  = $MAP[$bot]['log'];
  $PIDF = $MAP[$bot]['pid'];

  $lines  = max(50, min(5000, (int)($_GET['lines'] ?? 400)));
  $pidNow = get_pid($PIDF);
  $running= $pidNow && is_running($pidNow);
  $logTail= tail_file($LOG, $lines);

  // seçilen bot için python path:
  $py = $GLOBALS['PY_MAP'][$bot] ?? $GLOBALS['PYTHON_FALLBACK'];
  if (!$py || !is_file($py)) $py = $GLOBALS['PYTHON_FALLBACK'];

  json_out([
    'ok'=>true,
    'bot'=>$bot,
    'running'=>$running,
    'pid'=>$pidNow?:null,
    'tail'=>$logTail,
    'csrf'=>$GLOBALS['csrf'],
    'paths'=>[
      'base'=>$GLOBALS['BASE'],
      'python'=>$py,
      'script'=>$MAP[$bot]['script'],
      'log'=>$LOG,
      'pid'=>$PIDF,
      'pw_home'=>$GLOBALS['PW_HOME'],
      'pw_browsers'=>$GLOBALS['PW_BROWSERS'],
    ],
  ]);
}

/* STATUS (POST) — opsiyonel bot param */
if ($IS_AJAX && $valid_post && (($_POST['action'] ?? '')==='status')) {
  $bot = $_POST['bot'] ?? null;
  $targets = pick_bots($MAP, $bot);
  $out = [];
  foreach ($targets as $key=>$S){
    $pid = get_pid($S['pid']);
    $out[$key] = [
      'running' => (bool)($pid && is_running($pid)),
      'pid'     => $pid ?: null
    ];
  }
  json_out(['ok'=>true,'status'=>$out]);
}

/* START / STOP / CLEAR (POST) */
if ($IS_AJAX && $valid_post && isset($_POST['action'])) {
  $action = $_POST['action'];
  if ($action === 'clearlog') $action = 'clear_log';

  $bot = $_POST['bot'] ?? null;  // UI’dan geliyor
  $targets = pick_bots($MAP, $bot);

  if ($action === 'start') {
    $msgs=[]; $anyRunning=false; $anyError=false;

    foreach ($targets as $key=>$S){
      $SCRIPT = $S['script']; $LOG = $S['log']; $PID = $S['pid'];

      if (!is_file($SCRIPT)) { $msgs[]="[$key] Betik yok: $SCRIPT"; $anyError=true; continue; }
      if (!is_readable($SCRIPT)) { $msgs[]="[$key] Betik okunamıyor: $SCRIPT"; $anyError=true; continue; }

      // bot’a özel python (yoksa fallback)
      $PY = $GLOBALS['PY_MAP'][$key] ?? $GLOBALS['PYTHON_FALLBACK'];
      if (!$PY || !is_file($PY)) { $msgs[]="[$key] Python bulunamadı: ".($PY ?: ''); $anyError=true; continue; }

      $pid = get_pid($PID);
      if ($pid && is_running($pid)) { $msgs[]="[$key] Zaten çalışıyor (PID: $pid)"; $anyRunning=true; continue; }

      @touch($LOG); @chmod($LOG,0664);
      rotate_log_if_big($LOG);

      // Ortam değişkenleri: bot’a özel export
      $envPairs = [];
      $botEnv = $GLOBALS['ENV_MAP'][$key] ?? [];
      foreach ($botEnv as $ek => $ev) {
        $envPairs[] = sprintf('%s=%s', escapeshellcmd($ek), escapeshellarg($ev));
      }

      // yt için PW_HOME, HOME ve BROWSERS PATH ekle
      if ($key === 'yt') {
        $envPairs[] = 'PW_HOME=' . escapeshellarg($GLOBALS['PW_HOME']);
        $envPairs[] = 'HOME='    . escapeshellarg($GLOBALS['PW_HOME']);
        $envPairs[] = 'PLAYWRIGHT_BROWSERS_PATH=' . escapeshellarg($GLOBALS['PW_BROWSERS']);
      }

      $env = '';
      if (!empty($envPairs)) {
        $env = 'export ' . implode(' ', $envPairs) . '; ';
      }

      $cmd = sprintf(
        'cd %s && %s nohup %s %s >> %s 2>&1 & echo $! > %s',
        escapeshellarg($BASE),
        $env,
        escapeshellcmd($PY),
        escapeshellarg($SCRIPT),
        escapeshellarg($LOG),
        escapeshellarg($PID)
      );
      @shell_exec($cmd);
      usleep(300000);

      $newPid = get_pid($PID);
      if ($newPid && is_running($newPid)) {
        @chmod($PID,0660);
        $msgs[] = "[$key] Başlatıldı (PID: $newPid)";
        $anyRunning = true;
      } else {
        $msgs[] = "[$key] Başlatılamadı.";
        $anyError = true;
      }
    }

    json_out([
      'ok'=> !$anyError,
      'msg'=> implode(' | ', $msgs),
      'running'=> $anyRunning,
      'details'=> $msgs
    ]);
  }

  if ($action === 'stop') {
    $msgs=[]; $anyRunning=false;
    foreach ($targets as $key=>$S){
      $PID = $S['pid'];
      $pid = get_pid($PID);
      if ($pid && is_running($pid)) {
        @shell_exec('kill -TERM '.intval($pid).' 2>&1');
        usleep(300000);
        if (is_running($pid)) @shell_exec('kill -KILL '.intval($pid).' 2>&1');
        if (!is_running($pid)) { @unlink($PID); $msgs[]="[$key] Durduruldu (PID: $pid)"; }
        else { $msgs[]="[$key] Durdurulamadı (PID: $pid)"; $anyRunning=true; }
      } else {
        $msgs[]="[$key] Çalışan süreç yok.";
      }
    }
    json_out([
      'ok'=> !$anyRunning,
      'msg'=> implode(' | ', $msgs),
      'running'=> false,
      'details'=> $msgs
    ]);
  }

  if ($action === 'clear_log') {
    $msgs=[]; $anyFail=false;
    foreach ($targets as $key=>$S){
      $ok = @touch($S['log']) && save_atomic($S['log'], "");
      $msgs[] = $ok ? "[$key] Log temizlendi." : "[$key] Log temizlenemedi (izin?).";
      if (!$ok) $anyFail=true;
    }
    json_out(['ok'=> !$anyFail, 'msg'=>implode(' | ', $msgs)]);
  }

  json_out(['ok'=>false,'msg'=>'Bilinmeyen action.']);
}

/* ====== HTML MODU (GET) ====== */
header('Content-Type: text/html; charset=UTF-8');

$B = $MAP[$DEFAULT_BOT];
$pid     = get_pid($B['pid']);
$running = $pid && is_running($pid);
$logTail = tail_file($B['log'], 400);

// default bot için python yolu gösterimi
$pyShow = $PY_MAP[$DEFAULT_BOT] ?? $PYTHON_FALLBACK;
if (!$pyShow || !is_file($pyShow)) $pyShow = $PYTHON_FALLBACK;
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
label{font-weight:600;margin-right:8px}
select{padding:6px 10px;border:1px solid #d1d5db;border-radius:8px}
.row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
</style>
</head>
<body>
  <h2>Python Bot – Arkaplan Çalıştırma</h2>

  <div class="row">
    <label for="botSel">Bot:</label>
    <select id="botSel">
      <option value="proxy">proxy (proxy_bot.py)</option>
      <option value="yt">yt (yt_clicker.py)</option>
    </select>
  </div>

  <p style="margin-top:10px">Durum:
    <?php if ($running): ?>
      <span class="status ok">ÇALIŞIYOR (PID: <?=h($pid)?>)</span>
    <?php else: ?>
      <span class="status bad">DURDU</span>
    <?php endif; ?>
    <br><small class="mono">BASE: <?=h($BASE)?></small>
    <br><small class="mono">PYTHON (varsayılan görünüm): <?=h($pyShow)?></small>
    <br><small class="mono">SCRIPT: <?=h($B['script'])?></small>
    <br><small class="mono">LOG: <?=h($B['log'])?></small>
    <br><small class="mono">PID: <?=h($B['pid'])?></small>
    <br><small class="mono">PW_HOME (yt için): <?=h($PW_HOME)?></small>
    <br><small class="mono">PLAYWRIGHT_BROWSERS_PATH (yt): <?=h($PW_BROWSERS)?></small>
  </p>

  <div class="msgs" id="flash"></div>

  <form id="botForm" method="post" style="margin:10px 0">
    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
    <button type="button" class="btn primary" data-action="start">Başlat</button>
    <button type="button" class="btn"        data-action="stop">Durdur</button>
    <button type="button" class="btn"        data-action="clear_log">Log Temizle</button>
    <span id="statusTxt">hazır</span>
  </form>

  <h3>Son Log</h3>
  <pre id="logbox"><?=h($logTail)?></pre>

<script>
const csrf = <?= json_encode($csrf) ?>;
const logBox = document.getElementById('logbox');
const statusTxt = document.getElementById('statusTxt');
const flash = document.getElementById('flash');
const botSel = document.getElementById('botSel');

function currentBot(){ return botSel.value || '<?=h($DEFAULT_BOT)?>'; }
function setStatus(t){ statusTxt.textContent = t; }
function flashMsg(text, ok=true){
  const s = document.createElement('span');
  s.className = ok ? 'ok' : 'bad';
  s.textContent = (ok ? '✅ ' : '⚠️ ') + text;
  flash.appendChild(s);
  window.scrollTo({top: document.body.scrollHeight, behavior: 'smooth'});
}
async function postAction(action){
  setStatus('işleniyor…');
  const fd = new FormData();
  fd.append('csrf', csrf);
  fd.append('action', action);
  fd.append('bot', currentBot());
  try{
    const res = await fetch(location.href, { method: 'POST', body: fd, cache:'no-store' });
    if (res.status === 401) { setStatus('Oturum yok. Girişe yönlendiriliyor…'); location.href='login.php'; return false; }
    if (res.status === 403) { setStatus('CSRF hatası. Sayfayı yenileyin.'); return false; }
    const j = await res.json();
    setStatus(j.ok ? (j.msg || 'ok') : ('Hata: '+(j.msg||'bilinmiyor')));
    if (j.msg) flashMsg(j.msg, j.ok);
    return !!j.ok;
  }catch(e){
    setStatus('Hata: ' + e);
    return false;
  }
}
async function fetchTail(lines=400){
  try{
    const bot = currentBot();
    const res = await fetch(location.pathname + '?tail=1&lines='+lines+'&bot='+encodeURIComponent(bot), { cache:'no-store' });
    const j = await res.json();
    if (j && typeof j.tail === 'string') {
      logBox.textContent = j.tail;
      logBox.scrollTop = logBox.scrollHeight;
    }
  }catch(e){}
}
document.querySelectorAll('button[data-action]').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    const action = btn.getAttribute('data-action');
    if (action === 'stop' && !confirm('Durdurulsun mu?')) return;
    const ok = await postAction(action);
    await fetchTail(400);
  });
});
botSel.addEventListener('change', ()=>fetchTail(400));
setInterval(()=>fetchTail(400), 2000);
fetchTail(400);
</script>
</body>
</html>
