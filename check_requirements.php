<?php
/*********************************************
 * Python Bot – Gereksinim Kontrol Paneli
 * Kayıt yeri: /home/proje/public_html/proje1/check_requirements.php
 *********************************************/

header('Content-Type: text/html; charset=utf-8');

// ==== AYARLAR ====
$BASE_DIR   = "/home/python";
$VENV_ACT   = "/home/python/venv/bin/activate";
$PROXY_FILE = "/home/python/proxy.txt";
$URL_FILE   = "/home/python/url.txt";
$LOG_FILE   = "/home/python/log.txt";
// ================

function ini_get_bool($v) {
  $val = strtolower(trim((string)ini_get($v)));
  return in_array($val, ['1','on','true','yes'], true);
}

function run_cmd($cmd) {
  $out = []; $code = 0;
  @exec($cmd . " 2>&1", $out, $code);
  return ["code"=>$code, "out"=>implode("\n", $out)];
}

function passfail($ok) {
  return $ok ? '<span class="pill ok">PASS</span>' : '<span class="pill bad">FAIL</span>';
}

function row($name, $ok, $detail='', $fix='') {
  echo "<tr>";
  echo "<td class='name'>".htmlspecialchars($name)."</td>";
  echo "<td class='status'>".passfail($ok)."</td>";
  echo "<td class='detail'><pre>".htmlspecialchars($detail)."</pre></td>";
  echo "<td class='fix'>".($fix ? htmlspecialchars($fix) : '')."</td>";
  echo "</tr>";
}

$results = [];
$advices = [];

// 1) PHP fonksiyonları (exec/system/shell_exec)
$disable = (string)ini_get('disable_functions');
$disabled = array_filter(array_map('trim', explode(',', $disable)));
$need = ['exec','shell_exec','system'];
$missing = [];
foreach ($need as $fn) {
  if (!function_exists($fn) || in_array($fn, $disabled, true)) $missing[] = $fn;
}
$ok_php_exec = empty($missing);
$detail = "disable_functions: ".($disable?:'(boş)')."\nexists: exec=". (int)function_exists('exec').", shell_exec=". (int)function_exists('shell_exec').", system=". (int)function_exists('system');
$fix = $ok_php_exec ? '' : "php.ini 'disable_functions' listesinden kaldır: ".implode(', ', $missing);
$results[] = ['PHP shell yetkisi',$ok_php_exec,$detail,$fix];

// 2) PHP-FPM kullanıcı/izinler
$euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
$userInfo = $euid !== null ? @posix_getpwuid($euid) : null;
$phpUser = $userInfo['name'] ?? get_current_user();
$home = $userInfo['dir'] ?? '~';
$detail = "PHP-FPM user: {$phpUser}\nHome: {$home}";
$ok_user = true; // sadece bilgi
$results[] = ['PHP-FPM kullanıcı bilgisi', $ok_user, $detail, ''];

// 3) /home/python var mı? yazılabilir mi?
$exists = is_dir($BASE_DIR);
$writable = $exists && is_writable($BASE_DIR);
// Yazma testi
$write_test_detail = '';
$ok_write = false;
if ($writable) {
  $testFile = $BASE_DIR."/.__write_test_".uniqid().".tmp";
  $ok_write = @file_put_contents($testFile, "ok") !== false;
  if ($ok_write) {
    @unlink($testFile);
    $write_test_detail = "Yazma testi başarılı.";
  } else {
    $write_test_detail = "Yazma testi başarısız.";
  }
}
$ok_base = $exists && $writable && $ok_write;
$detail = "exists=".($exists?'yes':'no').", writable=".($writable?'yes':'no')."\n".$write_test_detail;
$fix = $ok_base ? '' : "Dizin oluştur ve yazma yetkisi ver:\n  sudo mkdir -p {$BASE_DIR}\n  sudo chown -R {$phpUser}:{$phpUser} {$BASE_DIR}";
$results[] = ['/home/python dizini', $ok_base, $detail, $fix];

