<?php
/**
 * FIAS TCP Client Daemon
 *
 * Connects to Fidelio Opera PMS via FIAS protocol over TCP.
 * Listens for GI (Guest Check-In), GO (Guest Check-Out), GC (Guest Change)
 * events and forwards them to PCS via the registered subscription callback.
 *
 * Usage:
 *   php /var/www/html/fias_client.php              (foreground)
 *   php /var/www/html/fias_client.php --daemon      (background)
 *   php /var/www/html/fias_client.php --test        (connect, send LS, print messages, exit after 30s)
 */

declare(strict_types=1);

$configFile = __DIR__ . '/config.json';
$logFile    = __DIR__ . '/logs/api.log';
$dataFile   = __DIR__ . '/data/rooms.json';
$subsFile   = __DIR__ . '/data/subscriptions.json';
$pidFile    = __DIR__ . '/data/fias.pid';

foreach ([__DIR__ . '/logs', __DIR__ . '/data'] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// --- Constants ---
const STX = "\x02";
const ETX = "\x03";
const ACK = "\x06";
const NAK = "\x15";
const FIELD_SEP = '|';

// --- FIAS field code labels ---
const FIAS_FIELDS = [
    'RN' => 'Room Number',
    'GN' => 'Guest Name (Last)',
    'GF' => 'Guest First Name',
    'G#' => 'Guest ID',
    'GL' => 'Guest Language',
    'GG' => 'Guest Group',
    'GA' => 'Arrival Date',
    'GD' => 'Departure Date',
    'GS' => 'VIP Status',
    'GT' => 'Guest Title',
    'NO' => 'No Post',
    'DA' => 'Date',
    'TI' => 'Time',
    'SF' => 'Share Flag',
    'SW' => 'Swap Flag',
    'TA' => 'Total Amount',
    'CT' => 'Transaction Code',
    'SO' => 'Source',
    'AS' => 'Answer Status',
    'MI' => 'Minibar',
    'FL' => 'Floor',
    'FN' => 'Full Name',
];

const FIAS_RECORDS = [
    'LS' => 'Link Start',
    'LD' => 'Link Description',
    'LA' => 'Link Alive',
    'LR' => 'Link Record',
    'GI' => 'Guest Check-In',
    'GO' => 'Guest Check-Out',
    'GC' => 'Guest Change',
    'DS' => 'Database Resync Start',
    'DE' => 'Database Resync End',
    'DR' => 'Database Resync Record',
    'RE' => 'Room Equipment',
    'PA' => 'Posting Answer',
    'PR' => 'Posting Request',
];

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

function fiasLog(string $direction, string $type, string $message, ?string $raw = null): void {
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

function stdout(string $msg): void {
    $ts = date('H:i:s');
    echo "[{$ts}] {$msg}\n";
}

// --- FIAS message parsing ---
function parseFiasMessage(string $raw): array {
    // Raw is the content between STX and ETX
    $parts = explode(FIELD_SEP, $raw);
    $recordType = $parts[0] ?? '';

    $fields = [];
    for ($i = 1; $i < count($parts); $i++) {
        $part = $parts[$i];
        if (strlen($part) < 2) continue;
        $code  = substr($part, 0, 2);
        $value = substr($part, 2);
        $fields[$code] = $value;
    }

    return [
        'record_type' => $recordType,
        'fields'      => $fields,
        'raw'         => $raw,
    ];
}

function buildFiasMessage(string $recordType, array $fields = []): string {
    $parts = [$recordType];
    foreach ($fields as $code => $value) {
        $parts[] = $code . $value;
    }
    return STX . implode(FIELD_SEP, $parts) . FIELD_SEP . ETX;
}

function fiasFieldSummary(array $fields): string {
    $parts = [];
    foreach ($fields as $code => $value) {
        $label = FIAS_FIELDS[$code] ?? $code;
        $parts[] = "{$label}={$value}";
    }
    return implode(', ', $parts);
}

// --- Push event to PCS ---
function pushEventToPCS(array $eventPayload): void {
    $subs = loadSubscriptions();
    if (empty($subs)) {
        fiasLog('OUT', 'WARN', 'Kein PCS-Subscriber, Event nur lokal gespeichert');
        return;
    }

    $jsonBody = json_encode($eventPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    foreach ($subs as $sub) {
        $callbackUri = $sub['callbackUri'] ?? '';
        if (!$callbackUri) continue;

        $evType = $eventPayload['data']['events'][0]['type'] ?? '?';
        $evRoom = $eventPayload['data']['events'][0][$evType]['room'] ?? '?';
        fiasLog('OUT', 'EVENT->PCS', "{$evType} Zimmer {$evRoom} -> {$callbackUri}", $jsonBody);

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
            fiasLog('IN', 'ERROR', "PCS callback Fehler: {$error}");
        } else {
            $pcsStatus = json_decode($response, true)['status'] ?? '?';
            fiasLog('IN', "HTTP {$httpCode}", "PCS callback: {$pcsStatus}", $response);
        }
    }
}

// --- Major Error List ---
function addMajorError(string $type, string $message, ?string $raw = null): void {
    $errorFile = __DIR__ . '/data/major_errors.json';
    $errors = [];
    if (file_exists($errorFile)) {
        $errors = json_decode(file_get_contents($errorFile), true) ?: [];
    }
    $errors[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'type'      => $type,
        'message'   => $message,
        'raw'       => $raw,
    ];
    // Keep last 500
    if (count($errors) > 500) {
        $errors = array_slice($errors, -500);
    }
    file_put_contents($errorFile, json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fiasLog('IN', 'MAJOR ERROR', $message, $raw);
}

function getKnownRooms(): array {
    $config = loadConfig();
    return $config['resync']['rooms'] ?? [];
}

function validateRoom(string $room, string $recordType, string $raw): bool {
    $knownRooms = getKnownRooms();
    if (empty($knownRooms)) return true; // No room list configured, skip validation
    if (in_array($room, $knownRooms)) return true;

    $label = ($recordType === 'GI') ? 'Check-In' : (($recordType === 'GO') ? 'Check-Out' : $recordType);
    addMajorError('ROOM_MISMATCH', "Unbekanntes Zimmer {$room} bei {$label} - Zimmer existiert nicht in der Konfiguration", $raw);
    stdout("  *** MAJOR ERROR: Zimmer {$room} nicht in Zimmerliste! ***");
    return false;
}

// --- Language translation ---
function translateLanguage(string $fiasLang): string {
    $config = loadConfig();
    $map = $config['fias']['language_map'] ?? [];
    $upper = strtoupper(trim($fiasLang));
    if (isset($map[$upper])) {
        return $map[$upper];
    }
    // Fallback: return lowercase as-is
    return strtolower($fiasLang);
}

// --- Process FIAS guest events ---
function processGuestCheckin(array $msg): void {
    $fields    = $msg['fields'];
    $room      = $fields['RN'] ?? '';
    $lastName  = $fields['GN'] ?? '';
    $firstName = $fields['GF'] ?? '';
    $guestId   = $fields['G#'] ?? ($room . '_1');
    $fiasLang  = $fields['GL'] ?? 'EA';
    $language  = translateLanguage($fiasLang);
    $vipStatus = $fields['GS'] ?? null;
    $title     = $fields['GT'] ?? null;
    $noPost    = ($fields['NO'] ?? '0') === '1';
    $arrival   = $fields['GA'] ?? '';
    $departure = $fields['GD'] ?? '';

    if (!$room) {
        fiasLog('IN', 'WARN', 'GI ohne Zimmernummer ignoriert', $msg['raw']);
        return;
    }

    // Validate room against known list
    validateRoom($room, 'GI', $msg['raw']);

    // Store locally
    $rooms = loadRooms();
    $guestObj = [
        'id'   => $guestId,
        'name' => [
            'prefix' => $title,
            'first'  => $firstName ?: null,
            'middle' => null,
            'last'   => $lastName,
            'suffix' => null,
            'full'   => trim("{$firstName} {$lastName}"),
        ],
        'language'           => $language,
        'email'              => null,
        'balance'            => null,
        'no_post'            => $noPost,
        'vip_status'         => $vipStatus,
        'payment'            => 'cash',
        'option'             => null,
        'channel_preference' => null,
    ];

    if (!isset($rooms[$room])) {
        $rooms[$room] = ['id' => $room, 'groupCode' => '0', 'deploymentId' => 1, 'guests' => []];
    }
    // Replace or add guest
    $found = false;
    foreach ($rooms[$room]['guests'] as &$g) {
        if ($g['id'] === $guestId) { $g = $guestObj; $found = true; break; }
    }
    unset($g);
    if (!$found) $rooms[$room]['guests'][] = $guestObj;
    saveRooms($rooms);

    stdout("CHECK-IN: Zimmer {$room} -> {$firstName} {$lastName} ({$language})");
    fiasLog('IN', 'FIAS GI', "Check-In Zimmer {$room}: {$firstName} {$lastName} ({$language})", $msg['raw']);

    // Push to PCS
    pushEventToPCS([
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
                        'title'  => $title,
                        'first'  => $firstName ?: null,
                        'last'   => $lastName,
                        'middle' => null,
                        'prefix' => null,
                        'suffix' => null,
                    ],
                    'e-mail'     => null,
                    'language'   => $language,
                    'vip_status' => $vipStatus,
                    'no_post'    => $noPost,
                    'source'     => ['type' => null],
                    'group'      => ['number' => null, 'code' => null],
                    'affinity'   => ['member' => null, 'status' => null],
                ],
            ]],
        ],
    ]);
}

