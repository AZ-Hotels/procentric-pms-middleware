<?php
/**
 * Resync Cron Runner
 * Called by cron with --checkout or --checkin argument.
 * Checks if resync is enabled and FIAS is down before executing.
 */

require_once __DIR__ . '/resync_lib.php';

$action = $argv[1] ?? '';

$config = resync_loadConfig();
$resyncConfig = $config['resync'] ?? [];

if (!($resyncConfig['enabled'] ?? false)) {
    echo "Resync deaktiviert\n";
    exit(0);
}

// Check FIAS daemon status (no TCP connect - would kill daemon connection!)
if ($resyncConfig['only_when_fias_down'] ?? true) {
    $fiasActive = trim(shell_exec('/usr/local/bin/fias-ctl status 2>&1') ?? '');
    if ($fiasActive === 'active') {
        resync_log('OUT', 'RESYNC', 'Cron: FIAS-Daemon aktiv, Resync übersprungen');
        echo "FIAS-Daemon aktiv, Resync übersprungen\n";
        exit(0);
    }
    resync_log('OUT', 'RESYNC', 'Cron: FIAS-Daemon nicht aktiv, starte Resync');
}

if ($action === '--checkout') {
    $result = resync_checkout_all();
} elseif ($action === '--checkin') {
    $result = resync_checkin_all();
} else {
    echo "Usage: php resync_cron.php --checkout|--checkin\n";
    exit(1);
}

echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
exit($result['success'] ? 0 : 1);
