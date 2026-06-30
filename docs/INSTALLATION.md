# Installationsanleitung — ProCentric PMS Middleware

Vollständige Installation auf **Rocky Linux 10 (Minimal ISO)**. Diese Anleitung
führt von der frischen Betriebssysteminstallation bis zur betriebsbereiten
Middleware. Sie deckt die **automatische** Installation (`deploy/install.sh`) und
die **manuelle** Schritt-für-Schritt-Variante (zur Fehlersuche) ab.

> Getestet auf Rocky Linux 10.1 „Red Quartz", PHP 8.3, Apache httpd 2.4.
> Kompatibel zu RHEL/AlmaLinux 9/10; bei abweichenden Distributionen ggf. Pfade anpassen.

---

## Inhalt

1. [Architektur-Überblick](#1-architektur-überblick)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Netzwerk- und Portplanung](#3-netzwerk--und-portplanung)
4. [Rocky Linux 10 Minimal vorbereiten](#4-rocky-linux-10-minimal-vorbereiten)
5. [Automatische Installation](#5-automatische-installation-empfohlen)
6. [Manuelle Installation (Schritt für Schritt)](#6-manuelle-installation-schritt-für-schritt)
7. [Konfiguration](#7-konfiguration)
8. [Inbetriebnahme prüfen (Verifikation)](#8-inbetriebnahme-prüfen-verifikation)
9. [PCS-seitige Einrichtung](#9-pcs-seitige-einrichtung)
10. [Betrieb & Wartung](#10-betrieb--wartung)
11. [Update / Neuinstallation](#11-update--neuinstallation)
12. [Fehlersuche (Troubleshooting)](#12-fehlersuche-troubleshooting)
13. [Sicherheit](#13-sicherheit)
14. [Deinstallation](#14-deinstallation)

---

## 1. Architektur-Überblick

Die Middleware ist die Brücke zwischen **Fidelio Opera PMS** (FIAS-TCP) und dem
**LG ProCentric Direct Server (PCS)** (REST). Drei voneinander unabhängige
Komponenten laufen auf demselben Host:

| Komponente | Technik | Port | Richtung |
|---|---|---|---|
| **Web-GUI** | Apache VHost → `index.php`/`api.php` | **80** (eingehend) | Admin-Browser → Middleware |
| **PMS-Interface** | Apache VHost → `pms_router.php` | **7000** (eingehend, konfigurierbar) | PCS → Middleware |
| **FIAS-Daemon** | systemd-Dienst `fias-client.service` (PHP-CLI) | **5091** (ausgehend) | Middleware → Opera FIAS |
| **PCS-API-Aufrufe** | aus PHP heraus (curl) | **60080** (ausgehend, TLS) | Middleware → PCS |
| **Event-Push** | PCS ruft `callbackUri` aus `data/subscriptions.json` | (PCS-seitig) | PCS → TVs |

```
+---------+   FIAS-TCP 5091   +---------------------+   curl 60080   +-------+   Push   +-----+
| Opera   | <===============> | fias_client.php     | =============> | PCS   | =======> | TVs |
| PMS     |   (ausgehend)     | (systemd-Daemon)    |   (ausgehend)  |       |          |     |
+---------+                   +---------------------+                +-------+          +-----+
                                                                        |
                                              PCS ruft Port 7000 ein -> | pms_router.php (Apache VHost)
                                                                        v
                                  Admin-Browser -> Port 80 (IP-beschraenkt) -> index.php / api.php
```

> **Wichtig (zwei Port-Richtungen nicht verwechseln):**
> - `procentric.port = 60080` ist der Port **des PCS**, den die Middleware **ausgehend** anruft.
> - `middleware.pms_interface_port` (Default **7000**) ist der Port, auf dem die
>   Middleware **eingehend** lauscht und den der PCS anruft. Dieser muss in
>   Apache (`pms-listen.conf` + `pms-middleware.conf`), in `config.json` und im
>   PCS identisch sein.

---

## 2. Voraussetzungen

**Hardware / VM (Minimum):**
- 1 vCPU, 1 GB RAM, 10 GB Disk (mehr für Logs/Reserve empfohlen)
- Ein Netzwerkinterface mit statischer IP im Admin-/PCS-Netz

**Software (installiert das Skript automatisch):**
- Apache `httpd` mit `mod_rewrite`
- PHP 8.x mit den Extensions `curl`, `sockets`, `pcntl`, `posix`, `json`, `openssl`
  (auf Rocky 10 sind `sockets`/`pcntl`/`json`/`openssl` fest eingebaut, `curl`
  steckt in `php-common`, `posix` in `php-process`)
- `gcc` (kompiliert die beiden SUID-Hilfsbinaries)

**Zugänge / Daten, die vorab bereitstehen müssen:**
- PCS: Host-IP, Port (i.d.R. `60080`), `client_id` + `client_secret`, SSL ja/nein
- Opera FIAS: Host-IP, Port (i.d.R. `5091`)
- Liste der Admin-Netze (CIDR), die auf die GUI zugreifen dürfen
- root-Zugang zum Server

---

## 3. Netzwerk- und Portplanung

Vor der Installation klären und in der Firewall/Netzwerk-Infrastruktur freigeben:

| Verbindung | Quelle | Ziel | Port/Proto | Muss offen sein |
|---|---|---|---|---|
| Admin-GUI | Admin-PCs (Netz X) | Middleware | TCP **80** eingehend | ja (auf Middleware) |
| PMS-Interface | PCS | Middleware | TCP **7000** eingehend | ja (auf Middleware) |
| FIAS | Middleware | Opera | TCP **5091** ausgehend | ja (Routing/FW dazwischen) |
| PCS-API | Middleware | PCS | TCP **60080** ausgehend | ja (Routing/FW dazwischen) |

> **FIAS-Besonderheit:** Der Opera-FIAS-Server akzeptiert **nur eine** TCP-Verbindung.
> Während der Daemon läuft, dürfen **keine** zusätzlichen `telnet`/`fsockopen`-Tests
> gegen Port 5091 laufen — eine zweite Verbindung trennt die erste.

---

## 4. Rocky Linux 10 Minimal vorbereiten

1. **Rocky Linux 10 Minimal** installieren (Standardinstallation, keine GUI nötig).
2. Nach dem ersten Login als root System aktualisieren:
   ```bash
   dnf -y update
   ```
3. Statische IP setzen (Beispiel mit NetworkManager):
   ```bash
   nmcli con mod "System ens160" ipv4.addresses 192.168.120.20/24 \
         ipv4.gateway 192.168.120.1 ipv4.dns 192.168.120.1 ipv4.method manual
   nmcli con up "System ens160"
   ```
   > Interface-Name (`ens160`/`ens224` …) mit `nmcli con show` ermitteln.
4. Zeit/Datum (für Cron-Resync und TLS) prüfen:
   ```bash
   timedatectl set-timezone Europe/Berlin
   timedatectl   # NTP aktiv?
   ```
5. Quellcode liegt lokal unter `/root/procentric-pms-middleware` (Repo-Quelle).
   Die Installation erfolgt ausschließlich aus diesem lokalen Verzeichnis —
   kein Internet-/GitHub-Zugriff nötig.

Die Minimal-ISO bringt **SELinux=Enforcing** und ein **aktives firewalld** mit —
beides behandelt das Installationsskript automatisch (SELinux → disabled,
firewalld → Ports 80 + PMS-Port öffnen).

---

## 5. Automatische Installation (empfohlen)

Die Routine `deploy/install.sh` ist **idempotent** (mehrfacher Aufruf ist
ungefährlich) und überschreibt eine vorhandene `config.json` **nicht**
(Zeitstempel-Backup statt Überschreiben).

```bash
# In die lokale Repo-Quelle wechseln (bereits auf diesem System vorhanden)
cd /root/procentric-pms-middleware

# Installation starten
sudo bash deploy/install.sh
```

Das Skript führt nacheinander aus:

| Schritt | Aktion |
|---|---|
| 0 | Vorbedingungen (root, `web/`-Pfad, Distro-Erkennung) |
| 1 | Pakete `httpd php php-cli php-common php-process php-mbstring gcc`; verifiziert PHP-Extensions |
| 2 | SELinux: bei Enforcing → Permissive sofort + `disabled` in `/etc/selinux/config` (voll wirksam nach Reboot) |
| 3 | Web-Dateien nach `/var/www/html` (ohne `config.json`/`data`/`logs` zu überschreiben) |
| 4 | Eigentümer `apache:apache`, Verzeichnisse `755`, Dateien `644`, `data`/`logs` `775`, `config.json` `664` |
| 5 | Apache-VHosts nach `/etc/httpd/conf.d/`; entfernt doppeltes `Listen 7000` aus `httpd.conf`; `httpd -t` |
| 6 | SUID-Helfer `fias-ctl` + `pms-portctl` kompilieren → `/usr/local/bin` (`root:root`, `4755`) |
| 7 | systemd-Dienst `fias-client.service` installieren + `daemon-reload` |
| 8 | Cron `/etc/cron.d/pms-middleware` |
| 9 | firewalld: TCP 80 + PMS-Port (aus `config.json` geparst, sonst 7000) freigeben |
| 10 | Dienste `httpd`, `crond`, `fias-client` aktivieren & starten; Statuszusammenfassung |

Am Ende zeigt das Skript die **GUI-URL**, die **PMS-Interface-URL** und den
Dienststatus an. Weiter mit [Abschnitt 7 (Konfiguration)](#7-konfiguration).

> Wurde SELinux gerade erst von Enforcing umgestellt, einmalig **rebooten**,
> damit `disabled` voll greift (`setenforce 0` macht es sofort permissive — die
> SUID-Helfer funktionieren dann bereits).

---

## 6. Manuelle Installation (Schritt für Schritt)

Diese Schritte entsprechen exakt dem, was `install.sh` automatisiert — nützlich
zur Fehlersuche oder für individuelle Anpassungen.

### 6.1 Pakete

```bash
dnf install -y httpd php php-cli php-common php-process php-mbstring gcc
systemctl enable --now httpd
php -m | grep -E 'curl|sockets|pcntl|posix|json|openssl'   # alle muessen erscheinen
```

### 6.2 SELinux deaktivieren

Die SUID-Helfer und der httpd-Reload aus Apache heraus scheitern unter
SELinux-Enforcing.

```bash
setenforce 0
sed -i 's/^SELINUX=.*/SELINUX=disabled/' /etc/selinux/config
# voll wirksam nach Reboot; setenforce 0 wirkt sofort (Permissive)
```

### 6.3 Quellcode bereitstellen

```bash
cd /root/procentric-pms-middleware
mkdir -p /var/www/html/data /var/www/html/logs

# Code + .htaccess (nicht config.json/data/logs)
cp -a web/api.php web/index.php web/fias_client.php web/pms_router.php \
      web/resync.php web/resync_lib.php web/resync_cron.php web/ip_guard.php \
      web/.htaccess /var/www/html/

# config.json nur anlegen, wenn noch nicht vorhanden
[ -f /var/www/html/config.json ] || cp -a web/config.example.json /var/www/html/config.json
```

### 6.4 Eigentümer & Rechte (kritisch!)

Apache läuft als User `apache` und **muss** `config.json`, `data/` und `logs/`
beschreiben können. Falsche Owner führen zu schwer auffindbaren **HTTP-401**-Fehlern
bei der Callback-Token-Persistenz (siehe Troubleshooting).

```bash
chown -R apache:apache /var/www/html
find /var/www/html -type d -exec chmod 755 {} +
find /var/www/html -type f -exec chmod 644 {} +
chmod 775 /var/www/html/data /var/www/html/logs
chmod 664 /var/www/html/config.json
```

### 6.5 Apache-VirtualHosts

```bash
cp deploy/apache/pms-middleware.conf  /etc/httpd/conf.d/
cp deploy/apache/port80-restrict.conf /etc/httpd/conf.d/
cp deploy/apache/pms-listen.conf      /etc/httpd/conf.d/
```

- `pms-listen.conf` enthält **nur** `Listen 7000` (vom GUI/`pms-portctl` automatisch
  umschreibbar). In `/etc/httpd/conf/httpd.conf` bleibt nur `Listen 80` —
  ein dort verbliebenes `Listen 7000` muss entfernt werden (Kollision):
  ```bash
  sed -ri '/^\s*Listen\s+7000\b/d' /etc/httpd/conf/httpd.conf
  ```
- `pms-middleware.conf` ist der VHost auf `*:7000`, leitet `/api/pms/v2/*` an
  `pms_router.php`.
- `port80-restrict.conf` ist der GUI-VHost auf `*:80` (`Require all granted`);
  die IP-Beschränkung erfolgt in PHP (`ip_guard.php` über
  `access_control.allowed_networks`), **nicht** statisch in Apache.

Konfiguration prüfen und neu starten:

```bash
httpd -t
systemctl restart httpd
```

### 6.6 FIAS-Daemon (systemd)

```bash
cp deploy/systemd/fias-client.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now fias-client
```

Der Dienst läuft als `apache`, startet `php /var/www/html/fias_client.php`,
`Restart=on-failure` (30 s).

### 6.7 SUID-Hilfsbinaries

**`fias-ctl`** — erlaubt der GUI, den FIAS-Dienst zu steuern
(`start|stop|restart|status|pid`):

```bash
gcc -O2 -o deploy/fias-ctl/fias-ctl deploy/fias-ctl/fias-ctl.c
cp deploy/fias-ctl/fias-ctl /usr/local/bin/
chown root:root /usr/local/bin/fias-ctl
chmod 4755 /usr/local/bin/fias-ctl
```

**`pms-portctl`** — erlaubt der GUI, den PMS-Interface-Port live umzustellen.
Schreibt `Listen` (`pms-listen.conf`) **und** den VHost-Port
(`pms-middleware.conf`) um, validiert mit `apachectl configtest`, lädt httpd neu
und **rollt bei Fehler automatisch zurück**. Port wird strikt validiert
(nur Ziffern, 1024–65535, nicht 443). Port 80 wird nie angefasst.

```bash
gcc -O2 -o deploy/pms-portctl/pms-portctl deploy/pms-portctl/pms-portctl.c
cp deploy/pms-portctl/pms-portctl /usr/local/bin/
chown root:root /usr/local/bin/pms-portctl
chmod 4755 /usr/local/bin/pms-portctl
```

### 6.8 Cron-Resync

```bash
cp deploy/cron/pms-middleware /etc/cron.d/pms-middleware
systemctl enable --now crond
```

Cron läuft jede Minute; `resync_cron.php` führt den Resync nur zu den in
`config.json` (`resync.checkout_time` / `checkin_time`, Default 11:00 / 11:30) und
nur wenn aktiviert (`resync.enabled`, optional `only_when_fias_down`) aus.

### 6.9 Firewall

```bash
firewall-cmd --permanent --add-port=80/tcp
firewall-cmd --permanent --add-port=7000/tcp     # bzw. der konfigurierte PMS-Port
firewall-cmd --reload
firewall-cmd --list-ports
```

---

## 7. Konfiguration

Bevorzugt über die **GUI** (`http://<server>/` → Tab **Einstellungen**),
alternativ direkt in `/var/www/html/config.json`. Nach manuellem Editieren von
`config.json` immer Rechte zurücksetzen:

```bash
chown apache:apache /var/www/html/config.json && chmod 664 /var/www/html/config.json
```

Struktur (`config.example.json` als Vorlage):

```jsonc
{
  "procentric": {
    "host": "192.168.120.159",   // PCS-IP
    "port": 60080,                // PCS-API-Port (ausgehend)
    "ssl": true,                  // PCS per TLS ansprechen
    "client_id": "CHANGEME",
    "client_secret": "CHANGEME",
    "api_prefix": "/api/pms/v2"
  },
  "fias": {
    "host": "192.168.0.20",       // Opera FIAS-IP
    "port": 5091,
    "heartbeat_interval": 300,    // LA-Heartbeat (Sekunden)
    "language_map": { "EA": "en", "GE": "de", "FR": "fr", "...": "..." }
  },
  "middleware": {
    "listen_port": 80,
    "log_max_entries": 500
    // "pms_interface_port": 7000  // eingehender PCS-Port; via GUI/pms-portctl gesetzt
  },
  "access_control": {
    "allowed_networks": ["192.168.120.0/24", "127.0.0.1/32"]  // CIDR; leer = keine Beschr.
  },
  "resync": {
    "enabled": true,
    "only_when_fias_down": true,
    "checkout_time": "11:00",
    "checkin_time": "11:30",
    "rooms": [],
    "pseudo_first_name": "Max",
    "pseudo_last_name": "Mustermann",
    "pseudo_language": "de",
    "room_filter": true
  }
}
```

> **`pms_interface_port`** wird am besten **im GUI** (Einstellungen → ProCentric
> Server) geändert — dann passt `pms-portctl` Apache automatisch mit an. Ein
> manueller Eintrag in `config.json` ohne Apache-Anpassung führt zu einem
> Port-Mismatch (PCS erreicht die Middleware nicht).

---

## 8. Inbetriebnahme prüfen (Verifikation)

```bash
# 1. Dienste laufen?
systemctl is-active httpd fias-client crond
systemctl status fias-client --no-pager

# 2. Lauscht Apache auf 80 und 7000?
ss -ltnp | grep -E ':80 |:7000 '

# 3. GUI lokal erreichbar?
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/        # 200 erwartet

# 4. PMS-Interface antwortet?
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:7000/api/pms/v2/statuses

# 5. SUID-Helfer ok?
ls -l /usr/local/bin/fias-ctl /usr/local/bin/pms-portctl   # -rwsr-xr-x root root
/usr/local/bin/fias-ctl status                              # active

# 6. FIAS-Verbindung im Log?
tail -n 40 /var/www/html/logs/api.log
```

Anschließend in der GUI den **Verbindungstest** (PCS) ausführen und Status-Badges
oben rechts (FIAS / PCS) prüfen.

---

## 9. PCS-seitige Einrichtung

Im LG ProCentric Direct Server konfigurieren:

1. **PMS-Verbindung** auf die Middleware zeigen lassen: `http://<middleware-ip>:7000`
   (bzw. der konfigurierte PMS-Port) mit Pfadpräfix `/api/pms/v2`.
2. **Fetch Data / Fetch Settings** auslösen — dabei registriert der PCS eine
   Subscription (`POST /subscriptions`) und vergibt einen **`callbackToken`**
   (Bearer), den die Middleware in `data/subscriptions.json` **persistiert**.
   Während dieses Vorgangs ergänzt die Auto-Discovery die Zimmerliste.
3. Sicherstellen, dass `procentric.host/port/ssl` + Credentials in der Middleware
   auf denselben PCS zeigen.

> Event-Pushes (Check-In/-Out) gehen an die `callbackUri` aus
> `data/subscriptions.json` — **nicht** an `procentric.host`. Nach einem
> PCS-Umzug/IP-Wechsel müssen sowohl `config.json` als auch
> `data/subscriptions.json → callbackUri` aktualisiert werden.

---

## 10. Betrieb & Wartung

**Dienste:**
```bash
systemctl restart httpd          # Apache (GUI + PMS-Interface)
systemctl restart fias-client    # FIAS-Daemon
journalctl -u fias-client -f     # Live-Log des Daemons
```

**Logs:**
- `/var/www/html/logs/api.log` — Anwendungs-Log (in GUI farbcodiert sichtbar)
- `/var/www/html/logs/resync_cron.log` — Cron-Resync
- `/var/log/httpd/pms_error.log`, `pms_access.log` — PMS-Interface (Port 7000)
- `/var/log/httpd/error_log`, `access_log` — GUI (Port 80)

**FIAS aus der GUI steuern:** Tab **FIAS** → Start/Stop/Status, DB-Swap.
Intern ruft die GUI `fias-ctl` (SUID).

**PMS-Port ändern:** Tab **Einstellungen → ProCentric Server**, Feld speichern →
`pms-portctl` schreibt Apache um, reloadt, rollt bei Fehler zurück. Danach den
neuen Port in der **Firewall** freigeben und im **PCS** eintragen.

---

## 11. Update / Neuinstallation

Aus der lokalen Repo-Quelle neu ausrollen, ohne Konfiguration/Laufzeitdaten zu
verlieren:

```bash
cd /root/procentric-pms-middleware
sudo bash deploy/install.sh
```

`install.sh` ist idempotent: `config.json` bleibt erhalten (Backup
`config.json.bak-install-<timestamp>`), `data/`/`logs/` werden nicht angetastet,
Rechte werden neu gesetzt, Apache neu geprüft.

> Manuelles Update einzelner Dateien: nach dem Kopieren **immer**
> `chown apache:apache` + passende Rechte setzen (siehe 6.4), sonst drohen
> 401/Schreibfehler.

---

## 12. Fehlersuche (Troubleshooting)

| Symptom | Ursache / Prüfung | Lösung |
|---|---|---|
| **HTTP 401 / „PCS callback error"** bei Event-Push | `data/subscriptions.json` o.ä. gehört `root:root` → Apache kann neuen `callbackToken` nicht speichern (antwortet PCS „success", behält aber alten Token) | `chown apache:apache /var/www/html/data/*.json && chmod 664 …`; zuerst **Owner/Rechte**, dann Token prüfen |
| GUI nicht erreichbar | Port 80 in Firewall zu **oder** Quell-IP nicht in `allowed_networks` | `firewall-cmd --add-port=80/tcp`; CIDR in Einstellungen → Zugriffsbeschränkung ergänzen (Loopback ist immer erlaubt) |
| PCS erreicht Middleware nicht | Port-Mismatch zwischen `pms-listen.conf`, `pms-middleware.conf`, `config.json`, PCS; oder Firewall | Port via GUI setzen (`pms-portctl` synchronisiert Apache), Firewall freigeben, `ss -ltnp` (Port 7000 sichtbar?) |
| FIAS-Daemon startet, fällt aber ständig zurück | falscher FIAS-Host/Port, oder zweite Verbindung trennt | `journalctl -u fias-client`; keine `telnet`-Tests gegen 5091 bei laufendem Daemon |
| `fias-ctl`/`pms-portctl` aus GUI ohne Wirkung | SELinux Enforcing **oder** Binary nicht SUID `root` | `getenforce` (→ disabled), `ls -l /usr/local/bin/…` muss `-rwsr-xr-x root root` zeigen |
| `apachectl configtest` schlägt fehl nach Portwechsel | inkonsistente Listen/VHost-Ports | `pms-portctl` rollt automatisch zurück; Ports in beiden `.conf` prüfen |
| Resync läuft nicht | `crond` aus, `resync.enabled=false`, oder `only_when_fias_down` greift | `systemctl status crond`, `logs/resync_cron.log`, Zeiten/Flags in `config.json` |
| PHP-Funktion `socket_*`/`curl_*` „undefined" | Extension fehlt | `php -m` (zeigt `sockets`/`curl`/`posix`?); ggf. `php-process`/`php-common` nachinstallieren |

---

## 13. Sicherheit

- **GUI-Zugriff** über `access_control.allowed_networks` (CIDR) auf Admin-Netze
  begrenzen — durchgesetzt in `ip_guard.php`. Loopback ist immer erlaubt; leere
  Liste = keine Beschränkung.
- **`.htaccess`** sperrt direkten Zugriff auf `config.json`, `*.log`/`*.pid`/`*.flag`,
  interne PHP-Dateien (`fias_client`, `pms_router`, `resync*`, `ip_guard`) sowie
  `data/` und `logs/`. Voraussetzung: GUI-VHost mit `AllowOverride All` (so in
  `port80-restrict.conf` gesetzt).
- **`config.json`** enthält PCS-Credentials → steht in `.gitignore`, **niemals**
  ins Repo einchecken.
- **SUID-Binaries** validieren Eingaben strikt (Port nur Ziffern, 1024–65535) und
  fassen Port 80 nie an — ein versehentliches Aussperren über die GUI ist nicht möglich.
- **SELinux disabled** ist Projektvorgabe (sonst scheitern SUID + httpd-Reload).
  Wer SELinux behalten will, muss eigene Policies bauen und für jeden PMS-Port ein
  `http_port_t`-Label setzen (`semanage port -a -t http_port_t -p tcp <port>`).

---

## 14. Deinstallation

```bash
systemctl disable --now fias-client httpd crond
rm -f /etc/systemd/system/fias-client.service && systemctl daemon-reload
rm -f /etc/httpd/conf.d/{pms-middleware,port80-restrict,pms-listen}.conf
rm -f /usr/local/bin/{fias-ctl,pms-portctl}
rm -f /etc/cron.d/pms-middleware
firewall-cmd --permanent --remove-port=80/tcp --remove-port=7000/tcp; firewall-cmd --reload
# Web-Dateien (ACHTUNG: enthaelt config.json mit Credentials und data/):
# rm -rf /var/www/html/*
```

---

*Stand: Rocky Linux 10.1, PHP 8.3. Bei Pfad-/Versionsabweichungen die
README.md sowie die Quelldateien unter `deploy/` heranziehen.*
