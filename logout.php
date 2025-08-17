<?php
// /proje1/logout.php
ini_set('default_charset','UTF-8');
mb_internal_encoding('UTF-8');
session_start();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure']??false, $params['httponly']??true);
}
session_destroy();

header('Location: /proje1/login.php');
exit;
