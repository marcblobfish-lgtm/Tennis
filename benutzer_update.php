<?php
require_once __DIR__ . '/platzdaten_funktionen.php';
header('Content-Type: application/json; charset=utf-8');
tn_admin_pflicht();

$input = json_decode((string)file_get_contents('php://input'), true);
$fullname = is_array($input) && is_string($input['fullname'] ?? null) ? trim($input['fullname']) : '';
$neueRolle = is_array($input) && is_string($input['rolle'] ?? null) ? $input['rolle'] : '';
$erlaubteRollen = ['mitglied', 'admin', 'gesperrt'];

if ($fullname === '' || !in_array($neueRolle, $erlaubteRollen, true)) {
    tn_antwort(['ok' => false, 'msg' => 'Ungültige Daten.']);
}

$ergebnis = tn_mit_sperre(function () use ($fullname, $neueRolle) {
    $file = __DIR__ . '/platzdaten/accounts.json';
    if (!file_exists($file)) {
        return ['ok' => false, 'msg' => 'Keine Kontendatei gefunden.'];
    }
    [$accounts, $leseOk] = tn_json_lesen($file);
    if (!$leseOk) {
        return ['ok' => false, 'msg' => 'Kontodaten nicht lesbar.'];
    }

    $geaendert = false;
    foreach ($accounts as &$acc) {
        $accFullname = trim(($acc['vorname'] ?? '') . ' ' . ($acc['nachname'] ?? ''));
        if (strtolower($accFullname) === strtolower($fullname)) {
            $acc['rolle'] = $neueRolle;
            $geaendert = true;
        }
    }
    unset($acc);

    if (!$geaendert) {
        return ['ok' => false, 'msg' => 'Benutzer nicht gefunden.'];
    }

    // Schutz: es muss mindestens ein Administrator übrig bleiben
    $admins = 0;
    foreach ($accounts as $a) { if (($a['rolle'] ?? '') === 'admin') $admins++; }
    if ($admins === 0) {
        return ['ok' => false, 'msg' => 'Es muss mindestens ein Administrator übrig bleiben.'];
    }

    if (!tn_json_schreiben($file, $accounts)) {
        return ['ok' => false, 'msg' => 'Konnte nicht speichern (Schreibrechte prüfen).'];
    }
    return ['ok' => true];
});

tn_antwort_ergebnis($ergebnis);