// 4) Python 3 kurulu mu?
$py3 = run_cmd('which python3');
$py3v = run_cmd('python3 -V');
$ok_py3 = ($py3['code']===0 && $py3v['code']===0 && stripos($py3v['out'],'Python 3')!==false);
$detail = "which python3: {$py3['out']}\npython3 -V: {$py3v['out']}";
$fix = $ok_py3 ? '' : "Python 3 kurun (ör. pyenv ya da sistem paket yöneticisi).";
$results[] = ['Python 3', $ok_py3, $detail, $fix];

// 5) pip3 var mı?
$pip3 = run_cmd('which pip3');
$pip3v = run_cmd('pip3 --version');
$ok_pip3 = ($pip3['code']===0 && $pip3v['code']===0);
$detail = "which pip3: {$pip3['out']}\npip3 --version: {$pip3v['out']}";
$fix = $ok_pip3 ? '' : "pip3 kurun: python3 -m ensurepip --upgrade veya paket yöneticisi.";
$results[] = ['pip3', $ok_pip3, $detail, $fix];

// 6) Venv var mı? (opsiyonel – varsa içini kontrol et)
$venvExists = is_file($VENV_ACT);
$ok_venv = $venvExists;
$detail = $venvExists ? "Bulundu: {$VENV_ACT}" : "Venv yok.";
$fix = $venvExists ? '' : "Sanal ortam oluşturun:\n  python3 -m venv {$BASE_DIR}/venv\n  source {$VENV_ACT}\n  pip install --upgrade pip playwright\n  python -m playwright install chromium";
$results[] = ['Venv (virtualenv)', $ok_venv, $detail, $fix];

// 7) Venv + Playwright sürümü (venv varsa)
$ok_pw = false; $detail = ''; $fix = '';
if ($venvExists) {
  $cmd = 'bash -lc ' . escapeshellarg("source {$VENV_ACT} && python -c \"import playwright, sys; print('playwright', playwright.__version__)\" ");
  $r = run_cmd($cmd);
  $ok_pw = ($r['code']===0 && stripos($r['out'],'playwright')!==false);
  $detail = $r['out'] ?: '(çıktı yok)';
  $fix = $ok_pw ? '' : "Playwright kurun:\n  source {$VENV_ACT}\n  pip install playwright\n  python -m playwright install chromium";
} else {
  $detail = "Venv yoksa atlandı.";
}
$results[] = ['Playwright modülü', $ok_pw || !$venvExists, $detail, $fix];

// 8) nohup arka plan testi (/home/python yazılabilir olmalı)
$ok_nohup = false; $detail = ''; $fix = '';
if ($ok_base) {
  $testLog = $BASE_DIR.'/.__nohup_test.log';
  @unlink($testLog);
  $cmd = 'bash -lc ' . escapeshellarg("nohup sh -c 'echo start; sleep 1; echo done' > {$testLog} 2>&1 & echo $!");
  $r = run_cmd($cmd);
  $pid = trim($r['out']);
  // biraz bekle
  usleep(800000);
  $logContent = @file_get_contents($testLog);
  $ok_nohup = (strpos($logContent, 'done') !== false);
  $detail = "PID: {$pid}\nLog:\n".$logContent;
  $fix = $ok_nohup ? '' : "nohup/izin sorunu olabilir. SELinux da etkileyebilir.";
} else {
  $detail = "/home/python yazma testi geçmediği için atlandı.";
}
$results[] = ['nohup ile arka plan', $ok_nohup || !$ok_base, $detail, $fix];

// 9) SELinux durumu
$geten = run_cmd('getenforce');
$detail = $geten['out'] ?: '(komut yok olabilir)';
$ok_sel = true; // sadece bilgi
$fix = (stripos($detail,'Enforcing')!==false) ? "Enforcing ise süreçleri kısıtlayabilir. Gerekirse geçici: sudo setenforce 0" : "";
$results[] = ['SELinux', $ok_sel, $detail, $fix];

