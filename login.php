<?php
// /proje1/login.php
ini_set('default_charset','UTF-8');
mb_internal_encoding('UTF-8');
session_start();

$USERS_FILE = __DIR__.'/.bot_users';
$PANEL_URL  = '/proje1/bot_panel.php';
$SESSION_KEY_AUTH = 'auth';
$SESSION_KEY_USER = 'auth_user';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

if (!empty($_SESSION[$SESSION_KEY_AUTH])) {
  $next = (string)($_GET['next'] ?? $PANEL_URL);
  header('Location: '.$next);
  exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $posted_csrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($csrf, $posted_csrf)) {
    $err = 'Oturum süreniz dolmuş olabilir. Lütfen tekrar deneyin.';
  } else {
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    $ok = false;
    if (is_file($USERS_FILE) && is_readable($USERS_FILE)) {
      $fh = fopen($USERS_FILE, 'r');
      if ($fh) {
        while (($line = fgets($fh)) !== false) {
          $line = trim($line);
          if ($line === '' || strpos($line, ':') === false) continue;
          [$u,$hash] = explode(':', $line, 2);
          if (hash_equals($u, $user) && password_verify($pass, $hash)) {
            $ok = true;
            break;
          }
        }
        fclose($fh);
      }
    }

    if ($ok) {
      $_SESSION[$SESSION_KEY_AUTH] = true;
      $_SESSION[$SESSION_KEY_USER] = $user;
      $_SESSION['csrf'] = bin2hex(random_bytes(16)); // yenile
      $next = (string)($_POST['next'] ?? $PANEL_URL);
      header('Location: '.$next);
      exit;
    } else {
      $err = 'Kullanıcı adı veya şifre hatalı.';
    }
  }
}

$next = (string)($_GET['next'] ?? $PANEL_URL);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Giriş Yap</title>
<link rel="icon" type="image/png" href="/proje1/img/favicon.svg">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  /* Kaymaları engelleyen temel ayarlar */
  *,*::before,*::after{box-sizing:border-box}
  html,body{height:100%}
  body{
    font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
    -webkit-font-smoothing:antialiased;
    margin:0;
    min-height:100dvh;               /* mobilde güvenli yükseklik */
    display:grid;
    place-items:center;
    background:#0b1021;
    color:#e5e7eb;
    scrollbar-gutter: stable both-edges; /* scrollbar alanını rezerve et -> layout kayması yok */
  }
  .card{
    background:#111827;
    padding:24px;
    border-radius:12px;
    box-shadow:0 10px 30px rgba(0,0,0,.35);
    width:min(420px,92vw);
  }
  h1{margin:0 0 16px 0;font-size:20px}
  label{display:block;margin:12px 0 6px}
  input{
    width:100%;
    padding:12px 14px;
    border:1px solid #374151;
    border-radius:8px;
    background:#0f172a;
    color:#e5e7eb;
    outline:none;
  }
  input:focus{border-color:#2563eb}
  button{
    width:100%;
    padding:12px 14px;
    border:1px solid #2563eb;
    background:#2563eb;
    color:#fff;
    border-radius:8px;
    cursor:pointer;
    margin-top:16px;
    font-weight:600;
  }
  .err{
    background:#7f1d1d;
    border:1px solid #dc2626;
    color:#fff;
    padding:10px 12px;
    border-radius:8px;
  }
  .msg{min-height:44px; margin-bottom:12px;} /* Hata alanı için sabit yükseklik -> zıplama yok */
  small{color:#9ca3af}
  a{color:#93c5fd;text-decoration:none}
</style>
</head>
<body>
  <form class="card" method="post" autocomplete="off">
    <h1>Bot Panel Giriş</h1>

    <div class="msg">
      <?php if ($err): ?><div class="err">⚠️ <?=h($err)?></div><?php endif; ?>
    </div>

    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
    <input type="hidden" name="next" value="<?=h($next)?>">

    <label for="u">Kullanıcı Adı</label>
    <input id="u" name="username" required>

    <label for="p">Şifre</label>
    <input id="p" name="password" type="password" required>

    <button type="submit">Giriş Yap</button>
    <p><small>🛈 Kullanıcı bilgileriniz güvenli bir dosyada (hash’li) saklanır.</small></p>
  </form>
</body>
</html>
