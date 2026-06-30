<?php
/**
 * IP-Zugriffsschutz fuer die Web-GUI (Port 80).
 *
 * Liest die erlaubten Netze aus config.json (access_control.allowed_networks)
 * und blockt Anfragen von nicht erlaubten Adressen mit HTTP 403.
 *
 * Wird von index.php und api.php eingebunden. Gilt NICHT fuer pms_router.php
 * (Port 7000 / PCS bleibt offen).
 *
 * Aussperr-Schutz:
 *   - Loopback (127.0.0.1 / ::1) ist immer erlaubt.
 *   - Ist keine Liste konfiguriert (leer/fehlt), wird NICHT gesperrt (fail-open).
 */

if (!function_exists('ac_ip_in_cidr')) {

    /**
     * Prueft, ob $ip im Netz $cidr liegt. Unterstuetzt IPv4 und IPv6.
     * Eine reine Adresse ohne Maske gilt als /32 (IPv4) bzw. /128 (IPv6).
     */
    function ac_ip_in_cidr(string $ip, string $cidr): bool {
        $cidr = trim($cidr);
        if ($cidr === '') return false;

        if (strpos($cidr, '/') === false) {
            $cidr .= (strpos($cidr, ':') !== false) ? '/128' : '/32';
        }

        [$subnet, $bitsStr] = explode('/', $cidr, 2);
        $bits = (int)$bitsStr;

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton(trim($subnet));
        if ($ipBin === false || $subnetBin === false) return false;

        // Adressfamilien muessen uebereinstimmen (beide IPv4 oder beide IPv6)
        if (strlen($ipBin) !== strlen($subnetBin)) return false;

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) return false;
        if ($bits === 0) return true;

        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) return false;

        if ($rem > 0) {
            $mask = (~((1 << (8 - $rem)) - 1)) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) !== (ord($subnetBin[$bytes]) & $mask)) {
                return false;
            }
        }
        return true;
    }

    /** Prueft eine Adresse gegen eine Liste von Netzen/Adressen. */
    function ac_ip_allowed(string $ip, array $networks): bool {
        foreach ($networks as $net) {
            if (is_string($net) && ac_ip_in_cidr($ip, $net)) return true;
        }
        return false;
    }

    /**
     * Erzwingt die Zugriffsbeschraenkung. Beendet das Skript mit 403,
     * wenn die Client-Adresse nicht erlaubt ist.
     */
    function ac_enforce_access(string $configFile): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        // Loopback immer erlauben (lokaler Zugriff / Aussperr-Schutz)
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') return;

        $cfg = file_exists($configFile)
            ? (json_decode(file_get_contents($configFile), true) ?: [])
            : [];
        $networks = $cfg['access_control']['allowed_networks'] ?? [];

        // Keine Liste konfiguriert -> nicht aussperren (fail-open)
        if (!is_array($networks) || count($networks) === 0) return;

        if (!ac_ip_allowed($ip, $networks)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "403 Forbidden - Zugriff von {$ip} ist nicht erlaubt.";
            exit;
        }
    }
}