// 10) proxy.txt ve url.txt yazılabilir mi?
function check_file_rw($path) {
  $okDir = is_dir(dirname($path)) && is_writable(dirname($path));
  $w = @file_put_contents($path, "# test ".date('c')."\n", FILE_APPEND);
  return [$okDir && $w!==false, "dir_writable=".($okDir?'yes':'no').", append_result=".($w!==false?'ok':'fail')];
}
list($ok_proxy,$d1) = check_file_rw($PROXY_FILE);
list($ok_url,$d2)   = check_file_rw($URL_FILE);
$results[] = ['proxy.txt yazma', $ok_proxy, $d1, $ok_proxy?'':"Dizin/yetki verin: chown {$phpUser}:{$phpUser} {$BASE_DIR}"];
$results[] = ['url.txt yazma',   $ok_url,   $d2, $ok_url?'':"Dizin/yetki verin: chown {$phpUser}:{$phpUser} {$BASE_DIR}"];

// 11) PHP limitleri (bilgi)
$detail = "max_execution_time=".ini_get('max_execution_time')."\nmemory_limit=".ini_get('memory_limit')."\nopen_basedir=". (ini_get('open_basedir')?:'(yok)');
$results[] = ['PHP limitleri (bilgi)', true, $detail, 'Uzun işler için nohup + log önerilir.'];

// ===== HTML ÇIKTI =====
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Python Bot – Gereksinim Kontrolü</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:1100px;margin:24px auto;padding:0 12px}
table{width:100%;border-collapse:collapse;margin-top:12px}
th,td{border:1px solid #e5e7eb;padding:8px;vertical-align:top}
th{background:#f8fafc;text-align:left}
.name{width:220px;font-weight:600}
.status{width:100px}
.detail{width:420px}
.fix{color:#374151;white-space:pre-wrap}
.pill{display:inline-block;padding:2px 10px;border-radius:999px;font-weight:700;font-size:12px}
.ok{background:#dcfce7;color:#065f46}
.bad{background:#fee2e2;color:#991b1b}
.small{color:#6b7280;font-size:12px}
code,pre{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
.btn{display:inline-block;margin-top:8px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#f9fafb;text-decoration:none;color:#111827}
.btn:hover{background:#eef2ff}
</style>
</head>
<body>
  <h2>Python Bot – Gereksinim Kontrolü</h2>
  <div class="small">Konum: <?=htmlspecialchars($BASE_DIR)?> &middot; PHP-FPM kullanıcı: <b><?=htmlspecialchars($phpUser)?></b></div>

  <table>
    <thead><tr><th>Kontrol</th><th>Durum</th><th>Detay</th><th>Öneri / Çözüm</th></tr></thead>
    <tbody>
      <?php foreach ($results as $r) { row($r[0], $r[1], $r[2], $r[3]); } ?>
    </tbody>
  </table>

  <p>
    <a class="btn" href="<?=htmlspecialchars($_SERVER['PHP_SELF'])?>">Yeniden Çalıştır</a>
  </p>

  <h3>Sonraki Adım</h3>
  <p>Yukarıdaki kritik maddeler <b>PASS</b> ise, talebin doğrultusunda <b>kurulum ve başlatma</b> için tek komutla çalışacak bir <b>bash</b> betiği hazırlayacağım.</p>

  <h4>Başlıca PASS Olması Gerekenler</h4>
  <ul>
    <li><b>PHP shell yetkisi</b> (exec/shell_exec)</li>
    <li><b>/home/python</b> dizini yazılabilir</li>
    <li><b>Python 3</b> ve <b>pip3</b> kurulu</li>
    <li>(Varsa) Venv + <b>Playwright</b> düzgün</li>
    <li><b>nohup</b> testi başarılı</li>
  </ul>

  <p class="small">Not: Bu sayfa kurulum yapmaz; yalnızca denetim yapar.</p>
</body>
</html>