function processGuestCheckout(array $msg): void {
    $fields  = $msg['fields'];
    $room    = $fields['RN'] ?? '';
    $guestId = $fields['G#'] ?? ($room . '_1');

    if (!$room) {
        fiasLog('IN', 'WARN', 'GO ohne Zimmernummer ignoriert', $msg['raw']);
        return;
    }

    // Validate room against known list
    validateRoom($room, 'GO', $msg['raw']);

    // Remove from local data
    $rooms = loadRooms();
    $guestName = '';
    if (isset($rooms[$room])) {
        foreach ($rooms[$room]['guests'] ?? [] as $g) {
            if ($g['id'] === $guestId) {
                $guestName = $g['name']['full'] ?? trim(($g['name']['first'] ?? '') . ' ' . ($g['name']['last'] ?? ''));
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
        saveRooms($rooms);
    }

    stdout("CHECK-OUT: Zimmer {$room} ({$guestName})");
    fiasLog('IN', 'FIAS GO', "Check-Out Zimmer {$room}: {$guestName}", $msg['raw']);

    // Push to PCS
    pushEventToPCS([
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
    ]);
}

function processGuestChange(array $msg): void {
    $fields    = $msg['fields'];
    $room      = $fields['RN'] ?? '';
    $guestId   = $fields['G#'] ?? ($room . '_1');
    $lastName  = $fields['GN'] ?? null;
    $firstName = $fields['GF'] ?? null;
    $fiasLang  = $fields['GL'] ?? null;
    $language  = $fiasLang !== null ? translateLanguage($fiasLang) : null;

    if (!$room) return;

    validateRoom($room, 'GC', $msg['raw']);

    $rooms = loadRooms();
    if (isset($rooms[$room])) {
        foreach ($rooms[$room]['guests'] as &$g) {
            if ($g['id'] === $guestId) {
                if ($lastName !== null)  $g['name']['last']  = $lastName;
                if ($firstName !== null) $g['name']['first'] = $firstName;
                if ($language !== null)  $g['language']      = $language;
                $g['name']['full'] = trim(($g['name']['first'] ?? '') . ' ' . ($g['name']['last'] ?? ''));
                break;
            }
        }
        unset($g);
        saveRooms($rooms);
    }

    stdout("GUEST CHANGE: Zimmer {$room}");
    fiasLog('IN', 'FIAS GC', "Guest Change Zimmer {$room}: " . fiasFieldSummary($fields), $msg['raw']);

    // Push update to PCS
    pushEventToPCS([
        'data' => [
            'events' => [[
                'id'     => time(),
                'created'=> date('c'),
                'type'   => 'update',
                'update' => [
                    'id'    => $guestId,
                    'room'  => $room,
                    'guest' => $guestId,
                    'name'  => [
                        'last'  => $lastName,
                        'first' => $firstName,
                    ],
                    'language' => $language,
                ],
            ]],
        ],
    ]);
}

// ============================================================
// MAIN FIAS CLIENT - persistent connection, never exits
// ============================================================
function runFiasClient(): void {
    global $pidFile;

    file_put_contents($pidFile, getmypid());
    $socket = null;
    $buffer = '';
    $lastHeartbeatSent = 0;
    $linkDescSent = false;
    $connected = false;

    stdout("FIAS-Client gestartet (PID: " . getmypid() . ")");
    fiasLog('OUT', 'FIAS', 'FIAS-Client gestartet (PID: ' . getmypid() . ')');

    // --- Outer loop: keeps process alive forever ---
    while (true) {
        // Reload config each connection attempt
        $config = loadConfig();
        $fias = $config['fias'] ?? [];
        $host = $fias['host'] ?? '';
        $port = (int)($fias['port'] ?? 5010);
        $heartbeatInterval = (int)($fias['heartbeat_interval'] ?? 10);

        if (!$host) {
            stdout("Kein FIAS-Host konfiguriert, warte 30s...");
            sleep(30);
            continue;
        }

        // --- Connect ---
        if (!$connected) {
            stdout("Verbinde zu {$host}:{$port}...");
            fiasLog('OUT', 'FIAS', "Verbinde zu {$host}:{$port}...");

            $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (!$socket) {
                stdout("Socket erstellen fehlgeschlagen, warte 30s...");
                sleep(30);
                continue;
            }

            socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);

            if (!@socket_connect($socket, $host, $port)) {
                $err = socket_strerror(socket_last_error($socket));
                stdout("Verbindung fehlgeschlagen: {$err} - warte 30s...");
                fiasLog('OUT', 'ERROR', "Verbindung fehlgeschlagen: {$err}");
                @socket_close($socket);
                $socket = null;
                sleep(30);
                continue;
            }

            $connected = true;
            $buffer = '';
            $linkDescSent = false;
            $lastHeartbeatSent = time();

            stdout("Verbunden mit {$host}:{$port} - warte auf LS vom PMS...");
            fiasLog('OUT', 'FIAS', "Verbunden mit {$host}:{$port}");

            socket_set_nonblock($socket);
        }

        // --- Inner loop: read/write on connected socket ---
        while ($connected) {
            // Send periodic Link Alive
            if ((time() - $lastHeartbeatSent) >= $heartbeatInterval) {
                $laMsg = buildFiasMessage('LA', ['DA' => date('ymd'), 'TI' => date('His')]);
                socket_set_block($socket);
                $written = @socket_write($socket, $laMsg, strlen($laMsg));
                socket_set_nonblock($socket);
                if ($written === false) {
                    stdout("Heartbeat fehlgeschlagen, Verbindung verloren");
                    fiasLog('OUT', 'ERROR', 'Heartbeat senden fehlgeschlagen');
                    $connected = false;
                    break;
                }
                $lastHeartbeatSent = time();
                fiasLog('OUT', 'FIAS LA', 'Link Alive gesendet');
            }

            // Check for DB-Swap request from GUI
            $swapFile = __DIR__ . '/data/fias_dbswap.flag';
            if ($linkDescSent && file_exists($swapFile)) {
                @unlink($swapFile);
                $drMsg = buildFiasMessage('DR', ['DA' => date('ymd'), 'TI' => date('His')]);
                socket_set_block($socket);
                @socket_write($socket, $drMsg, strlen($drMsg));
                socket_set_nonblock($socket);
                stdout("Gesendet: DR (Database Resync Request)");
                fiasLog('OUT', 'FIAS DR', 'Database Resync angefordert', $drMsg);
            }

            // Wait for data (2s timeout so heartbeats get sent regularly)
            $read = [$socket];
            $write = null;
            $except = null;
            $ready = @socket_select($read, $write, $except, 2);

            if ($ready === false) {
                stdout("select Fehler, Verbindung verloren");
                $connected = false;
                break;
            }

            if ($ready === 0) {
                continue; // No data, loop back for heartbeat check
            }

            // Read data
            $data = @socket_read($socket, 4096);

            if ($data === false) {
                $errno = socket_last_error($socket);
                if ($errno === 11 || $errno === 35) continue; // EAGAIN
                $err = socket_strerror($errno);
                stdout("Lesefehler: {$err}");
                fiasLog('IN', 'ERROR', "Lesefehler: {$err}");
                $connected = false;
                break;
            }

            if ($data === '') {
                stdout("Verbindung vom Server geschlossen");
                fiasLog('IN', 'FIAS', 'Verbindung vom Server geschlossen');
                $connected = false;
                break;
            }

            $buffer .= $data;

            // --- Process complete FIAS messages ---
            while (true) {
                // Handle bare ACK/NAK
                foreach ([ACK => 'ACK', NAK => 'NAK'] as $ctrl => $ctrlName) {
                    $pos = strpos($buffer, $ctrl);
                    if ($pos !== false) {
                        $buffer = substr($buffer, 0, $pos) . substr($buffer, $pos + 1);
                        continue 2;
                    }
                }

                $stxPos = strpos($buffer, STX);
                $etxPos = strpos($buffer, ETX);
                if ($stxPos === false || $etxPos === false || $etxPos <= $stxPos) break;

                $msgContent = substr($buffer, $stxPos + 1, $etxPos - $stxPos - 1);
                $buffer = substr($buffer, $etxPos + 1);

                // Send ACK
                socket_set_block($socket);
                @socket_write($socket, ACK, 1);
                socket_set_nonblock($socket);

                // Parse message
                $msg = parseFiasMessage($msgContent);
                $rt = $msg['record_type'];
                $rtLabel = FIAS_RECORDS[$rt] ?? $rt;
                stdout("Empfangen: {$rt} ({$rtLabel}) " . fiasFieldSummary($msg['fields']));

                switch ($rt) {
                    case 'LS': // Link Start from PMS - respond immediately
                        fiasLog('IN', 'FIAS LS', 'Link Start vom PMS', $msg['raw']);
                        // Always resend LD/LR/LA on every LS (PMS may have restarted)
                        $linkDescSent = true;
                        $da = date('ymd');
                        $ti = date('His');
                        // Build complete response as single buffer for speed
                        $response = STX . "LD|DA{$da}|TI{$ti}|V#1.00|IFPI|" . ETX
                                  . STX . 'LR|RIGI|FLRNG#GNGLGVGGGSSF|' . ETX
                                  . STX . 'LR|RIGO|FLRNG#GSSF|' . ETX
                                  . STX . 'LR|RIGC|FLRNG#GNGLGVGGGSRO|' . ETX
                                  . STX . "LA|DA{$da}|TI{$ti}|" . ETX;
                        socket_set_block($socket);
                        @socket_write($socket, $response, strlen($response));
                        socket_set_nonblock($socket);
                        $lastHeartbeatSent = time();
                        stdout("  -> LD/LR/LA sofort gesendet");
                        fiasLog('OUT', 'FIAS', 'LD/LR/LA Sequenz gesendet (single write)');
                        break;

                    case 'LA':
                        fiasLog('IN', 'FIAS LA', 'Link Alive empfangen');
                        break;

                    case 'LD':
                        fiasLog('IN', 'FIAS LD', 'Link Description empfangen', $msg['raw']);
                        break;

                    case 'LR':
                        fiasLog('IN', 'FIAS LR', 'Link Record', $msg['raw']);
                        break;

                    case 'GI':
                        processGuestCheckin($msg);
                        break;

                    case 'GO':
                        processGuestCheckout($msg);
                        break;

                    case 'GC':
                        processGuestChange($msg);
                        break;

                    case 'DS':
                        fiasLog('IN', 'FIAS DS', 'Database Resync gestartet');
                        stdout("Database Resync gestartet...");
                        break;

                    case 'DR':
                        processGuestCheckin($msg);
                        break;

                    case 'DE':
                        fiasLog('IN', 'FIAS DE', 'Database Resync abgeschlossen');
                        stdout("Database Resync abgeschlossen");
                        break;

                    default:
                        fiasLog('IN', "FIAS {$rt}", fiasFieldSummary($msg['fields']), $msg['raw']);
                        break;
                }
            }
        }

        // Connection lost - clean up socket, DON'T exit process
        if ($socket) {
            @socket_close($socket);
            $socket = null;
        }
        $connected = false;
        stdout("Verbindung getrennt - halte Prozess aktiv, reconnect in 30s...");
        fiasLog('OUT', 'FIAS', 'Verbindung getrennt, warte 30s vor Reconnect');
        sleep(30);
    }
}

// --- Status check ---
function getFiasStatus(): array {
    global $pidFile;
    $config = loadConfig();
    $fias = $config['fias'] ?? [];
    $host = $fias['host'] ?? '';
    $port = (int)($fias['port'] ?? 5010);

    $running = false;
    $pid = null;
    if (file_exists($pidFile)) {
        $pid = (int)file_get_contents($pidFile);
        if ($pid > 0 && file_exists("/proc/{$pid}")) {
            $running = true;
        }
    }

    // TCP connectivity check
    $reachable = false;
    if ($host) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 3);
        if ($fp) {
            $reachable = true;
            fclose($fp);
        }
    }

    return [
        'host'      => "{$host}:{$port}",
        'reachable' => $reachable,
        'daemon'    => $running,
        'pid'       => $running ? $pid : null,
    ];
}

// ============================================================
// CLI entry point
// ============================================================
if (php_sapi_name() === 'cli') {
    $args = $argv ?? [];

    if (in_array('--status', $args)) {
        $status = getFiasStatus();
        echo json_encode($status, JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }

    if (in_array('--stop', $args)) {
        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            if ($pid > 0 && posix_kill($pid, 15)) {
                echo "FIAS-Daemon gestoppt (PID: {$pid})\n";
                @unlink($pidFile);
            } else {
                echo "Prozess {$pid} nicht gefunden\n";
                @unlink($pidFile);
            }
        } else {
            echo "Kein FIAS-Daemon aktiv\n";
        }
        exit(0);
    }

    // Default: run persistent client (never exits)
    runFiasClient();
    exit(0);
}
