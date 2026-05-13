<?php
/**
 * Middleware API - Backend for the Web GUI
 * Handles check-in/check-out commands from the GUI, stores room data locally,
 * and pushes events to PCS via the registered subscription callback.
 */

header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/config.json';
$logFile    = __DIR__ . '/logs/api.log';
$dataFile   = __DIR__ . '/data/rooms.json';
$subsFile   = __DIR__ . '/data/subscriptions.json';

foreach ([__DIR__ . '/logs', __DIR__ . '/data'] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// --- Config helpers ---
function loadConfig(): array {
    global $configFile;
    return file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?: []) : [];
}

function saveConfig(array $config): void {
    global $configFile;
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// --- Room data ---
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

// --- Logging ---
function apiLog(string $direction, string $type, string $message, ?string $raw = null): void {
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

// --- Push event to PCS via subscription callback ---
function pushEventToPCS(array $eventPayload): array {
    $subs = loadSubscriptions();
    if (empty($subs)) {
        apiLog('OUT', 'WARN', 'Kein PCS-Subscriber registriert, Event wird nur lokal gespeichert');
        return ['success' => false, 'error' => 'Kein PCS-Subscriber registriert'];
    }

    $results = [];
    foreach ($subs as $sub) {
        $callbackUri = $sub['callbackUri'] ?? '';
        if (!$callbackUri) continue;

        $jsonBody = json_encode($eventPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $evType = $eventPayload['data']['events'][0]['type'] ?? '?';
        $evRoom = $eventPayload['data']['events'][0][$evType]['room'] ?? '?';
        apiLog('OUT', 'EVENT->PCS', "{$evType} Zimmer {$evRoom} -> {$callbackUri}", $jsonBody);

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
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            apiLog('IN', 'ERROR', "PCS callback error: {$error}");
            $results[] = ['callback' => $callbackUri, 'success' => false, 'error' => $error];
        } else {
            $pcsStatus = json_decode($response, true)['status'] ?? '?';
            apiLog('IN', "HTTP {$httpCode}", "PCS callback: {$pcsStatus} ({$callbackUri})", $response);
            $results[] = ['callback' => $callbackUri, 'success' => ($httpCode >= 200 && $httpCode < 300), 'http_code' => $httpCode];
        }
    }

    return ['success' => true, 'callbacks' => $results];
}

// --- Route handling ---
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;
}

switch ($action) {

    // === Check-In ===
    case 'checkin':
        $room      = trim($input['room'] ?? '');
        $lastName  = trim($input['last_name'] ?? '');
        $firstName = trim($input['first_name'] ?? '');
        $language  = trim($input['language'] ?? 'en');
        $guestId   = trim($input['guest_id'] ?? ($room . '_1'));

        if (!$room || !$lastName) {
            echo json_encode(['success' => false, 'error' => 'Zimmernummer und Nachname sind erforderlich']);
            exit;
        }

        // 1) Store in local room data
        $rooms = loadRooms();
        $guestObj = [
            'id'       => $guestId,
            'name'     => [
                'prefix' => null,
                'first'  => $firstName ?: null,
                'middle' => null,
                'last'   => $lastName,
                'suffix' => null,
                'full'   => trim("{$firstName} {$lastName}"),
            ],
            'language'           => $language,
            'email'              => null,
            'balance'            => null,
            'no_post'            => null,
            'vip_status'         => null,
            'payment'            => 'cash',
            'option'             => null,
            'channel_preference' => null,
        ];

        if (!isset($rooms[$room])) {
            $rooms[$room] = ['id' => $room, 'groupCode' => '0', 'deploymentId' => 1, 'guests' => []];
        }
        // Replace existing guest with same ID or add new
        $found = false;
        foreach ($rooms[$room]['guests'] as &$g) {
            if ($g['id'] === $guestId) {
                $g = $guestObj;
                $found = true;
                break;
            }
        }
        unset($g);
        if (!$found) {
            $rooms[$room]['guests'][] = $guestObj;
        }
        saveRooms($rooms);

        apiLog('OUT', 'CHECKIN', "Zimmer {$room}: {$firstName} {$lastName} ({$language})", json_encode($guestObj));

        // 2) Push checkin event to PCS callback
        $eventPayload = [
            'data' => [
                'events' => [[
                    'id'      => time(),
                    'created' => date('c'),
                    'type'    => 'checkin',
                    'checkin' => [
                        'id'        => $guestId,
                        'room'      => $room,
                        'guest'     => $guestId,
                        'groupCode' => '0',
                        'payment'   => 'cash',
                        'name'      => [
                            'title'  => null,
                            'first'  => $firstName ?: null,
                            'last'   => $lastName,
                            'middle' => null,
                            'prefix' => null,
                            'suffix' => null,
                        ],
                        'e-mail'     => null,
                        'language'   => $language,
                        'vip_status' => null,
                        'no_post'    => false,
                        'source'     => ['type' => null],
                        'group'      => ['number' => null, 'code' => null],
                        'affinity'   => ['member' => null, 'status' => null],
                    ],
                ]],
            ],
        ];
        $pushResult = pushEventToPCS($eventPayload);

        echo json_encode([
            'success'      => true,
            'action'       => 'checkin',
            'room'         => $room,
            'guest'        => "{$firstName} {$lastName}",
            'language'     => $language,
            'push_result'  => $pushResult,
        ]);
        break;

    // === Check-Out ===
    case 'checkout':
        $room    = trim($input['room'] ?? '');
        $guestId = trim($input['guest_id'] ?? ($room . '_1'));

        if (!$room) {
            echo json_encode(['success' => false, 'error' => 'Zimmernummer ist erforderlich']);
            exit;
        }

        // 1) Remove from local room data
        $rooms = loadRooms();
        $guestName = '';
        if (isset($rooms[$room])) {
            foreach ($rooms[$room]['guests'] ?? [] as $g) {
                if ($g['id'] === $guestId) {
                    $guestName = trim(($g['name']['first'] ?? '') . ' ' . ($g['name']['last'] ?? ''));
                    break;
                }
            }
            $rooms[$room]['guests'] = array_values(array_filter(
                $rooms[$room]['guests'] ?? [],
                fn($g) => ($g['id'] ?? '') !== $guestId
            ));
            if (empty($rooms[$room]['guests'])) {
                unset($rooms[$room]);
            }
        }
        saveRooms($rooms);

        apiLog('OUT', 'CHECKOUT', "Zimmer {$room}: {$guestName} (Gast-ID: {$guestId})");

        // 2) Push checkout event to PCS callback
        $eventPayload = [
            'data' => [
                'events' => [[
                    'id'       => time(),
                    'created'  => date('c'),
                    'type'     => 'checkout',
                    'checkout' => [
                        'id'    => $guestId,
                        'room'  => $room,
                        'guest' => $guestId,
                    ],
                ]],
            ],
        ];
        $pushResult = pushEventToPCS($eventPayload);

        echo json_encode([
            'success'     => true,
            'action'      => 'checkout',
            'room'        => $room,
            'guest'       => $guestName,
            'push_result' => $pushResult,
        ]);
        break;

    // === Room Status (local) ===
    case 'room_status':
        $room = trim($_GET['room'] ?? '');
        if (!$room) {
            echo json_encode(['success' => false, 'error' => 'Zimmernummer erforderlich']);
            exit;
        }
        $rooms = loadRooms();
        if (isset($rooms[$room]) && !empty($rooms[$room]['guests'])) {
            echo json_encode([
                'success' => true,
                'data' => $rooms[$room],
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => "Zimmer {$room} ist nicht belegt",
            ]);
        }
        break;

    // === List all occupied rooms ===
    case 'list_rooms':
        $rooms = loadRooms();
        echo json_encode(['success' => true, 'data' => $rooms]);
        break;

    // === Connection Check (PCS calls our /check) ===
    case 'check_connection':
        // Test if PCS can reach us by checking our own port 7000
        $config = loadConfig();
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'http://127.0.0.1:7000/api/pms/v2/statuses',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        $localOk = ($httpCode === 200);

        // Also check PCS reachability
        $pc = $config['procentric'] ?? [];
        $pcsHost = $pc['host'] ?? '192.168.122.10';
        $pcsPort = $pc['port'] ?? 60080;
        $pcsOk = false;
        $fp = @fsockopen($pcsHost, $pcsPort, $errno, $errstr, 3);
        if ($fp) {
            $pcsOk = true;
            fclose($fp);
        }

        // Nicht loggen - wird alle 30s von GUI gepollt

        echo json_encode([
            'success'       => $localOk,
            'local_api'     => $localOk ? 'OK' : 'FAIL',
            'pcs_reachable' => $pcsOk ? 'OK' : 'FAIL',
            'pcs_host'      => "{$pcsHost}:{$pcsPort}",
            'subscriptions' => count(loadSubscriptions()),
        ]);
        break;

    // === Get Config ===
    case 'get_config':
        echo json_encode(loadConfig());
        break;

    // === Save Config ===
    case 'save_config':
        if (empty($input)) {
            echo json_encode(['success' => false, 'error' => 'Keine Konfigurationsdaten']);
            exit;
        }
        unset($input['action']);
        saveConfig($input);
        apiLog('OUT', 'CONFIG', 'Konfiguration gespeichert', json_encode($input));
        echo json_encode(['success' => true, 'message' => 'Konfiguration gespeichert']);
        break;

    // === FIAS Status ===
    case 'fias_status':
        $fiasConf = loadConfig()['fias'] ?? [];
        $fiasHost = $fiasConf['host'] ?? '';
        $fiasPort = (int)($fiasConf['port'] ?? 5010);

        // Check systemd service - NO TCP connect (would kill daemon connection!)
        $statusOut = [];
        exec('/usr/local/bin/fias-ctl status 2>&1', $statusOut);
        $fiasState = trim($statusOut[0] ?? '');
        $fiasRunning = in_array($fiasState, ['active', 'activating']);

        $fiasPid = null;
        if ($fiasRunning) {
            $pidOut = [];
            exec('/usr/local/bin/fias-ctl pid 2>&1', $pidOut);
            $fiasPid = (int)trim($pidOut[0] ?? '0') ?: null;
        }

        echo json_encode(['success' => true, 'data' => [
            'host'   => "{$fiasHost}:{$fiasPort}",
            'daemon' => $fiasRunning,
            'pid'    => $fiasPid,
        ]]);
        break;

    // === FIAS Start ===
    case 'fias_start':
        exec('/usr/local/bin/fias-ctl start 2>&1', $out, $rc);
        sleep(2);
        $statusOut = [];
        exec('/usr/local/bin/fias-ctl status 2>&1', $statusOut);
        $active = in_array(trim($statusOut[0] ?? ''), ['active', 'activating']);
        apiLog('OUT', 'FIAS', 'FIAS-Service gestartet');
        echo json_encode(['success' => $active, 'message' => $active ? 'FIAS-Service gestartet' : 'FIAS-Service konnte nicht gestartet werden']);
        break;

    // === FIAS Stop ===
    case 'fias_stop':
        exec('/usr/local/bin/fias-ctl stop 2>&1', $out, $rc);
        sleep(1);
        $statusOut = [];
        exec('/usr/local/bin/fias-ctl status 2>&1', $statusOut);
        $stopped = trim($statusOut[0] ?? '') === 'inactive';
        apiLog('OUT', 'FIAS', 'FIAS-Service gestoppt');
        echo json_encode(['success' => $stopped, 'message' => $stopped ? 'FIAS-Service gestoppt' : 'Stop fehlgeschlagen: ' . trim($statusOut[0] ?? '')]);
        break;

    // === Resync: Checkout all ===
    // === Major Errors ===
    case 'get_errors':
        $errorFile = __DIR__ . '/data/major_errors.json';
        $errors = [];
        if (file_exists($errorFile)) {
            $errors = json_decode(file_get_contents($errorFile), true) ?: [];
        }
        echo json_encode(['success' => true, 'data' => $errors, 'count' => count($errors)]);
        break;

    case 'clear_errors':
        $errorFile = __DIR__ . '/data/major_errors.json';
        file_put_contents($errorFile, '[]');
        apiLog('OUT', 'ERRORS', 'Major-Error-Liste gelöscht');
        echo json_encode(['success' => true, 'message' => 'Error-Liste gelöscht']);
        break;

    // === FIAS DB-Swap Request ===
    case 'fias_dbswap':
        $swapFile = __DIR__ . '/data/fias_dbswap.flag';
        file_put_contents($swapFile, time());
        apiLog('OUT', 'FIAS', 'DB-Swap angefordert');
        echo json_encode(['success' => true, 'message' => 'DB-Swap Anforderung gesendet']);
        break;

    case 'resync_checkout':
        require_once __DIR__ . '/resync_lib.php';
        $result = resync_checkout_all();
        echo json_encode($result);
        break;

    // === Resync: Checkin all (pseudo data) ===
    case 'resync_checkin':
        require_once __DIR__ . '/resync_lib.php';
        $result = resync_checkin_all();
        echo json_encode($result);
        break;

    // === Get Logs ===
    case 'get_logs':
        $maxLines = (int)($_GET['max'] ?? 200);
        $entries = [];
        if (file_exists($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_slice($lines, -$maxLines);
            foreach ($lines as $line) {
                $entry = json_decode($line, true);
                if ($entry) {
                    $entries[] = $entry;
                }
            }
        }
        echo json_encode($entries);
        break;

    // === Clear Logs ===
    case 'clear_logs':
        if (file_exists($logFile)) {
            file_put_contents($logFile, '');
        }
        echo json_encode(['success' => true, 'message' => 'Logs gelöscht']);
        break;

    default:
        echo json_encode(['success' => false, 'error' => "Unbekannte Aktion: {$action}"]);
        break;
}
