<?php
require_once __DIR__ . '/platzdaten_funktionen.php';

$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$fehler = '';
$erfolg = false;
$file = __DIR__ . '/platzdaten/accounts.json';

function tn_reset_konto_finden(array $accounts, string $token): ?int {
    if (strlen($token) !== 64) { return null; }
    $now = date('Y-m-d H:i:s');
    foreach ($accounts as $index => $acc) {
        if (!empty($acc['reset_token']) && hash_equals((string)$acc['reset_token'], $token)) {
            if (isset($acc['reset_expires_at']) && $acc['reset_expires_at'] > $now) {
                return $index;
            }
            return null;
        }
    }
    return null;
}

[$accounts, $leseOk] = tn_json_lesen($file);
$userIndex = $leseOk ? tn_reset_konto_finden($accounts, $token) : null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $userIndex !== null) {
    $neuesPw = is_string($_POST['passwort'] ?? null) ? $_POST['passwort'] : '';
    $neuesPwWdh = is_string($_POST['passwort_wdh'] ?? null) ? $_POST['passwort_wdh'] : '';

    if (tn_laenge($neuesPw) < 8) {
        $fehler = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif (strlen($neuesPw) > 72) {
        $fehler = 'Das Passwort darf höchstens 72 Zeichen lang sein.';
    } elseif ($neuesPw !== $neuesPwWdh) {
        $fehler = 'Die Passwörter stimmen nicht überein.';
    } else {
        $res = tn_mit_sperre(function () use ($file, $token, $neuesPw) {
            [$konten, $ok] = tn_json_lesen($file);
            if (!$ok) { return ['ok' => false, 'msg' => 'Fehler beim Lesen der Kontodaten.']; }
            $i = tn_reset_konto_finden($konten, $token);
            if ($i === null) { return ['ok' => false, 'msg' => 'Der Link ist inzwischen abgelaufen.']; }
            $konten[$i]['passwort_hash'] = password_hash($neuesPw, PASSWORD_DEFAULT);
            $konten[$i]['reset_token'] = null;
            $konten[$i]['reset_expires_at'] = null;
            if (!tn_json_schreiben($file, $konten)) {
                return ['ok' => false, 'msg' => 'Fehler beim Speichern in der Datenbank.'];
            }
            return ['ok' => true];
        });
        if (!empty($res['ok'])) { $erfolg = true; } else { $fehler = (string)($res['msg'] ?? 'Fehler.'); }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<meta name="referrer" content="no-referrer">
<title>SV Niederroth – Neues Passwort vergeben</title>
<link rel="stylesheet" href="fonts.css">
<style>
  body{margin:0;font-family:'Inter','Segoe UI',Arial,sans-serif;background:#f6f5f2;color:#1c1c1e;display:flex;justify-content:center;align-items:center;height:100vh;}
  .karte{background:#fff;border:1px solid #e2dfd8;padding:30px;width:100%;max-width:400px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
  .form-gruppe{margin-bottom:16px;}
  label{display:block;font-size:13px;font-weight:700;text-transform:uppercase;margin-bottom:6px;}
  input{width:100%;padding:12px;border:1px solid #c9c6bf;border-radius:4px;box-sizing:border-box;font-size:16px;}
  button{width:100%;padding:14px;background:#1c1c1e;color:#fff;border:none;font-weight:700;font-size:16px;cursor:pointer;}
  button:hover{background:#000;}
  .msg{padding:12px;margin-bottom:16px;font-size:14px;}
  .error{background:#fde8e8;color:#9b1c1c;}
  .success{background:#edf7ed;color:#1e4620;}
</style>
</head>
<body>
<div class="karte">
  <h2>Neues Passwort</h2>
  <?php if ($userIndex === null): ?>
    <div class="msg error">Der Link ist ungültig oder bereits abgelaufen. Bitte fordere einen neuen an.</div>
    <a href="login.html" style="color:#1c1c1e;font-weight:600;">Zurück zum Login</a>
  <?php elseif ($erfolg): ?>
    <div class="msg success">Dein Passwort wurde erfolgreich geändert! Du kannst dich jetzt anmelden.</div>
    <a href="login.html"><button style="margin-top:10px;">Zum Login</button></a>
  <?php else: ?>
    <?php if ($fehler): ?><div class="msg error"><?php echo htmlspecialchars($fehler); ?></div><?php endif; ?>
    <form method="POST">
      <div class="form-gruppe">
        <label>Neues Passwort</label>
        <input type="password" name="passwort" required minlength="8" maxlength="72" placeholder="Mind. 8 Zeichen" autocomplete="new-password">
      </div>
      <div class="form-gruppe">
        <label>Passwort wiederholen</label>
        <input type="password" name="passwort_wdh" required minlength="8" maxlength="72" placeholder="Wiederholen" autocomplete="new-password">
      </div>
      <button type="submit">Passwort speichern</button>
    </form>
  <?php endif; ?>
  <p style="font-size:12px;margin-top:20px;"><a href="datenschutz.html" style="color:#8d8a82;">Datenschutz</a></p>
</div>
</body>
</html>
