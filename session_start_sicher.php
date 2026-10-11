<?php
// ============================================================================
// Gemeinsame, sichere Session-Start (Sicherheitsbericht, Punkt 6).
// Anstelle von session_start() in jeder Datei:
//     require __DIR__ . '/session_start_sicher.php';
//
// WICHTIG: Bewusst NICHT in der php.ini setzen – auf demselben Server laufen
// weitere Seiten (u. a. Port 6363 per HTTP), bei denen das Secure-Flag die
// Anmeldung kaputt machen könnte.
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    
    // Secure-Flag nur setzen, wenn über HTTPS zugegriffen wird
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
              || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
              || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
    
    session_set_cookie_params([
        'path'     => '/',
        'secure'   => $isHttps,   // Cookie nur über HTTPS (automatisch erkannt)
        'httponly' => true,        // nicht per JS lesbar (XSS-Schutz)
        'samesite' => 'Lax',       // Schutz vor Cross-Site-Anfragen (CSRF-Entlastung)
    ]);
    session_start();
}