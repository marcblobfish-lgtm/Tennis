<?php
require_once __DIR__ . '/platzdaten_funktionen.php';
header('Content-Type: application/json; charset=utf-8');
// Punkt 1: keine CORS-Header mehr

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tn_antwort(['ok' => false, 'msg' => 'Ungültige Anfrage.'], 405);
}

// Punkt 1: Login-Pflicht
$benutzer = tn_login_pflicht();
$istAdmin = $benutzer['rolle'] === 'admin';

$eingabe = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($eingabe)) { $eingabe = $_POST; }

$tag = is_string($eingabe['day_date'] ?? null) ? $eingabe['day_date'] : (is_string($eingabe['datum'] ?? null) ? $eingabe['datum'] : '');
$id = is_string($eingabe['id'] ?? null) ? $eingabe['id'] : '';

if ($id === '' || strlen($id) > 100 || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $tag)) {
    tn_antwort(['ok' => false, 'msg' => 'Fehlende oder ungültige Parameter zum Löschen.']);
}

$ergebnis = tn_mit_sperre(function () use ($tag, $id, $benutzer, $istAdmin) {
    $pfad = __DIR__ . '/platzdaten/' . $tag . '.json';
    if (!file_exists($pfad)) {
        return ['ok' => true]; // nichts zu löschen
    }
    [$alle, $leseOk] = tn_json_lesen($pfad);
    if (!$leseOk) {
        error_log('tennis: Tagesdatei nicht lesbar (löschen): ' . $tag);
        return ['ok' => false, 'msg' => 'Die Tagesdaten sind gerade nicht lesbar. Bitte später erneut versuchen.'];
    }

    $ziel = null;
    foreach ($alle as $b) {
        if (($b['id'] ?? '') === $id) { $ziel = $b; break; }
    }
    if ($ziel === null) {
        return ['ok' => true]; // existiert nicht (mehr)
    }

    // Nur Ersteller oder Admin. Ältere Buchungen ohne 'ersteller': nur Admin.
    $ersteller = (string)($ziel['ersteller'] ?? '');
    $darf = $istAdmin || ($ersteller !== '' && $ersteller === $benutzer['fullname']);
    if (!$darf) {
        return ['ok' => false, 'msg' => 'Du darfst nur Reservierungen löschen, die du selbst angelegt hast.', 'status' => 403];
    }

    $alle = array_values(array_filter($alle, function ($b) use ($id) {
        return ($b['id'] ?? '') !== $id;
    }));

    if (!tn_json_schreiben($pfad, $alle)) {
        return ['ok' => false, 'msg' => 'Konnte Datei nicht speichern (Schreibrechte prüfen).'];
    }
    return ['ok' => true];
});

tn_antwort_ergebnis($ergebnis);
