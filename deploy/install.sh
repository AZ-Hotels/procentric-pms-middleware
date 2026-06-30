#!/usr/bin/env bash
#
# ProCentric PMS Middleware - Installationsroutine fuer Rocky Linux 10 (Minimal ISO)
# =================================================================================
#
# Bringt ein frisch installiertes Rocky Linux 10 Minimal-System in einen
# lauffaehigen Zustand: Pakete, Web-Dateien, Apache-VHosts (Port 80 GUI +
# Port 7000 PMS-Interface), FIAS-systemd-Daemon, SUID-Helfer (fias-ctl,
# pms-portctl), Cron-Resync, Firewall und SELinux.
#
# Aufruf (aus dem Repo-Wurzelverzeichnis oder beliebigem Pfad):
#     sudo bash deploy/install.sh
#
# Eigenschaften:
#   - Idempotent: mehrfacher Aufruf ist sicher; vorhandene config.json/data
#     werden NICHT ueberschrieben (Backups mit Zeitstempel).
#   - Bricht bei echten Fehlern ab (set -euo pipefail), Firewall/SELinux-Schritte
#     sind tolerant (nur Warnungen, falls Subsysteme fehlen).
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Konstanten / Pfade
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
WEB_SRC="${REPO_ROOT}/web"
WWW="/var/www/html"
HTTPD_CONFD="/etc/httpd/conf.d"
APACHE_USER="apache"
DEFAULT_PMS_PORT="7000"   # PMS-Interface (PCS -> Middleware), siehe pms-listen.conf

# GUI-Zugriff: Das Netz dieses Interfaces wird automatisch in
# access_control.allowed_networks aufgenommen, damit die GUI nach der
# Installation aus dem primaeren (Admin-)Netz erreichbar ist und man sich
# nicht aussperrt. Reihenfolge der Ermittlung:
#   1. Umgebungsvariable GUI_IFACE (falls gesetzt)
#   2. Interface der Default-Route
#   3. Fallback: ens160
# Mit GUI_ALLOW_NET="a.b.c.0/24" laesst sich das Netz auch direkt vorgeben.
GUI_IFACE="${GUI_IFACE:-}"
GUI_ALLOW_NET="${GUI_ALLOW_NET:-}"
FALLBACK_IFACE="ens160"

# ---------------------------------------------------------------------------
# Log-Helfer
# ---------------------------------------------------------------------------
if [[ -t 1 ]]; then C_G=$'\e[32m'; C_Y=$'\e[33m'; C_R=$'\e[31m'; C_B=$'\e[1m'; C_0=$'\e[0m'
else C_G=""; C_Y=""; C_R=""; C_B=""; C_0=""; fi
step() { echo; echo "${C_B}==> $*${C_0}"; }
ok()   { echo "    ${C_G}OK${C_0}  $*"; }
warn() { echo "    ${C_Y}WARN${C_0} $*"; }
die()  { echo "${C_R}FEHLER:${C_0} $*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# 0. Vorbedingungen
# ---------------------------------------------------------------------------
step "Vorbedingungen pruefen"
[[ $EUID -eq 0 ]] || die "Bitte als root ausfuehren (sudo bash deploy/install.sh)."
[[ -d "${WEB_SRC}" ]] || die "web/ nicht gefunden unter ${WEB_SRC} - aus dem Repo heraus starten."

if [[ -r /etc/os-release ]]; then
    . /etc/os-release
    case "${ID:-}:${VERSION_ID:-}" in
        rocky:10*|rhel:10*|almalinux:10*|centos:10*) ok "Distribution: ${PRETTY_NAME:-$ID $VERSION_ID}" ;;
        rocky:9*|rhel:9*|almalinux:9*)               warn "EL9 erkannt (${PRETTY_NAME:-}) - kompatibel, weiter." ;;
        *) warn "Ungetestete Distribution '${PRETTY_NAME:-unbekannt}' - Pfade ggf. anpassen." ;;
    esac
else
    warn "/etc/os-release nicht lesbar - fahre auf eigene Gefahr fort."
fi

# ---------------------------------------------------------------------------
# 1. Pakete
# ---------------------------------------------------------------------------
# Auf Rocky 10 sind die PHP-Extensions sockets, pcntl, json und openssl fest
# eingebaut; curl steckt in php-common, posix in php-process. Es werden daher
# nur real existierende Paketnamen installiert (sonst bricht dnf ab).
step "Pakete installieren (dnf)"
PKGS=(httpd php php-cli php-common php-process php-mbstring gcc)
dnf install -y "${PKGS[@]}"
ok "Pakete installiert: ${PKGS[*]}"

