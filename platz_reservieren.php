<?php
require_once __DIR__ . '/platzdaten_funktionen.php';
header('Content-Type: application/json; charset=utf-8');
// Punkt 1: keine CORS-Header mehr (Access-Control-Allow-Origin: * entfernt)

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tn_antwort(['ok' => false, 'msg' => 'Ungültige Anfrage.'], 405);
}

// Punkt 1: Login-Pflicht
$benutzer = tn_login_pflicht();
$istAdmin = $benutzer['rolle'] === 'admin';

const TN_STUNDE_MIN = 9;
const TN_STUNDE_MAX = 19;
const TN_PLATZ_MIN = 1;
const TN_PLATZ_MAX = 3;
const TN_MAX_TAGE_VORAUS = 60;
const TN_MAX_ZEILEN = 40;
const TN_MAX_NAME = 80;
const TN_MAX_SPIELER2 = 36;

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['zeilen']) || !is_array($input['zeilen']) || count($input['zeilen']) === 0) {
    tn_antwort(['ok' => false, 'msg' => 'Keine Daten empfangen.']);
}
if (count($input['zeilen']) > TN_MAX_ZEILEN) {
    tn_antwort(['ok' => false, 'msg' => 'Zu viele Zeitfenster auf einmal (maximal ' . TN_MAX_ZEILEN . ').']);
}

// Sonderreservierung: nur Admin, der Server prüft die Rolle
$sonder = !empty($input['sonder']);
if ($sonder && !$istAdmin) {
    tn_antwort(['ok' => false, 'msg' => 'Sonderreservierungen sind nur für Administratoren möglich.'], 403);
}

$zeitzone = new DateTimeZone('Europe/Berlin');
$heuteTag = (new DateTimeImmutable('today', $zeitzone))->format('Y-m-d');
$letzterTag = (new DateTimeImmutable('today', $zeitzone))->modify('+' . TN_MAX_TAGE_VORAUS . ' days')->format('Y-m-d');

$zeilen = $input['zeilen'];

$ergebnis = tn_mit_sperre(function () use ($zeilen, $benutzer, $sonder, $heuteTag, $letzterTag) {
    $dir = __DIR__ . '/platzdaten';
    $groupId = uniqid('grp_', true);
    $geladen = [];    // Tag => vorhandene Buchungen aus der Datei
    $neuNachTag = []; // Tag => neue Zeilen dieser Anfrage

    foreach ($zeilen as $z) {
        if (!is_array($z)) {
            return ['ok' => false, 'msg' => 'Ungültige Reservierung.'];
        }

        // Datum: Format, echtes Datum, ab heute bis max. 60 Tage voraus
        $tag = is_string($z['day_date'] ?? null) ? $z['day_date'] : '';
        if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $tag, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return ['ok' => false, 'msg' => 'Ungültiges Datum.'];
        }
        if ($tag < $heuteTag) {
            return ['ok' => false, 'msg' => 'Reservierungen in der Vergangenheit sind nicht möglich.'];
        }
        if ($tag > $letzterTag) {
            return ['ok' => false, 'msg' => 'Reservierungen sind höchstens ' . TN_MAX_TAGE_VORAUS . ' Tage im Voraus möglich.'];
        }

        // Stunde und Platz
        $stunde = filter_var($z['start_hour'] ?? null, FILTER_VALIDATE_INT);
        $platz = filter_var($z['court'] ?? null, FILTER_VALIDATE_INT);
        if ($stunde === false || $stunde < TN_STUNDE_MIN || $stunde > TN_STUNDE_MAX
            || $platz === false || $platz < TN_PLATZ_MIN || $platz > TN_PLATZ_MAX) {
            return ['ok' => false, 'msg' => 'Ungültige Reservierung (Uhrzeit oder Platz).'];
        }

        // Name: serverseitig erzwingen, nicht dem Client vertrauen
        $name = is_string($z['name'] ?? null) ? trim($z['name']) : '';
        if ($sonder) {
            if ($name === '' || tn_laenge($name) > TN_MAX_NAME || tn_hat_verbotene_zeichen($name)) {
                return ['ok' => false, 'msg' => 'Ungültige Bezeichnung (max. ' . TN_MAX_NAME . ' Zeichen, ohne < oder >).'];
            }
        } else {
            $prefix = $benutzer['fullname'] . ' & ';
            if (strpos($name, $prefix) !== 0) {
                return ['ok' => false, 'msg' => 'Ungültiger Name. Normale Reservierungen laufen immer unter deinem eigenen Namen.'];
            }
            $spieler2 = trim(substr($name, strlen($prefix)));
            if ($spieler2 === '' || tn_laenge($spieler2) > TN_MAX_SPIELER2 || tn_hat_verbotene_zeichen($spieler2)) {
                return ['ok' => false, 'msg' => 'Ungültiger Name des Mitspielers (max. ' . TN_MAX_SPIELER2 . ' Zeichen, ohne < oder >).'];
            }
            $name = $prefix . $spieler2;
        }

        if (!isset($geladen[$tag])) {
            [$vorhanden, $leseOk] = tn_json_lesen($dir . '/' . $tag . '.json');
            if (!$leseOk) {
                error_log('tennis: Tagesdatei nicht lesbar: ' . $tag);
                return ['ok' => false, 'msg' => 'Die Tagesdaten sind gerade nicht lesbar. Bitte später erneut versuchen.'];
            }
            $geladen[$tag] = $vorhanden;
            $neuNachTag[$tag] = [];
        }

        // Gegen bereits gespeicherte Buchungen prüfen
        foreach ($geladen[$tag] as $ex) {
            if ((int)($ex['start_hour'] ?? -1) === $stunde && (int)($ex['court'] ?? -1) === $platz) {
                return ['ok' => false, 'msg' => "Platz $platz um $stunde:00 Uhr ist bereits belegt."];
            }
        }
        // Gegen bereits in dieser Anfrage vorgemerkte Zeilen prüfen
        foreach ($neuNachTag[$tag] as $n) {
            if ($n['start_hour'] === $stunde && $n['court'] === $platz) {
                return ['ok' => false, 'msg' => "Platz $platz um $stunde:00 Uhr ist bereits belegt."];
            }
        }

        $neuNachTag[$tag][] = [
            'id' => uniqid('res_', true),
            'group_id' => $groupId,
            'day_date' => $tag,
            'start_hour' => $stunde,
            'court' => $platz,
            'name' => $name,
            'ersteller' => $benutzer['fullname']
        ];
    }

    foreach ($neuNachTag as $tag => $neueZeilen) {
        $alle = array_merge($geladen[$tag], $neueZeilen);
        if (!tn_json_schreiben($dir . '/' . $tag . '.json', $alle)) {
            return ['ok' => false, 'msg' => 'Konnte nicht speichern (Schreibrechte prüfen).'];
        }
    }

    return ['ok' => true];
});

tn_antwort_ergebnis($ergebnis);
