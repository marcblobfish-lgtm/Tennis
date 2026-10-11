<?php
require_once __DIR__ . '/platzdaten_funktionen.php';
require_once __DIR__ . '/session_start_sicher.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tn_antwort(['ok' => false, 'msg' => 'Ungültige Anfrage.'], 405);
}

$eingabe = json_decode((string)file_get_contents('php://input'), true);
$vorname = is_array($eingabe) && is_string($eingabe['vorname'] ?? null) ? trim($eingabe['vorname']) : '';
$nachname = is_array($eingabe) && is_string($eingabe['nachname'] ?? null) ? trim($eingabe['nachname']) : '';
$passwort = is_array($eingabe) && is_string($eingabe['passwort'] ?? null) ? $eingabe['passwort'] : '';

if ($vorname === '' || $nachname === '' || $passwort === '') {
    tn_antwort(['ok' => false, 'msg' => 'Bitte alle Felder ausfüllen.']);
}
if (tn_laenge($vorname) > 80 || tn_laenge($nachname) > 80 || strlen($passwort) > 200) {
    tn_antwort(['ok' => false, 'msg' => 'Vorname, Nachname oder Passwort ist falsch.']);
}

// Punkt 4: nach 5 Fehlversuchen je Name+IP 15 Minuten sperren
$schluessel = tn_rate_schluessel('login', $vorname . ' ' . $nachname . '|' . tn_client_ip());
if (tn_rate_blockiert([$schluessel], 5, 900)) {
    tn_antwort(['ok' => false, 'msg' => 'Zu viele Fehlversuche. Bitte warte 15 Minuten und versuche es dann erneut.'], 429);
}

$datei = __DIR__ . '/platzdaten/accounts.json';
[$konten, $leseOk] = tn_json_lesen($datei);
if (!$leseOk) {
    error_log('tennis: accounts.json nicht lesbar (login)');
    tn_antwort(['ok' => false, 'msg' => 'Serverfehler. Bitte später erneut versuchen.'], 500);
}

$gefunden = null;
foreach ($konten as $k) {
    if (strtolower($k['vorname'] ?? '') === strtolower($vorname) && strtolower($k['nachname'] ?? '') === strtolower($nachname)) {
        $gefunden = $k;
        break;
    }
}

// Kein Konto, kein Passwort (alter Admin-Reset) oder falsches Passwort -> immer dieselbe Meldung
if (!$gefunden || empty($gefunden['passwort_hash']) || !password_verify($passwort, $gefunden['passwort_hash'])) {
    tn_rate_merken([$schluessel]);
    tn_antwort(['ok' => false, 'msg' => 'Vorname, Nachname oder Passwort ist falsch.']);
}

$rolle = $gefunden['rolle'] ?? 'mitglied';
if ($rolle === 'gesperrt') {
    tn_antwort(['ok' => false, 'msg' => 'Dieses Konto wurde gesperrt. Bitte an den Verein wenden.']);
}

tn_rate_vergessen([$schluessel]);

// Punkt 6: neue Session-ID nach erfolgreicher Anmeldung (verhindert Session Fixation)
session_regenerate_id(true);
$_SESSION['benutzer'] = [
    'fullname' => $gefunden['vorname'] . ' ' . $gefunden['nachname'],
    'rolle' => $rolle
];

echo json_encode(['ok' => true, 'fullname' => $_SESSION['benutzer']['fullname'], 'rolle' => $rolle]);
