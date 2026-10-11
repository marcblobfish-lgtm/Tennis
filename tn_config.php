<?php
// ============================================================================
// tn_config.php – lädt die Projekt-Konfiguration.
// Reihenfolge:
//   1) /etc/tennis-niederroth/config.php  (wird von einrichten.sh angelegt:
//      außerhalb des Web-Ordners, Besitzer marc:www-data, Rechte 640)
//   2) config.php neben dieser Datei      (lokaler Fallback, .gitignore)
// ============================================================================
$TN_CONFIG = array_merge([
    'club_code'          => '',
    'smtp_host'          => 'smtp.mail.me.com',
    'smtp_port'          => 587,
    'smtp_user'          => '',
    'smtp_pass'          => '',
    'mail_from_address'  => '',
    'mail_from_name'     => 'SV Niederroth',
    'base_url'           => 'https://tennis-niederroth.ddns.net',
    'max_reset_per_hour' => 3,
], (function () {
    $pfade = ['/etc/tennis-niederroth/config.php', __DIR__ . '/config.php'];
    foreach ($pfade as $pfad) {
        if (is_file($pfad) && is_readable($pfad)) {
            $cfg = @include $pfad;
            if (is_array($cfg)) { return $cfg; }
        }
    }
    return [];
})());
