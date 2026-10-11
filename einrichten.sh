#!/usr/bin/env bash
# =============================================================================
# SV Niederroth - Platzreservierung: Einrichtung auf dem Server (Debian)
# Aufruf:  sudo bash einrichten.sh
# Das Skript ist wiederholbar (man kann es mehrfach ausfuehren).
# =============================================================================
set -u

WEB="${WEB:-/var/www/tennis-niederroth}"
HOST="tennis-niederroth.ddns.net"
BENUTZER="marc"
CONF_DIR="/etc/tennis-niederroth"
CONF="$CONF_DIR/config.php"
STANDARD_CODE="SVN-Tennis-6Q4M"
SMTP_USER="marc10henke@icloud.com"
FEHLER=0

ok()    { echo "  [OK]       $*"; }
warn()  { echo "  [ACHTUNG]  $*"; }
fehler(){ echo "  [FEHLER]   $*"; FEHLER=1; }
titel() { echo; echo "== $* =="; }

if [ "$(id -u)" -ne 0 ]; then
  echo "Bitte so starten:  sudo bash einrichten.sh"
  exit 1
fi
if [ ! -d "$WEB" ]; then
  echo "Web-Ordner $WEB nicht gefunden. Entweder die Dateien dorthin kopieren"
  echo "oder den Pfad angeben:  sudo WEB=/pfad/zum/ordner bash einrichten.sh"
  exit 1
fi
if [ ! -f "$WEB/login_verarbeiten.php" ] || [ ! -f "$WEB/tn_config.php" ] || [ ! -f "$WEB/platzdaten_funktionen.php" ] || [ ! -f "$WEB/session_start_sicher.php" ]; then
  echo "In $WEB fehlen Projektdateien (login_verarbeiten.php, tn_config.php,"
  echo "platzdaten_funktionen.php oder session_start_sicher.php)."
  echo "Bitte zuerst alle Dateien dorthin kopieren und dann erneut starten."
  exit 1
fi

# ----------------------------------------------------------------------------
titel "1/9 Benoetigte Pakete"
need=()
command -v php >/dev/null 2>&1 || need+=(php-cli)
command -v setfacl >/dev/null 2>&1 || need+=(acl)
command -v curl >/dev/null 2>&1 || need+=(curl)
if command -v php >/dev/null 2>&1 && ! php -m 2>/dev/null | grep -qi '^mbstring$'; then
  need+=(php-mbstring)
fi
if [ "${#need[@]}" -gt 0 ]; then
  echo "  Installiere: ${need[*]}"
  apt-get update -qq && apt-get install -y -qq "${need[@]}" && ok "Pakete installiert" || fehler "Paketinstallation fehlgeschlagen"
else
  ok "alles vorhanden"
fi

# ----------------------------------------------------------------------------
titel "2/9 Aufraeumen im Web-Ordner"
for f in neues_passwort_setzen.php benutzer_passwort_reset.php PRUEFUNG_SICHERHEIT_RECHT.txt einrichten.sh; do
  if [ -e "$WEB/$f" ]; then rm -f "$WEB/$f" && ok "entfernt: $f"; fi
done
for d in "$WEB"/BACKUP_*; do
  if [ -d "$d" ]; then rm -rf "$d" && ok "entfernt: $(basename "$d")"; fi
done
if [ -d "$WEB/.git" ]; then warn ".git liegt im Web-Ordner (wird unten auf Erreichbarkeit geprueft)"; fi

# ----------------------------------------------------------------------------
titel "3/9 PHPMailer (ausserhalb des Web-Ordners)"
VENDOR_DIR="$(dirname "$WEB")/vendor"
if [ -f "$VENDOR_DIR/autoload.php" ]; then
  [ -d "$WEB/vendor" ] && rm -rf "$WEB/vendor"
  ok "vorhanden: $VENDOR_DIR"
elif [ -f "$WEB/vendor/autoload.php" ]; then
  mv "$WEB/vendor" "$VENDOR_DIR" && ok "vendor/ aus dem Web-Ordner nach $VENDOR_DIR verschoben" || fehler "vendor/ konnte nicht verschoben werden"
