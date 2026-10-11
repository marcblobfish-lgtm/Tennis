<?php
// ============================================================================
// Gemeinsame Hilfsfunktionen: Konfiguration, Dateisperren (flock), Login-
// Pflicht, Rate-Limit (Sicherheitsbericht: Punkte 1, 4, 6, 7, 8, 9)
// ============================================================================
require_once __DIR__ . '/tn_config.php';

// Fehler nur ins Log, nie in die Antwort (sonst ist das JSON kaputt)
ini_set('display_errors', '0');

/** Liest einen Wert aus der Konfiguration (/etc/tennis-niederroth/config.php bzw. config.php). */
function tn_cfg(string $key, $default = '') {
    global $TN_CONFIG;
    return $TN_CONFIG[$key] ?? $default;
}

/** true, wenn ein Konfigurationswert leer oder noch ein Platzhalter ("HIER-...") ist. */
function tn_ist_platzhalter($wert): bool {
    return !is_string($wert) || trim($wert) === '' || strpos($wert, 'HIER-') !== false;
}

/** Antwort als JSON ausgeben und beenden. */
function tn_antwort(array $daten, int $status = 200): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Ergebnis aus tn_mit_sperre() ausgeben (optionaler Schlüssel 'status' = HTTP-Code). */
function tn_antwort_ergebnis(array $ergebnis): void {
    $status = (int)($ergebnis['status'] ?? 200);
    unset($ergebnis['status']);
    tn_antwort($ergebnis, $status);
}

/** Zeichenanzahl (UTF-8-sicher, auch ohne mbstring). */
function tn_laenge(string $s): int {
    if (function_exists('mb_strlen')) { return mb_strlen($s, 'UTF-8'); }
    return (int)preg_match_all('/./us', $s);
}

function tn_klein(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/** true, wenn der Text < oder > oder Steuerzeichen enthält. */
function tn_hat_verbotene_zeichen(string $s): bool {
    return (bool)preg_match('/[<>\x00-\x1F\x7F]/', $s);
}

/** IP des Besuchers. Absichtlich NUR REMOTE_ADDR (X-Forwarded-For ist fälschbar). */
function tn_client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unbekannt');
}

/**
 * Startet die sichere Session und verlangt eine Anmeldung.
 * Liefert ['fullname' => ..., 'rolle' => ...] oder beendet mit 401.
 */
function tn_login_pflicht(): array {
    require_once __DIR__ . '/session_start_sicher.php';
    if (empty($_SESSION['benutzer']['fullname'])) {
        tn_antwort(['ok' => false, 'msg' => 'Bitte melde dich an.', 'error' => 'Nicht angemeldet.'], 401);
    }
    return [
        'fullname' => (string)$_SESSION['benutzer']['fullname'],
        'rolle'    => (string)($_SESSION['benutzer']['rolle'] ?? 'mitglied'),
    ];
}

/** Wie tn_login_pflicht(), zusätzlich muss die Rolle "admin" sein (sonst 403). */
function tn_admin_pflicht(): array {
    $benutzer = tn_login_pflicht();
    if ($benutzer['rolle'] !== 'admin') {
        tn_antwort(['ok' => false, 'msg' => 'Kein Zugriff.', 'error' => 'Kein Zugriff.'], 403);
    }
    return $benutzer;
}

/**
 * Führt $ablauf mit exklusiver Sperre (flock auf platzdaten/.schreib.lock) aus.
 * Verhindert, dass beim parallelen Buchen/Registrieren Daten verloren gehen.
 * $ablauf muss ein Array zurückgeben, z. B. ['ok' => true]
 * oder ['ok' => false, 'msg' => '...'].
 */
