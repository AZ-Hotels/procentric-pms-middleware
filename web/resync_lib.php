<?php
/**
 * Resync Library - Checkout all rooms, then Checkin with pseudo data.
 * Uses the PCS subscription callback (no PCS API connection needed).
 * Room list comes from config (rooms that PCS knows about).
 */

$resync_configFile = __DIR__ . '/config.json';
$resync_logFile    = __DIR__ . '/logs/api.log';
$resync_dataFile   = __DIR__ . '/data/rooms.json';
$resync_subsFile   = __DIR__ . '/data/subscriptions.json';

function resync_loadConfig(): array {
    global $resync_configFile;
    return file_exists($resync_configFile) ? (json_decode(file_get_contents($resync_configFile), true) ?: []) : [];
}

function resync_loadSubscriptions(): array {
    global $resync_subsFile;
    return file_exists($resync_subsFile) ? (json_decode(file_get_contents($resync_subsFile), true) ?: []) : [];
}

function resync_saveRooms(array $rooms): void {
    global $resync_dataFile;
    file_put_contents($resync_dataFile, json_encode($rooms, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function resync_log(string $direction, string $type, string $message, ?string $raw = null): void {
    global $resync_logFile;
    $entry = json_encode([
        'timestamp' => date('Y-m-d H:i:s.v'),
        'direction' => $direction,
        'type'      => $type,
        'message'   => $message,
        'raw'       => $raw,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents($resync_logFile, $entry, FILE_APPEND | LOCK_EX);
}

function resync_pushEvent(array $eventPayload): array {
    $subs = resync_loadSubscriptions();
    $results = [];
    foreach ($subs as $sub) {
        $callbackUri = $sub['callbackUri'] ?? '';
        if (!$callbackUri) continue;
        $jsonBody = json_encode($eventPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $callbackUri,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . ($sub['callbackToken'] ?? '')],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
        $results[] = ['callback' => $callbackUri, 'success' => !$error && $httpCode >= 200 && $httpCode < 300, 'http_code' => $httpCode, 'error' => $error ?: null];
    }
    return $results;
}

// === Checkout all rooms ===
function resync_checkout_all(): array {
    $config = resync_loadConfig();
    $resyncConfig = $config['resync'] ?? [];

    if (!($resyncConfig['enabled'] ?? false)) {
        return ['success' => false, 'message' => 'TV-Resync ist deaktiviert'];
    }

    $roomList = $resyncConfig['rooms'] ?? [];
    if (empty($roomList)) {
        return ['success' => false, 'message' => 'Keine Zimmer in der Resync-Konfiguration'];
    }

    resync_log('OUT', 'RESYNC', 'Starte Massen-Checkout für ' . count($roomList) . ' Zimmer');

    $ok = 0;
    $fail = 0;
    $eventId = time();

    foreach ($roomList as $roomId) {
        $roomId = trim((string)$roomId);
        if (!$roomId) continue;

        $eventId++;
        $guestId = $roomId . '_1';

        resync_log('OUT', 'RESYNC-CO', "Zimmer {$roomId}: Check-Out");
        $result = resync_pushEvent([
            'data' => ['events' => [[
                'id'       => $eventId,
                'created'  => date('c'),
                'type'     => 'checkout',
                'checkout' => [
                    'id'    => $guestId,
                    'room'  => $roomId,
                    'guest' => $guestId,
                ],
            ]]],
        ]);

        if (!empty($result) && ($result[0]['success'] ?? false)) {
            $ok++;
        } else {
            $fail++;
            resync_log('IN', 'ERROR', "Checkout Zimmer {$roomId} fehlgeschlagen", json_encode($result));
        }
        usleep(300000);
    }

    // Clear local room data
    resync_saveRooms([]);

    $summary = "Massen-Checkout: {$ok} OK, {$fail} Fehler (von " . count($roomList) . " Zimmern)";
    resync_log('OUT', 'RESYNC', $summary);
    return ['success' => $fail === 0, 'message' => $summary, 'ok' => $ok, 'fail' => $fail];
}

// === Checkin all rooms with pseudo data ===
function resync_checkin_all(): array {
    $config = resync_loadConfig();
    $resyncConfig = $config['resync'] ?? [];

    if (!($resyncConfig['enabled'] ?? false)) {
        return ['success' => false, 'message' => 'TV-Resync ist deaktiviert'];
    }

    $roomList  = $resyncConfig['rooms'] ?? [];
    $pseudoFirst = $resyncConfig['pseudo_first_name'] ?? 'Max';
    $pseudoLast  = $resyncConfig['pseudo_last_name'] ?? 'Mustermann';
    $pseudoLang  = $resyncConfig['pseudo_language'] ?? 'de';

    if (empty($roomList)) {
        return ['success' => false, 'message' => 'Keine Zimmer in der Resync-Konfiguration'];
    }

    resync_log('OUT', 'RESYNC', "Starte Massen-Checkin für " . count($roomList) . " Zimmer ({$pseudoFirst} {$pseudoLast}, {$pseudoLang})");

    $ok = 0;
    $fail = 0;
    $eventId = time();
    $localRooms = [];

    foreach ($roomList as $roomId) {
        $roomId = trim((string)$roomId);
        if (!$roomId) continue;

        $eventId++;
        $guestId = $roomId . '_1';

        resync_log('OUT', 'RESYNC-CI', "Zimmer {$roomId}: Check-In {$pseudoFirst} {$pseudoLast}");
        $result = resync_pushEvent([
            'data' => ['events' => [[
                'id'      => $eventId,
                'created' => date('c'),
                'type'    => 'checkin',
                'checkin' => [
                    'id'        => $guestId,
                    'room'      => $roomId,
                    'guest'     => $guestId,
                    'groupCode' => '0',
                    'payment'   => 'cash',
                    'name'      => [
                        'title' => null, 'first' => $pseudoFirst, 'last' => $pseudoLast,
                        'middle' => null, 'prefix' => null, 'suffix' => null,
                    ],
                    'e-mail'     => null,
                    'language'   => $pseudoLang,
                    'vip_status' => null,
                    'no_post'    => false,
                    'source'     => ['type' => null],
                    'group'      => ['number' => null, 'code' => null],
                    'affinity'   => ['member' => null, 'status' => null],
                ],
            ]]],
        ]);

        if (!empty($result) && ($result[0]['success'] ?? false)) {
            $ok++;
        } else {
            $fail++;
            resync_log('IN', 'ERROR', "Checkin Zimmer {$roomId} fehlgeschlagen", json_encode($result));
        }

        // Update local rooms
        $localRooms[$roomId] = [
            'id' => $roomId, 'groupCode' => '0', 'deploymentId' => 1,
            'guests' => [[
                'id' => $guestId,
                'name' => ['prefix' => null, 'first' => $pseudoFirst, 'middle' => null, 'last' => $pseudoLast, 'suffix' => null, 'full' => "{$pseudoFirst} {$pseudoLast}"],
                'language' => $pseudoLang, 'email' => null, 'balance' => null, 'no_post' => null,
                'vip_status' => null, 'payment' => 'cash', 'option' => null, 'channel_preference' => null,
            ]],
        ];
        usleep(300000);
    }

    resync_saveRooms($localRooms);

    $summary = "Massen-Checkin: {$ok} OK, {$fail} Fehler (von " . count($roomList) . " Zimmern)";
    resync_log('OUT', 'RESYNC', $summary);
    return ['success' => $fail === 0, 'message' => $summary, 'ok' => $ok, 'fail' => $fail];
}
