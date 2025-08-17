<?php
// /home/proje/public_html/proje1/log_view.php
declare(strict_types=1);
ini_set('default_charset','UTF-8');

session_start();

// ---- AUTH: sadece giriş yapmış kullanıcılar ----
if (empty($_SESSION['auth'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "not authorized";
    exit;
}

// ---- Erişim Kısıtı: yalnızca AJAX veya geçerli token ----
// 1) XHR denetimi (fetch ile manuel gönderilebilir)
// 2) veya token = md5($_SESSION['csrf'])
$is_xhr   = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$has_csrf = !empty($_SESSION['csrf']);
$token_ok = $has_csrf && (($_GET['token'] ?? '') === md5((string)$_SESSION['csrf']));

// Doğrudan tarayıcıda açılırsa panel sayfasına yönlendir:
if (!$is_xhr && !$token_ok) {
    header("Location: bot_panel.php");
    exit;
}

// ---- Çıktı başlıkları ----
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ---- Ayarlar ----
$BASE = '/home/proje/public_html/proje1';
$LOG  = $BASE . '/bot.log';

// tail parametresi (son N bayt)
$tail = isset($_GET['tail']) ? (int)$_GET['tail'] : 5000;
if ($tail < 100)    $tail = 100;      // minimum
if ($tail > 50000)  $tail = 50000;    // maksimum

if (!is_file($LOG)) {
    echo "(log yok)";
    exit;
}

$size = (int)@filesize($LOG);
$fp   = @fopen($LOG, "rb");
if (!$fp) {
    echo "(log okunamiyor)";
    exit;
}

if ($size > $tail) {
    // Dosya sonundan tail kadar geri git
    @fseek($fp, -$tail, SEEK_END);
}

$out = @stream_get_contents($fp);
@fclose($fp);

// Güvenli metin çıktısı (zaten text/plain)
echo $out !== false ? $out : "";