else
  if ! command -v composer >/dev/null 2>&1; then
    echo "  Installiere composer ..."
    apt-get install -y -qq composer >/dev/null 2>&1 || fehler "composer konnte nicht installiert werden"
  fi
  if command -v composer >/dev/null 2>&1; then
    (cd "$(dirname "$WEB")" && COMPOSER_ALLOW_SUPERUSER=1 composer require phpmailer/phpmailer --no-interaction --no-progress -q) \
      && ok "PHPMailer installiert in $VENDOR_DIR" || fehler "PHPMailer-Installation fehlgeschlagen"
  fi
fi
[ -d "$VENDOR_DIR" ] && chmod -R a+rX "$VENDOR_DIR"
rm -f "$WEB/composer.json" "$WEB/composer.lock"

# ----------------------------------------------------------------------------
titel "4/9 Konfiguration mit Zugangsdaten"
mkdir -p "$CONF_DIR"
NEU=1
if [ -f "$CONF" ] && ! grep -q 'HIER-' "$CONF"; then
  read -r -p "  Es gibt schon eine Konfiguration. Neu eingeben? [j/N] " antwort
  case "$antwort" in j|J|ja|Ja) NEU=1 ;; *) NEU=0 ;; esac
fi
if [ "$NEU" -eq 1 ]; then
  echo
  echo "  Dafuer brauchst du ein APP-SPEZIFISCHES PASSWORT von Apple:"
  echo "  appleid.apple.com -> Anmelden und Sicherheit -> App-spezifische Passwoerter"
  echo "  -> neues Passwort erzeugen (Name z. B. 'Tennis'). Es sieht so aus: abcd-efgh-ijkl-mnop"
  echo
  read -r -p "  Vereinscode fuer die Registrierung [Enter = $STANDARD_CODE]: " CODE
  CODE="${CODE:-$STANDARD_CODE}"
  if ! [[ "$CODE" =~ ^[A-Za-z0-9._-]{4,40}$ ]]; then
    echo "  Der Vereinscode darf nur Buchstaben, Zahlen, . _ - enthalten (4-40 Zeichen)."
    exit 1
  fi
  read -r -s -p "  App-spezifisches Passwort (Eingabe bleibt unsichtbar): " PW
  echo
  PW="${PW// /}"
  if ! [[ "$PW" =~ ^[A-Za-z0-9-]{8,64}$ ]]; then
    echo "  Das Passwort sieht nicht richtig aus (erwartet z. B. abcd-efgh-ijkl-mnop)."
    exit 1
  fi
  umask 077
  cat > "$CONF" <<EOF
<?php
// Von einrichten.sh angelegt. NICHT in den Web-Ordner kopieren, NICHT weitergeben.
return [
    'club_code'          => '$CODE',
    'smtp_host'          => 'smtp.mail.me.com',
    'smtp_port'          => 587,
    'smtp_user'          => '$SMTP_USER',
    'smtp_pass'          => '$PW',
    'mail_from_address'  => '$SMTP_USER',
    'mail_from_name'     => 'SV Niederroth',
    'base_url'           => 'https://$HOST',
    'max_reset_per_hour' => 3,
];
EOF
  umask 022
  ok "Konfiguration geschrieben (Vereinscode: $CODE)"
else
  ok "bestehende Konfiguration bleibt"
fi
chown -R "$BENUTZER":www-data "$CONF_DIR"
chmod 750 "$CONF_DIR"
chmod 640 "$CONF"
ok "Rechte gesetzt (marc:www-data, 640)"

# ----------------------------------------------------------------------------
titel "5/9 Datenordner"
if [ ! -d "$WEB/platzdaten" ]; then
  mkdir -p "$WEB/platzdaten"
  chown "$BENUTZER":www-data "$WEB/platzdaten"
  chmod 2770 "$WEB/platzdaten"
  ok "platzdaten angelegt"
fi
if command -v setfacl >/dev/null 2>&1; then
  setfacl -m "u:$BENUTZER:rwx,u:www-data:rwx" "$WEB/platzdaten" 2>/dev/null
  setfacl -d -m "u:$BENUTZER:rwx,u:www-data:rwx" "$WEB/platzdaten" 2>/dev/null
  ok "Zugriff fuer $BENUTZER und www-data sichergestellt"
