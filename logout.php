<?php
require_once __DIR__ . '/session_start_sicher.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
session_destroy();
header('Location: login.html');
exit;
