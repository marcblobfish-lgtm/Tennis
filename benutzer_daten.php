<?php
require_once __DIR__ . '/platzdaten_funktionen.php';
header('Content-Type: application/json; charset=utf-8');
tn_admin_pflicht();

[$konten, $leseOk] = tn_json_lesen(__DIR__ . '/platzdaten/accounts.json');
if (!$leseOk) {
    tn_antwort(['error' => 'Kontodaten nicht lesbar.'], 500);
}

$users = [];
foreach ($konten as $k) {
    $fullname = trim(($k['vorname'] ?? '') . ' ' . ($k['nachname'] ?? ''));
    $users[] = [
        'id' => $fullname,
        'fullname' => $fullname,
        'rolle' => $k['rolle'] ?? 'mitglied'
    ];
    // Wichtig: passwort_hash wird hier bewusst NICHT mit ausgegeben.
}

echo json_encode($users, JSON_UNESCAPED_UNICODE);
