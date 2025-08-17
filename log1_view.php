<?php
// /home/proje/public_html/proje1/log1_view.php
declare(strict_types=1);
ini_set('default_charset','UTF-8');
session_start();

// ---- AUTH ----
if (empty($_SESSION['auth'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "not authorized";
    exit;
}

// ---- XHR veya token kontrolü ----
$is_xhr   = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$has_csrf = !empty($_SESSION['csrf']);
$token_ok = $has_csrf && (($_GET['token'] ?? '') === md5((string)$_SESSION['csrf']));

if (!$is_xhr && !$token_ok) {
    header("Location: bot_panel.php");
    exit;
}

// ---- Başlıklar ----
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ---- Ayarlar ----
$BASE = realpath(__DIR__) ?: __DIR__;
// yt_clicker.py artık buraya yazacak:
$LOG  = $BASE . '/yt_clicker.log';

// tail parametresi (son N bayt)
$tail = isset($_GET['tail']) ? (int)$_GET['tail'] : 5000;
if ($tail < 100)    $tail = 100;
if ($tail > 50000)  $tail = 50000;

if (!is_file($LOG)) {
    echo "(yt_clicker.log bulunamadı)";
    exit;
}

$size = (int)@filesize($LOG);
$fp   = @fopen($LOG, "rb");
if (!$fp) {
    echo "(log okunamıyor)";
    exit;
}

if ($size > $tail) {
    @fseek($fp, -$tail, SEEK_END);
}
$out = @stream_get_contents($fp);
@fclose($fp);

echo $out !== false ? $out : "";
