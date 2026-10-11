<?php
require_once __DIR__ . '/platzdaten_funktionen.php';
header('Content-Type: application/json; charset=utf-8');

use PHPMailer\PHPMailer\PHPMailer;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tn_antwort(['ok' => true]);
}

$input = json_decode((string)file_get_contents('php://input'), true);
$email = is_array($input) && is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';

// Aus Sicherheitsgründen antwortet das Skript immer gleich (kein Rückschluss, ob die Adresse existiert)
if ($email === '' || strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    tn_antwort(['ok' => true]);
}

// Punkt 4: max. N Reset-Mails pro Stunde je Adresse und je IP
$max = max(1, (int)tn_cfg('max_reset_per_hour', 3));
$keys = [tn_rate_schluessel('reset-mail', $email), tn_rate_schluessel('reset-ip', tn_client_ip())];
if (tn_rate_blockiert($keys, $max, 3600)) {
    tn_antwort(['ok' => true]);
}
tn_rate_merken($keys);

// Zugangsdaten kommen NUR aus der Config (außerhalb des Web-Ordners)
$smtpHost = (string)tn_cfg('smtp_host');
$smtpPort = (int)tn_cfg('smtp_port', 587);
$smtpUser = tn_cfg('smtp_user');
$smtpPass = tn_cfg('smtp_pass');
$absender = tn_cfg('mail_from_address');
$baseUrl = rtrim((string)tn_cfg('base_url'), '/');
if ($smtpHost === '' || tn_ist_platzhalter($smtpUser) || tn_ist_platzhalter($smtpPass) || tn_ist_platzhalter($absender) || $baseUrl === '') {
    error_log('tennis: Mail-Konfiguration unvollständig (config.php), keine Reset-Mail versendet');
    tn_antwort(['ok' => true]);
}

$autoloads = [__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'];
$autoloadGefunden = false;
foreach ($autoloads as $al) {
    if (is_file($al)) { require_once $al; $autoloadGefunden = true; break; }
}
if (!$autoloadGefunden) {
    error_log('tennis: vendor/autoload.php (PHPMailer) nicht gefunden');
    tn_antwort(['ok' => true]);
}

// Token unter Sperre setzen (Punkte 8 + 9), Mail erst danach senden
$ziel = null;
tn_mit_sperre(function () use ($email, &$ziel) {
    $file = __DIR__ . '/platzdaten/accounts.json';
    if (!file_exists($file)) { return ['ok' => true]; }
    [$accounts, $leseOk] = tn_json_lesen($file);
    if (!$leseOk) {
        error_log('tennis: accounts.json nicht lesbar (Reset)');
        return ['ok' => false];
    }
    foreach ($accounts as &$acc) {
        if (isset($acc['email']) && strtolower(trim($acc['email'])) === $email) {
            $token = bin2hex(random_bytes(32));
            $acc['reset_token'] = $token;
            $acc['reset_expires_at'] = date('Y-m-d H:i:s', strtotime('+30 minutes'));
            $ziel = ['token' => $token, 'vorname' => (string)($acc['vorname'] ?? ''), 'nachname' => (string)($acc['nachname'] ?? '')];
            break;
        }
    }
    unset($acc);
    if ($ziel !== null && !tn_json_schreiben($file, $accounts)) {
        $ziel = null;
        return ['ok' => false];
    }
    return ['ok' => true];
});

if ($ziel !== null) {
    // Punkt (optional): feste Basis-URL aus der Config statt HTTP_HOST
    $resetLink = $baseUrl . '/passwort_zuruecksetzen.php?token=' . $ziel['token'];

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtpPort;
        $mail->Timeout    = 15;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($absender, (string)tn_cfg('mail_from_name', 'SV Niederroth'));
        $mail->addAddress($email, $ziel['vorname'] . ' ' . $ziel['nachname']);

        $mail->isHTML(false);
        $mail->Subject = 'Passwort zuruecksetzen - SV Niederroth';
        $mail->Body    = "Hallo " . $ziel['vorname'] . ",\n\ndu hast angefordert, dein Passwort zurückzusetzen.\nKlicke auf den folgenden Link, um ein neues Passwort zu vergeben:\n\n" . $resetLink . "\n\nDer Link ist 30 Minuten gültig.\nWenn du das nicht angefordert hast, kannst du diese E-Mail ignorieren.\n\nGruß\nSV Niederroth";

        $mail->send();
    } catch (Throwable $e) {
        error_log('tennis: Reset-Mail fehlgeschlagen: ' . $mail->ErrorInfo);
    }
}

tn_antwort(['ok' => true]);
