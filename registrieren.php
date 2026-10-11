<?php
require_once __DIR__ . '/platzdaten_funktionen.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tn_antwort(['ok' => false, 'msg' => 'Ungültige Anfrage.'], 405);
}

$eingabe = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($eingabe)) { $eingabe = []; }
$feld = function (string $name) use ($eingabe): string {
    return is_string($eingabe[$name] ?? null) ? $eingabe[$name] : '';
};

// ---- Punkt 4: Registrierung nur mit Vereinscode ----------------------------
$sollCode = tn_cfg('club_code', '');
if (tn_ist_platzhalter($sollCode)) {
    error_log('tennis: club_code ist nicht konfiguriert, Registrierung gesperrt');
    tn_antwort(['ok' => false, 'msg' => 'Die Registrierung ist derzeit nicht möglich. Bitte wende dich an den Verein.'], 503);
}

$codeKey = tn_rate_schluessel('code', tn_client_ip());
if (tn_rate_blockiert([$codeKey], 5, 900)) {
    tn_antwort(['ok' => false, 'msg' => 'Zu viele falsche Versuche. Bitte warte 15 Minuten.'], 429);
}
if (!hash_equals(tn_klein(trim($sollCode)), tn_klein(trim($feld('vereinscode'))))) {
    tn_rate_merken([$codeKey]);
    tn_antwort(['ok' => false, 'msg' => 'Der Vereinscode ist falsch. Du findest ihn im Clubhaus.']);
}

// ---- Eingaben prüfen -------------------------------------------------------
$vorname = trim($feld('vorname'));
$nachname = trim($feld('nachname'));
$email = strtolower(trim($feld('email')));
$passwort = $feld('passwort');

if ($vorname === '' || $nachname === '' || $email === '' || $passwort === '') {
    tn_antwort(['ok' => false, 'msg' => 'Bitte Vorname, Nachname, E-Mail und ein Passwort angeben.']);
}
if (tn_hat_verbotene_zeichen($vorname) || tn_hat_verbotene_zeichen($nachname)) {
    tn_antwort(['ok' => false, 'msg' => 'Der Name enthält unzulässige Zeichen (z. B. < oder >).']);
}
// Vor- und Nachname zusammen (inkl. Leerzeichen) höchstens 40 Zeichen
if (tn_laenge($vorname) + 1 + tn_laenge($nachname) > 40) {
    tn_antwort(['ok' => false, 'msg' => 'Vor- und Nachname dürfen zusammen höchstens 40 Zeichen lang sein.']);
}
if (strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    tn_antwort(['ok' => false, 'msg' => 'Bitte eine gültige E-Mail-Adresse angeben.']);
}
if (tn_laenge($passwort) < 8) {
    tn_antwort(['ok' => false, 'msg' => 'Das Passwort muss mindestens 8 Zeichen lang sein.']);
}
if (strlen($passwort) > 72) {
    tn_antwort(['ok' => false, 'msg' => 'Das Passwort darf höchstens 72 Zeichen lang sein.']);
}

// ---- Konto anlegen (unter Sperre, Punkte 8 + 9) ----------------------------
$ergebnis = tn_mit_sperre(function () use ($vorname, $nachname, $email, $passwort) {
    $datei = __DIR__ . '/platzdaten/accounts.json';

    // Punkt 8: Existiert die Datei, ist aber nicht lesbar/dekodierbar -> ABBRECHEN
    [$konten, $leseOk] = tn_json_lesen($datei);
    if (!$leseOk) {
        error_log('tennis: accounts.json nicht lesbar, Registrierung abgebrochen');
        return ['ok' => false, 'msg' => 'Die Kontodaten sind gerade nicht lesbar. Bitte später erneut versuchen oder den Verein informieren.'];
    }

    foreach ($konten as $k) {
        if (strtolower($k['vorname'] ?? '') === strtolower($vorname) && strtolower($k['nachname'] ?? '') === strtolower($nachname)) {
            return ['ok' => false, 'msg' => 'Ein Konto mit diesem Vor- und Nachnamen existiert bereits.'];
        }
        if (isset($k['email']) && strtolower($k['email']) === $email) {
            return ['ok' => false, 'msg' => 'Ein Konto mit dieser E-Mail-Adresse existiert bereits.'];
        }
    }

    // Die Regel "erster Account = Admin" gibt es nicht mehr. Weitere Admins
    // werden im Admin-Bereich vergeben.
    $konten[] = [
        'vorname' => $vorname,
        'nachname' => $nachname,
        'email' => $email,
        'passwort_hash' => password_hash($passwort, PASSWORD_DEFAULT),
        'rolle' => 'mitglied'
    ];

    if (!tn_json_schreiben($datei, $konten)) {
        return ['ok' => false, 'msg' => 'Konnte Konto nicht speichern (Schreibrechte prüfen).'];
    }
    return ['ok' => true];
});

tn_antwort_ergebnis($ergebnis);