function tn_mit_sperre(callable $ablauf) {
    $dir = __DIR__ . '/platzdaten';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $lock = $dir . '/.schreib.lock';
    $fp = @fopen($lock, 'c');
    if ($fp === false) {
        return ['ok' => false, 'msg' => 'Sperre konnte nicht geöffnet werden (Schreibrechte prüfen).'];
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return ['ok' => false, 'msg' => 'Sperre konnte nicht vergeben werden, bitte erneut versuchen.'];
    }
    try {
        $ergebnis = $ablauf();
    } catch (Throwable $e) {
        error_log('tennis: ' . $e->getMessage());
        $ergebnis = ['ok' => false, 'msg' => 'Serverfehler.'];
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return is_array($ergebnis) ? $ergebnis : ['ok' => true];
}

/**
 * Liest eine JSON-Datei sicher.
 * - Datei fehlt:        [[], true]   (kein Fehler)
 * - nicht lesbar/ungültig: [null, false]  -> ABBRUCH, nicht als leer behandeln
 *                           (sonst würde accounts.json z. B. überschrieben,
 *                            siehe Bericht Punkt 8)
 */
function tn_json_lesen(string $pfad) {
    if (!file_exists($pfad)) { return [[], true]; }
    $inhalt = @file_get_contents($pfad);
    if ($inhalt === false) { return [null, false]; }
    $daten = json_decode($inhalt, true);
    if (!is_array($daten)) { return [null, false]; }
    return [$daten, true];
}

/** Schreibt JSON atomar (temporäre Datei + rename). Gibt true bei Erfolg zurück. */
function tn_json_schreiben(string $pfad, array $daten): bool {
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) { return false; }
    $tmp = $pfad . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json) === false) { @unlink($tmp); return false; }
    if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
    return true;
}

// ----------------------------------------------------------------------------
// Rate-Limit (Punkt 4): Zähler in platzdaten/ratelimit.dat, eigene Sperre
// (.rate.lock), damit sie nie mit tn_mit_sperre() kollidiert. Schlüssel sind
// Hashes, es liegen also weder E-Mail-Adressen noch IPs im Klartext vor.
// ----------------------------------------------------------------------------
function tn_rate_schluessel(string $art, string $wert): string {
    return $art . ':' . hash('sha256', tn_klein(trim($wert)));
}

function tn_rate_zugriff(callable $f) {
    $dir = __DIR__ . '/platzdaten';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $fp = @fopen($dir . '/.rate.lock', 'c');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        if ($fp) { fclose($fp); }
        error_log('tennis: Rate-Limit-Sperre nicht verfügbar');
        $leer = [];
        return $f($leer);
    }
    $pfad = $dir . '/ratelimit.dat';
    $daten = [];
    if (is_file($pfad)) {
        $d = json_decode((string)@file_get_contents($pfad), true);
        if (is_array($d)) { $daten = $d; }
    }
    $grenze = time() - 86400; // alles älter als 1 Tag wird verworfen
    foreach ($daten as $k => $liste) {
        $liste = array_values(array_filter((array)$liste, function ($t) use ($grenze) { return $t > $grenze; }));
        if ($liste) { $daten[$k] = $liste; } else { unset($daten[$k]); }
    }
    $ergebnis = $f($daten);
    @file_put_contents($pfad, json_encode($daten));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ergebnis;
}

/** true, wenn für einen der Schlüssel in den letzten $fenster Sekunden $max oder mehr Einträge liegen. */
function tn_rate_blockiert(array $keys, int $max, int $fenster): bool {
    return (bool)tn_rate_zugriff(function (array &$daten) use ($keys, $max, $fenster) {
        $grenze = time() - $fenster;
        foreach ($keys as $k) {
            $n = 0;
            foreach (($daten[$k] ?? []) as $t) { if ($t > $grenze) { $n++; } }
            if ($n >= $max) { return true; }
        }
        return false;
    });
}

function tn_rate_merken(array $keys): void {
    tn_rate_zugriff(function (array &$daten) use ($keys) {
        foreach ($keys as $k) { $daten[$k][] = time(); }
        return true;
    });
}

function tn_rate_vergessen(array $keys): void {
    tn_rate_zugriff(function (array &$daten) use ($keys) {
        foreach ($keys as $k) { unset($daten[$k]); }
        return true;
    });
}