# Verifizieren, dass alle vom Code genutzten PHP-Extensions geladen sind.
NEED_EXT=(sockets pcntl posix curl json openssl)
MISSING=()
for ext in "${NEED_EXT[@]}"; do php -m 2>/dev/null | grep -qix "$ext" || MISSING+=("$ext"); done
[[ ${#MISSING[@]} -eq 0 ]] && ok "PHP-Extensions vorhanden: ${NEED_EXT[*]}" \
                           || warn "Fehlende PHP-Extensions: ${MISSING[*]} - bitte nachinstallieren."

# ---------------------------------------------------------------------------
# 2. SELinux deaktivieren
# ---------------------------------------------------------------------------
# Die SUID-Helfer (fias-ctl/pms-portctl) und httpd-Reload aus Apache heraus
# scheitern unter SELinux-Enforcing. Projektvorgabe: SELinux disabled.
step "SELinux"
if command -v getenforce >/dev/null 2>&1; then
    CUR_SE="$(getenforce 2>/dev/null || echo Unknown)"
    if [[ "${CUR_SE}" == "Enforcing" ]]; then
        setenforce 0 || true
        if [[ -f /etc/selinux/config ]]; then
            sed -i 's/^SELINUX=.*/SELINUX=disabled/' /etc/selinux/config
        fi
        warn "SELinux war Enforcing -> jetzt Permissive; 'disabled' gesetzt (voll wirksam nach REBOOT)."
    else
        ok "SELinux: ${CUR_SE}"
    fi
else
    ok "SELinux-Tools nicht vorhanden (deaktiviert)."
fi

# ---------------------------------------------------------------------------
# 3. Web-Dateien bereitstellen
# ---------------------------------------------------------------------------
step "Web-Dateien nach ${WWW} kopieren"
mkdir -p "${WWW}"
# Code + .htaccess kopieren; config.json/data/logs werden separat behandelt.
shopt -s dotglob
for item in "${WEB_SRC}"/*; do
    base="$(basename "$item")"
    case "$base" in
        config.json|config.example.json|data|logs) continue ;;
        *) cp -a "$item" "${WWW}/" ;;
    esac
done
shopt -u dotglob
ok "PHP-Dateien und .htaccess kopiert"

# Laufzeitverzeichnisse
mkdir -p "${WWW}/data" "${WWW}/logs"
ok "data/ und logs/ angelegt"

# config.json: nur anlegen, wenn nicht vorhanden (Credentials nicht ueberschreiben)
if [[ -f "${WWW}/config.json" ]]; then
    TS="$(date +%Y%m%d-%H%M%S)"
    cp -a "${WWW}/config.json" "${WWW}/config.json.bak-install-${TS}"
    ok "Vorhandene config.json beibehalten (Backup: config.json.bak-install-${TS})"
elif [[ -f "${WEB_SRC}/config.json" ]]; then
    cp -a "${WEB_SRC}/config.json" "${WWW}/config.json"
    ok "Produktive config.json aus Paket uebernommen"
else
    cp -a "${WEB_SRC}/config.example.json" "${WWW}/config.json"
    ok "config.json aus Vorlage erstellt - Credentials spaeter im GUI ausfuellen"
fi

# ---------------------------------------------------------------------------
# 4. Eigentuemer & Rechte
# ---------------------------------------------------------------------------
# httpd laeuft als 'apache' und muss config.json, data/, logs/ schreiben koennen.
# Falsche Owner fuehren zu HTTP 401 bei der Callback-Token-Persistenz.
step "Eigentuemer/Rechte setzen (${APACHE_USER}:${APACHE_USER})"
chown -R "${APACHE_USER}:${APACHE_USER}" "${WWW}"
find "${WWW}" -type d -exec chmod 755 {} +
find "${WWW}" -type f -exec chmod 644 {} +
# schreibbare Laufzeitdateien: 664 / Verzeichnisse 775
chmod 775 "${WWW}/data" "${WWW}/logs"
chmod 664 "${WWW}/config.json"
ok "Owner ${APACHE_USER}:${APACHE_USER}, Laufzeitdateien beschreibbar"

# ---------------------------------------------------------------------------
# 4b. GUI-Zugriff aus dem primaeren Netz sicherstellen
# ---------------------------------------------------------------------------
# Ermittelt das Netz (CIDR) des primaeren Interfaces und ergaenzt es in
# config.json -> access_control.allowed_networks, falls es fehlt. So ist die
# GUI nach der Installation aus dem Admin-Netz erreichbar (Schutz vor
# Aussperren), ohne vorhandene Eintraege zu entfernen.
step "GUI-Zugriff aus primaerem Netz freischalten"

if [[ -n "${GUI_ALLOW_NET}" ]]; then
    GUI_NET="${GUI_ALLOW_NET}"
    ok "Netz aus GUI_ALLOW_NET vorgegeben: ${GUI_NET}"
else
    IFACE="${GUI_IFACE}"
    [[ -z "${IFACE}" ]] && IFACE="$(ip -o -4 route show to default 2>/dev/null | awk '{print $5; exit}')"
    [[ -z "${IFACE}" ]] && IFACE="${FALLBACK_IFACE}"
    # Connected-Netz (proto kernel scope link) dieses Interfaces, z.B. 192.168.120.0/24
    GUI_NET="$(ip -o -4 route show dev "${IFACE}" scope link proto kernel 2>/dev/null | awk '{print $1; exit}')"
    if [[ -n "${GUI_NET}" ]]; then
        ok "Primaeres Interface ${IFACE} -> Netz ${GUI_NET}"
    else
        warn "Konnte Netz von Interface '${IFACE}' nicht ermitteln - allowed_networks unveraendert."
    fi
fi

if [[ -n "${GUI_NET}" ]]; then
    RES="$(php -r '
        $f=$argv[1]; $net=$argv[2];
        $c=json_decode(file_get_contents($f),true);
        if($c===null){fwrite(STDERR,"config.json ungueltig"); exit(1);}
        if(!isset($c["access_control"])||!is_array($c["access_control"])) $c["access_control"]=[];
        $list=$c["access_control"]["allowed_networks"]??[];
        if(!is_array($list)) $list=[];
        if(in_array($net,$list,true)){ echo "present"; exit(0); }
        $list[]=$net;
        $c["access_control"]["allowed_networks"]=array_values($list);
        file_put_contents($f,json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
        echo "added";
    ' "${WWW}/config.json" "${GUI_NET}" 2>/dev/null)" || warn "config.json konnte nicht aktualisiert werden."
    case "${RES}" in
        added)   chown "${APACHE_USER}:${APACHE_USER}" "${WWW}/config.json"; chmod 664 "${WWW}/config.json"
                 ok "Netz ${GUI_NET} zu allowed_networks hinzugefuegt" ;;
        present) ok "Netz ${GUI_NET} bereits in allowed_networks - nichts zu tun" ;;
        *)       warn "Aktualisierung von allowed_networks unklar (Ergebnis: '${RES:-leer}')" ;;
    esac
fi

# ---------------------------------------------------------------------------
# 5. Apache-VirtualHosts
# ---------------------------------------------------------------------------
step "Apache-VHosts installieren"
install -m 644 "${SCRIPT_DIR}/apache/pms-middleware.conf"  "${HTTPD_CONFD}/pms-middleware.conf"
install -m 644 "${SCRIPT_DIR}/apache/port80-restrict.conf" "${HTTPD_CONFD}/port80-restrict.conf"
install -m 644 "${SCRIPT_DIR}/apache/pms-listen.conf"      "${HTTPD_CONFD}/pms-listen.conf"
ok "pms-middleware.conf, port80-restrict.conf, pms-listen.conf -> ${HTTPD_CONFD}"

# httpd.conf soll NUR 'Listen 80' enthalten (PMS-Port kommt aus pms-listen.conf).
# Ein zweites 'Listen 7000' in httpd.conf wuerde mit pms-listen.conf kollidieren.
if grep -qE "^\s*Listen\s+${DEFAULT_PMS_PORT}\b" /etc/httpd/conf/httpd.conf 2>/dev/null; then
    sed -i -E "/^\s*Listen\s+${DEFAULT_PMS_PORT}\b/d" /etc/httpd/conf/httpd.conf
    warn "Doppeltes 'Listen ${DEFAULT_PMS_PORT}' aus httpd.conf entfernt (gehoert in pms-listen.conf)."
fi

httpd -t && ok "apachectl configtest bestanden" || die "Apache-Konfiguration fehlerhaft (siehe httpd -t)."

# ---------------------------------------------------------------------------
# 6. SUID-Helfer kompilieren & installieren
# ---------------------------------------------------------------------------
step "SUID-Helfer kompilieren (fias-ctl, pms-portctl)"
build_suid() {
    local name="$1" srcdir="${SCRIPT_DIR}/$1"
    [[ -f "${srcdir}/${name}.c" ]] || die "${name}.c nicht gefunden in ${srcdir}"
    gcc -O2 -o "${srcdir}/${name}" "${srcdir}/${name}.c"
    install -m 4755 -o root -g root "${srcdir}/${name}" "/usr/local/bin/${name}"
    ok "/usr/local/bin/${name} (root:root, SUID 4755)"
}
build_suid fias-ctl
build_suid pms-portctl

# ---------------------------------------------------------------------------
# 7. systemd-Dienst (FIAS-Daemon)
# ---------------------------------------------------------------------------
step "FIAS-systemd-Dienst installieren"
install -m 644 "${SCRIPT_DIR}/systemd/fias-client.service" /etc/systemd/system/fias-client.service
systemctl daemon-reload
ok "fias-client.service installiert"

# ---------------------------------------------------------------------------
# 8. Cron-Resync
# ---------------------------------------------------------------------------
step "Cron-Resync installieren"
install -m 644 "${SCRIPT_DIR}/cron/pms-middleware" /etc/cron.d/pms-middleware
ok "/etc/cron.d/pms-middleware (Checkout 11:00 / Checkin 11:30, in config.json steuerbar)"

# ---------------------------------------------------------------------------
# 9. Firewall (firewalld)
# ---------------------------------------------------------------------------
# Minimal-ISO hat firewalld aktiv. Port 80 (GUI) und PMS-Interface-Port oeffnen.
step "Firewall konfigurieren"
PMS_PORT="${DEFAULT_PMS_PORT}"
if [[ -f "${WWW}/config.json" ]]; then
    P="$(php -r '$c=json_decode(file_get_contents($argv[1]),true); echo (int)($c["middleware"]["pms_interface_port"] ?? 0);' "${WWW}/config.json" 2>/dev/null || echo 0)"
    [[ "$P" =~ ^[0-9]+$ ]] && (( P >= 1024 && P <= 65535 )) && PMS_PORT="$P"
fi
if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld; then
    firewall-cmd --permanent --add-service=http        >/dev/null 2>&1 || true
    firewall-cmd --permanent --add-port=80/tcp         >/dev/null 2>&1 || true
    firewall-cmd --permanent --add-port=${PMS_PORT}/tcp >/dev/null 2>&1 || true
    firewall-cmd --reload                              >/dev/null 2>&1 || true
    ok "firewalld: Port 80 (GUI) und ${PMS_PORT} (PMS-Interface) freigegeben"
else
    warn "firewalld nicht aktiv - falls eine Firewall laeuft, TCP 80 und ${PMS_PORT} manuell oeffnen."
fi

# ---------------------------------------------------------------------------
# 10. Dienste aktivieren & starten
# ---------------------------------------------------------------------------
step "Dienste aktivieren & starten"
systemctl enable --now httpd        && ok "httpd laeuft"
systemctl enable --now crond        && ok "crond laeuft"
systemctl enable --now fias-client  && ok "fias-client aktiviert (verbindet sich zu Opera FIAS)"

# ---------------------------------------------------------------------------
# Zusammenfassung
# ---------------------------------------------------------------------------
IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
echo
echo "${C_B}============================================================${C_0}"
echo "${C_G}Installation abgeschlossen.${C_0}"
echo "${C_B}============================================================${C_0}"
echo "  GUI:            http://${IP:-<server-ip>}/"
echo "  PMS-Interface:  http://${IP:-<server-ip>}:${PMS_PORT}/api/pms/v2/  (Eintrag im PCS)"
echo
echo "  Dienst-Status:"
for s in httpd fias-client crond; do
    printf "    %-14s %s\n" "$s" "$(systemctl is-active "$s" 2>/dev/null)"
done
echo
echo "  Naechste Schritte:"
echo "    1. GUI oeffnen -> Tab 'Einstellungen':"
echo "       - ProCentric Server (Host/Port/SSL, Client-ID + Secret)"
echo "       - FIAS (Opera-Host/Port, Standard 5091)"
echo "       - Zugriffsbeschraenkung (erlaubte Admin-Netze, CIDR)"
echo "    2. Verbindungstest in der GUI durchfuehren."
if command -v getenforce >/dev/null 2>&1 && [[ "$(getenforce 2>/dev/null)" == "Permissive" ]]; then
    echo
    echo "  ${C_Y}Hinweis:${C_0} SELinux ist Permissive. Fuer 'disabled' einmalig REBOOTEN."
fi
echo
