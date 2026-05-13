<?php
/**
 * PCS TV Resync Script
 *
 * Reads all rooms/TVs from PCS, then performs checkout + checkin for each
 * occupied room. Intended to run via cron at 03:00 daily as fallback
 * when the FIAS interface is down.
 *
 * Usage:
 *   php /var/www/html/resync.php          (from cron)
 *   curl http://localhost/api.php?action=resync   (from GUI)
 */

$configFile = __DIR__ . '/config.json';
$logFile    = __DIR__ . '/logs/api.log';
$dataFile   = __DIR__ . '/data/rooms.json';
$subsFile   = __DIR__ . '/data/subscriptions.json';
$tokenFile  = __DIR__ . '/data/pcs_token.json';

foreach ([__DIR__ . '/logs', __DIR__ . '/data'] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// --- Helpers ---
function loadConfig(): array {
    global $configFile;
    return file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?: []) : [];
}

function loadRooms(): array {
    global $dataFile;
    return file_exists($dataFile) ? (json_decode(file_get_contents($dataFile), true) ?: []) : [];
}

function saveRooms(array $rooms): void {
    global $dataFile;
    file_put_contents($dataFile, json_encode($rooms, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function loadSubscriptions(): array {
    global $subsFile;
    return file_exists($subsFile) ? (json_decode(file_get_contents($subsFile), true) ?: []) : [];
}

function resyncLog(string $direction, string $type, string $message, ?string $raw = null): void {
    global $logFile;
    $entry = json_encode([
        'timestamp' => date('Y-m-d H:i:s.v'),
        'direction' => $direction,
        'type'      => $type,
        'message'   => $message,
        'raw'       => $raw,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

// --- PCS API client (calls PCS's own API with token auth) ---
function getPcsToken(): ?string {
    global $tokenFile;
    $config = loadConfig();
    $pc = $config['procentric'] ?? [];

    // Check cached token
    if (file_exists($tokenFile)) {
        $cached = json_decode(file_get_contents($tokenFile), true);
        if ($cached && isset($cached['access_token'], $cached['expires_at'])) {
            if (time() < $cached['expires_at'] - 60) {
                return $cached['access_token'];
            }
        }
    }

    $host = $pc['host'] ?? '192.168.122.10';
    $port = $pc['port'] ?? 60080;

    $body = json_encode([
        'client_id'     => $pc['client_id'] ?? '',
        'client_secret' => $pc['client_secret'] ?? '',
        'grant_type'    => 'client_credentials',
    ]);

    // Try HTTPS first (PCS typically uses HTTPS), fallback to HTTP
    $schemes = ['https', 'http'];
    $response = '';
    $httpCode = 0;
    $error = '';

    foreach ($schemes as $scheme) {
        $url = "{$scheme}://{$host}:{$port}/api/v2/auth/tokens";
        resyncLog('OUT', 'AUTH', "POST {$url}");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if (!$error && $httpCode === 200) break;
        resyncLog('IN', 'WARN', "{$scheme} fehlgeschlagen: " . ($error ?: "HTTP {$httpCode}"), $response ?: null);
    }

    if ($error || $httpCode !== 200) {
        resyncLog('IN', 'ERROR', "Token-Anfrage fehlgeschlagen: " . ($error ?: "HTTP {$httpCode}"), $response);
        return null;
    }

    $data = json_decode($response, true);
    $token = $data['access_token'] ?? null;

    if ($token) {
        $expiresIn = $data['expires_in'] ?? 604800;
        file_put_contents($tokenFile, json_encode([
            'access_token' => $token,
            'expires_at'   => time() + $expiresIn,
        ]));
        resyncLog('IN', 'AUTH', "PCS-Token erhalten, gültig für {$expiresIn}s");
    }

    return $token;
}

function pcsApiGet(string $endpoint): ?array {
    $config = loadConfig();
    $pc = $config['procentric'] ?? [];
    $token = getPcsToken();

    if (!$token) {
        resyncLog('OUT', 'ERROR', 'Kein PCS-Token verfügbar, kann PCS-API nicht aufrufen');
        return null;
    }

    $host = $pc['host'] ?? '192.168.122.10';
    $port = $pc['port'] ?? 60080;

    // Try HTTPS first, fallback HTTP
    $schemes = ['https', 'http'];
    $response = '';
    $httpCode = 0;

    foreach ($schemes as $scheme) {
        $url = "{$scheme}://{$host}:{$port}/api/v2{$endpoint}";
        resyncLog('OUT', 'PCS-API', "GET {$url}");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if (!$error && $httpCode > 0) {
            resyncLog('IN', "HTTP {$httpCode}", "PCS-API Antwort von {$url}", $response);
            break;
        }
        resyncLog('IN', 'WARN', "{$scheme} PCS-API fehlgeschlagen: {$error}");
    }

    if ($httpCode === 401) {
        // Token expired, delete cache and retry once
        global $tokenFile;
        @unlink($tokenFile);
        resyncLog('OUT', 'AUTH', 'Token abgelaufen, erneuere...');
        $token = getPcsToken();
        if (!$token) return null;

        // Retry with new token (use last successful scheme)
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    return json_decode($response, true);
}

// --- Push event to PCS callback ---
function pushEvent(array $eventPayload): array {
    $subs = loadSubscriptions();
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
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . ($sub['callbackToken'] ?? ''),
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        $results[] = [
            'callback' => $callbackUri,
            'success'  => !$error && $httpCode >= 200 && $httpCode < 300,
            'http_code'=> $httpCode,
            'error'    => $error ?: null,
        ];
    }

    return $results;
}

// --- Check if FIAS is reachable ---
function isFiasUp(): bool {
    $config = loadConfig();
    $fias = $config['fias'] ?? [];
    $host = $fias['host'] ?? '';
    $port = $fias['port'] ?? 5010;

    if (!$host) return false;

    $fp = @fsockopen($host, $port, $errno, $errstr, 5);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}

// ============================================================
// MAIN RESYNC LOGIC
// ============================================================
function runResync(bool $force = false): array {
    $config = loadConfig();
    $resyncConfig = $config['resync'] ?? [];

    // Check if feature is enabled
    if (!($resyncConfig['enabled'] ?? false)) {
        $msg = 'TV-Resync ist in der Konfiguration deaktiviert';
        resyncLog('OUT', 'RESYNC', $msg);
        return ['success' => false, 'message' => $msg];
    }

    // Check FIAS status (skip resync if FIAS is up, unless forced)
    if (!$force) {
        $fiasUp = isFiasUp();
        if ($fiasUp) {
            $msg = 'FIAS-Interface ist erreichbar, Resync nicht erforderlich';
            resyncLog('OUT', 'RESYNC', $msg);
            return ['success' => true, 'message' => $msg, 'skipped' => true];
        }
        resyncLog('OUT', 'RESYNC', 'FIAS-Interface ist NICHT erreichbar, starte TV-Resync...');
    } else {
        resyncLog('OUT', 'RESYNC', 'Manueller Resync gestartet (FIAS-Check übersprungen)');
    }

    // 1) Read all rooms from PCS
    resyncLog('OUT', 'RESYNC', 'Lese alle Zimmer von PCS...');
    $pcsRooms = pcsApiGet('/rooms');

    if (!$pcsRooms || ($pcsRooms['status'] ?? '') !== 'success') {
        $msg = 'Konnte Zimmerliste nicht von PCS abrufen';
        resyncLog('IN', 'ERROR', $msg, json_encode($pcsRooms));
        return ['success' => false, 'message' => $msg];
    }

    $roomList = $pcsRooms['data'] ?? [];
    $totalRooms = count($roomList);
    resyncLog('OUT', 'RESYNC', "{$totalRooms} Zimmer von PCS gelesen");

    $checkedOut = 0;
    $checkedIn  = 0;
    $errors     = 0;
    $localRooms = [];
    $eventId    = time();

    foreach ($roomList as $room) {
        $roomId    = $room['id'] ?? '';
        $guests    = $room['guests'] ?? [];
        $groupCode = $room['groupCode'] ?? '0';
        $tvs       = $room['tvs'] ?? [];

        if (!$roomId) continue;

        // Skip empty rooms
        if (empty($guests)) {
            resyncLog('OUT', 'RESYNC', "Zimmer {$roomId}: leer, übersprungen");
            continue;
        }

        foreach ($guests as $guest) {
            $guestId   = $guest['id'] ?? ($roomId . '_1');
            $lastName  = $guest['lastName'] ?? $guest['name']['last'] ?? 'Guest';
            $firstName = $guest['firstName'] ?? $guest['name']['first'] ?? '';
            $language  = $guest['language'] ?? 'en';
            $email     = $guest['email'] ?? $guest['e-mail'] ?? null;

            // --- CHECKOUT ---
            $eventId++;
            $checkoutPayload = [
                'data' => [
                    'events' => [[
                        'id'       => $eventId,
                        'created'  => date('c'),
                        'type'     => 'checkout',
                        'checkout' => [
                            'id'    => $guestId,
                            'room'  => $roomId,
                            'guest' => $guestId,
                        ],
                    ]],
                ],
            ];

            resyncLog('OUT', 'RESYNC', "Zimmer {$roomId}: Check-Out {$firstName} {$lastName}");
            $coResult = pushEvent($checkoutPayload);
            $coOk = !empty($coResult) && ($coResult[0]['success'] ?? false);

            if ($coOk) {
                $checkedOut++;
            } else {
                $errors++;
                resyncLog('IN', 'ERROR', "Check-Out fehlgeschlagen für Zimmer {$roomId}", json_encode($coResult));
            }

            // Small delay between checkout and checkin
            usleep(500000); // 500ms

            // --- CHECKIN ---
            $eventId++;
            $checkinPayload = [
                'data' => [
                    'events' => [[
                        'id'      => $eventId,
                        'created' => date('c'),
                        'type'    => 'checkin',
                        'checkin' => [
                            'id'        => $guestId,
                            'room'      => $roomId,
                            'guest'     => $guestId,
                            'groupCode' => $groupCode,
                            'payment'   => $guest['payment'] ?? 'cash',
                            'name'      => [
                                'title'  => $guest['title'] ?? null,
                                'first'  => $firstName,
                                'last'   => $lastName,
                                'middle' => null,
                                'prefix' => null,
                                'suffix' => null,
                            ],
                            'e-mail'     => $email,
                            'language'   => $language,
                            'vip_status' => $guest['vipStatus'] ?? $guest['vip_status'] ?? null,
                            'no_post'    => $guest['no_post'] ?? false,
                            'source'     => ['type' => null],
                            'group'      => [
                                'number' => $guest['groupNumber'] ?? null,
                                'code'   => $guest['groupCode'] ?? null,
                            ],
                            'affinity'   => [
                                'member' => $guest['affinityMember'] ?? null,
                                'status' => $guest['affinityStatus'] ?? null,
                            ],
                        ],
                    ]],
                ],
            ];

            resyncLog('OUT', 'RESYNC', "Zimmer {$roomId}: Check-In {$firstName} {$lastName} ({$language})");
            $ciResult = pushEvent($checkinPayload);
            $ciOk = !empty($ciResult) && ($ciResult[0]['success'] ?? false);

            if ($ciOk) {
                $checkedIn++;
            } else {
                $errors++;
                resyncLog('IN', 'ERROR', "Check-In fehlgeschlagen für Zimmer {$roomId}", json_encode($ciResult));
            }

            // Update local room data
            $localRooms[$roomId] = [
                'id'           => $roomId,
                'groupCode'    => $groupCode,
                'deploymentId' => $room['deploymentId'] ?? 1,
                'guests'       => [],
            ];
            $localRooms[$roomId]['guests'][] = [
                'id'   => $guestId,
                'name' => [
                    'prefix' => null,
                    'first'  => $firstName,
                    'middle' => null,
                    'last'   => $lastName,
                    'suffix' => null,
                    'full'   => trim("{$firstName} {$lastName}"),
                ],
                'language'           => $language,
                'email'              => $email,
                'balance'            => null,
                'no_post'            => $guest['no_post'] ?? null,
                'vip_status'         => $guest['vipStatus'] ?? $guest['vip_status'] ?? null,
                'payment'            => $guest['payment'] ?? 'cash',
                'option'             => null,
                'channel_preference' => null,
            ];

            // Throttle between guests
            usleep(300000); // 300ms
        }
    }

    // Save synced room data locally
    saveRooms($localRooms);

    $summary = "TV-Resync abgeschlossen: {$checkedOut} Check-Outs, {$checkedIn} Check-Ins, {$errors} Fehler ({$totalRooms} Zimmer von PCS)";
    resyncLog('OUT', 'RESYNC', $summary);

    return [
        'success'     => $errors === 0,
        'message'     => $summary,
        'total_rooms' => $totalRooms,
        'checked_out' => $checkedOut,
        'checked_in'  => $checkedIn,
        'errors'      => $errors,
    ];
}

// --- CLI execution (for cron) ---
if (php_sapi_name() === 'cli') {
    $force = in_array('--force', $argv ?? []);
    $result = runResync($force);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($result['success'] ? 0 : 1);
}