fi

# ----------------------------------------------------------------------------
titel "6/9 Dateirechte"
find "$WEB" -path "$WEB/platzdaten" -prune -o -type d -exec chmod 755 {} +
find "$WEB" -path "$WEB/platzdaten" -prune -o -type f -exec chmod 644 {} +
ok "Code 644 / Ordner 755 (platzdaten unveraendert)"

# ----------------------------------------------------------------------------
titel "7/9 Automatisches Aufraeumen (Cron)"
{
  echo "# SV Niederroth - angelegt von einrichten.sh"
  echo "30 3 * * * $BENUTZER find $WEB/platzdaten -name '20??-??-??.json' -mtime +90 -delete"
  echo "15 4 * * * root journalctl --vacuum-time=30d >/dev/null 2>&1"
  if [ -d /var/log/caddy ]; then
    echo "20 4 * * * root find /var/log/caddy -type f -name '*.log*' -mtime +30 -delete"
  fi
} > /etc/cron.d/tennis-niederroth
chmod 644 /etc/cron.d/tennis-niederroth
ok "Buchungen werden nach 90 Tagen geloescht, Server-Logs nach 30 Tagen"

# ----------------------------------------------------------------------------
titel "8/9 PHP pruefen"
if command -v php >/dev/null 2>&1; then
  anzahl=0
  for f in "$WEB"/*.php; do
    out="$(php -l "$f" 2>&1)" || { fehler "$(basename "$f"): $out"; continue; }
    anzahl=$((anzahl + 1))
  done
  [ "$FEHLER" -eq 0 ] && ok "$anzahl PHP-Dateien ohne Syntaxfehler"
else
  warn "php nicht gefunden, Pruefung uebersprungen"
fi
for s in $(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null | awk '{print $1}'); do
  systemctl reload "$s" 2>/dev/null && ok "$s neu geladen"
done

# ----------------------------------------------------------------------------
titel "9/9 Test gegen den laufenden Server"
url() { echo "https://$HOST/$1"; }
cu()  { curl -sk --max-time 10 --resolve "$HOST:443:127.0.0.1" "$@"; }

antwort="$(cu -X POST -H 'Content-Type: application/json' \
  -d '{"vorname":"Test","nachname":"Test","passwort":"falsch"}' "$(url login_verarbeiten.php)")"
if echo "$antwort" | grep -q '"ok":false'; then
  ok "Login-Schnittstelle antwortet korrekt"
else
  fehler "Login-Schnittstelle liefert keine gueltige Antwort:"
  echo "     ${antwort:0:300}"
  echo "     --- letzte PHP-Fehler ---"
  journalctl -u 'php*-fpm' -n 15 --no-pager 2>/dev/null | sed 's/^/     /'
fi

for seite in login.html index.html impressum.html datenschutz.html; do
  c="$(cu -o /dev/null -w '%{http_code}' "$(url $seite)")"
  if [ "$c" = "200" ]; then ok "$seite erreichbar (200)"; else fehler "$seite liefert $c"; fi
done

leck="$(cu "$(url platzdaten/accounts.json)")"
if echo "$leck" | grep -q 'passwort_hash'; then
  fehler "platzdaten/accounts.json ist oeffentlich abrufbar! Caddy-Sperre pruefen."
else
  ok "platzdaten/accounts.json ist nicht abrufbar"
fi
for p in .git/config composer.json vendor/autoload.php PRUEFUNG_SICHERHEIT_RECHT.txt einrichten.sh; do
  c="$(cu -o /dev/null -w '%{http_code}' "$(url $p)")"
  case "$c" in
    200) warn "$p liefert 200 - bitte im Caddyfile sperren" ;;
    *)   ok "$p nicht abrufbar ($c)" ;;
  esac
done

echo
if [ "$FEHLER" -eq 0 ]; then
  echo "FERTIG. Alles eingerichtet."
else
  echo "FERTIG mit Fehlern - siehe [FEHLER] oben. Schick mir die Ausgabe."
fi
