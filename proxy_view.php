<?php
// proxy_view.php — Proxy bölümlerini parça parça HTML olarak döndürür.
// ?part=active | bad | def  (default: active)
declare(strict_types=1);
ini_set('default_charset','UTF-8');
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

session_start();
if (empty($_SESSION['auth'])) { http_response_code(401); echo 'Yetkisiz'; exit; }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$BASE      = '/home/proje/public_html/proje1';
$PROXY     = $BASE . '/proxy.txt';
$BAD       = $BASE . '/hatali_proxy.txt';
$DEF_BAD   = $BASE . '/kesin_hatali_proxy.txt';
$COUNTS    = $BASE . '/bad_counts.json';
$FAIL_LIMIT = 5;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function read_lines($p){
  if (!is_file($p)) return [];
  $out=[];
  foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln){
    $ln=trim($ln);
    if ($ln==='' || $ln[0]==='#') continue;
    $out[$ln]=true;
  }
  return array_keys($out);
}
function load_counts($p){
  if (!is_file($p)) return [];
  $raw = file_get_contents($p);
  $j = json_decode($raw,true);
  return is_array($j) ? $j : [];
}

$proxy_lines  = read_lines($PROXY);
$bad_lines    = read_lines($BAD);
$def_lines    = read_lines($DEF_BAD);
$counts       = load_counts($COUNTS);

$part = $_GET['part'] ?? 'active';

if ($part === 'active') {
  ?>
  <h3>Aktif Proxy Listesi (<span class="muted"><?=count($proxy_lines)?> satır</span>)</h3>
  <form method="post" action="bot_panel.php">
    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
    <textarea name="proxy_text" spellcheck="false"><?=h(implode("\n",$proxy_lines))?></textarea>
    <div style="margin-top:8px" class="flex">
      <button class="btn primary" name="action" value="save_proxy">Kaydet</button>
    </div>
  </form>
  <?php
  exit;
}

if ($part === 'bad') {
  ?>
  <h3>Hatalı Proxyler (tekrar denenebilir)</h3>
  <form method="post" action="bot_panel.php">
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
  <?php
  exit;
}

if ($part === 'def') {
  ?>
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
  <?php
  exit;
}

http_response_code(400);
echo 'Bilinmeyen part';
