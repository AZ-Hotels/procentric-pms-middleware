<?php
/**
 * PMS Interface Server Router
 * This file handles all /api/pms/v2/* requests from the ProCentric Server (PCS).
 * PCS calls US - we are the PMS interface server.
 */

header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/config.json';
$logFile    = __DIR__ . '/logs/api.log';
$dataFile   = __DIR__ . '/data/rooms.json';
$subsFile   = __DIR__ . '/data/subscriptions.json';

foreach ([__DIR__ . '/logs', __DIR__ . '/data'] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// --- Config ---
function loadConfig(): array {
    global $configFile;
    return file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?: []) : [];
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

// --- Room data persistence ---
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

function saveSubscriptions(array $subs): void {
    global $subsFile;
    file_put_contents($subsFile, json_encode($subs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// --- Auth validation ---
function validateAuth(): bool {
    $config = loadConfig();
    $expectedId     = $config['procentric']['client_id'] ?? '';
    $expectedSecret = $config['procentric']['client_secret'] ?? '';

    // Auth can come via header (JSON string) or individual headers
    $authHeader = $_SERVER['HTTP_AUTH'] ?? '';
    if ($authHeader) {
        $auth = json_decode($authHeader, true);
        if ($auth) {
            $clientId     = trim($auth['client_id'] ?? '');
            $clientSecret = trim($auth['client_secret'] ?? '');
            if ($clientId === $expectedId && $clientSecret === $expectedSecret) {
                return true;
            }
        }
    }

    // Also check client_id / client_secret as individual headers
    $clientId     = trim($_SERVER['HTTP_CLIENT_ID'] ?? '');
    $clientSecret = trim($_SERVER['HTTP_CLIENT_SECRET'] ?? '');
    if ($clientId === $expectedId && $clientSecret === $expectedSecret) {
        return true;
    }

    // Also check from request body for POST /check
    return false;
}

// --- Parse request path ---
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Strip prefix
$prefix = '/api/pms/v2';
if (strpos($path, $prefix) === 0) {
    $route = substr($path, strlen($prefix)) ?: '/';
} else {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Not found']);
    exit;
}

// Read body
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true) ?: [];

// Log incoming request with auth headers for debugging
$incomingHeaders = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $incomingHeaders[substr($k, 5)] = $v;
    }
}
// Log incoming request (skip noisy /statuses polling)
if ($route !== '/statuses') {
    apiLog('IN', "PCS {$method}", "{$method} {$path}", json_encode(['headers' => $incomingHeaders, 'body' => $body], JSON_UNESCAPED_SLASHES));
}

// --- Routes ---

// POST /check - Connection check from PCS
if ($route === '/check' && $method === 'POST') {
    // PCS sends server info to verify connection
    apiLog('OUT', 'RESPONSE', 'POST /check -> 200 OK', '{"status":"success"}');
    echo json_encode(['status' => 'success']);
    exit;
}

// GET /statuses - PMS status check (PCS polls every minute)
if ($route === '/statuses' && $method === 'GET') {
    $response = [
        'status' => 'success',
        'data' => [
            'id'      => time(),
            'created' => date('c'),
            'status'  => 'up',
        ],
    ];
    // Nicht loggen - wird jede Minute von PCS + alle 30s von GUI gepollt
    echo json_encode($response);
    exit;
}

// GET /details - Site/hotel information
if ($route === '/details' && $method === 'GET') {
    $config = loadConfig();
    $site = $config['site'] ?? [];
    $response = [
        'status' => 'success',
        'data' => [
            'id'       => $config['procentric']['client_id'] ?? '0123456',
            'name'     => $site['name'] ?? 'Hotel',
            'currency' => $site['currency'] ?? 'EUR',
            'website'  => $site['website'] ?? null,
            'timezone' => $site['timezone'] ?? 'CET',
            'contacts' => [
                'telephone' => $site['telephone'] ?? null,
                'email'     => $site['email'] ?? null,
            ],
            'location' => [
                'address' => [
                    'street'   => $site['street'] ?? null,
                    'city'     => $site['city'] ?? null,
                    'state'    => $site['state'] ?? null,
                    'postcode' => $site['postcode'] ?? null,
                    'country'  => $site['country'] ?? null,
                ],
                'coordinate' => [
                    'latitude'  => $site['latitude'] ?? null,
                    'longitude' => $site['longitude'] ?? null,
                ],
            ],
            'timestamp' => date('c'),
        ],
    ];
    apiLog('OUT', 'RESPONSE', 'GET /details -> site info', json_encode($response));
    echo json_encode($response);
    exit;
}

// GET /rooms/{room_id} - Room information
if (preg_match('#^/rooms/([^/]+)$#', $route, $m) && $method === 'GET') {
    $roomId = $m[1];

    // Track rooms queried by PCS for auto-discovery
    // Optional filter (default on): only accept IDs that look like room numbers
    $filterEnabled = (loadConfig()['resync']['room_filter'] ?? true) !== false;
    $isRealRoom = $filterEnabled
        ? (bool) preg_match('/^\d{1,5}[A-Za-z]?$/', $roomId)
        : true;
    $pcsRoomsFile = __DIR__ . '/data/pcs_discovered_rooms.json';
    $discovered = file_exists($pcsRoomsFile) ? (json_decode(file_get_contents($pcsRoomsFile), true) ?: []) : [];
    $now = time();
    if ($isRealRoom) {
        $discovered[$roomId] = $now;
    }
    // Remove entries older than 60s (only keep rooms from current fetch batch)
    $discovered = array_filter($discovered, fn($ts) => ($now - $ts) < 60);
    file_put_contents($pcsRoomsFile, json_encode($discovered));

    // If PCS queried 2+ rooms within 60s, merge into config room list (additive, dedup)
    if (count($discovered) >= 2) {
        $config = loadConfig();
        $discoveredRooms = array_keys($discovered);
        $oldRooms = $config['resync']['rooms'] ?? [];
        $mergedRooms = array_values(array_unique(array_merge($oldRooms, $discoveredRooms)));
        // natural sort for room numbers
        usort($mergedRooms, fn($a, $b) => strnatcmp((string)$a, (string)$b));
        $addedRooms = array_values(array_diff($mergedRooms, $oldRooms));
        if (!empty($addedRooms)) {
            $config['resync']['rooms'] = $mergedRooms;
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            apiLog('OUT', 'AUTO', 'Zimmerliste von PCS ergaenzt (' . count($mergedRooms) . ' gesamt, +' . count($addedRooms) . ' neu): ' . implode(', ', $addedRooms));
        }
    }

    $rooms = loadRooms();

    if (isset($rooms[$roomId]) && !empty($rooms[$roomId]['guests'])) {
        $response = [
            'status' => 'success',
            'data' => [
                'id'           => $roomId,
                'groupCode'    => $rooms[$roomId]['groupCode'] ?? '0',
                'deploymentId' => $rooms[$roomId]['deploymentId'] ?? 1,
                'guests'       => $rooms[$roomId]['guests'],
            ],
        ];
        apiLog('OUT', 'RESPONSE', "GET /rooms/{$roomId} -> occupied", json_encode($response));
        echo json_encode($response);
    } else {
        // Room empty / not found = 404 per spec
        apiLog('OUT', 'RESPONSE', "GET /rooms/{$roomId} -> 404 unoccupied");
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Room not found or unoccupied']);
    }
    exit;
}

// POST /rooms/{room_id}/checkouts - Checkout from TV
if (preg_match('#^/rooms/([^/]+)/checkouts(?:/([^/]+))?$#', $route, $m) && $method === 'POST') {
    $roomId  = $m[1];
    $guestId = $m[2] ?? null;
    $rooms   = loadRooms();

    if (isset($rooms[$roomId])) {
        if ($guestId) {
            // Remove specific guest
            $rooms[$roomId]['guests'] = array_values(array_filter(
                $rooms[$roomId]['guests'] ?? [],
                fn($g) => ($g['id'] ?? '') !== $guestId
            ));
            if (empty($rooms[$roomId]['guests'])) {
                unset($rooms[$roomId]);
            }
        } else {
            unset($rooms[$roomId]);
        }
        saveRooms($rooms);
    }

    $response = ['status' => 'success'];
    apiLog('OUT', 'RESPONSE', "POST /rooms/{$roomId}/checkouts -> success", json_encode($response));
    echo json_encode($response);
    exit;
}

// POST /subscriptions - PCS registers for events
if ($route === '/subscriptions' && $method === 'POST') {
    $subs = loadSubscriptions();

    // Extract token from Authorization header or body
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $callbackToken = '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $tm)) {
        $callbackToken = $tm[1];
    }
    $callbackToken = $body['callbackToken'] ?? $body['callbakToken'] ?? $callbackToken;

    $callbackUri = $body['callbackUri'] ?? '';
    $name        = $body['name'] ?? 'PCS';

    // Update existing subscription with same name instead of duplicating
    $found = false;
    foreach ($subs as &$s) {
        if (($s['name'] ?? '') === $name) {
            $s['callbackUri']   = $callbackUri;
            $s['callbackToken'] = $callbackToken;
            $s['created']       = date('c');
            $found = true;
            $newSub = $s;
            break;
        }
    }
    unset($s);

    if (!$found) {
        $maxId = 0;
        foreach ($subs as $s) { $maxId = max($maxId, $s['id'] ?? 0); }
        $newSub = [
            'id'            => $maxId + 1,
            'created'       => date('c'),
            'name'          => $name,
            'callbackUri'   => $callbackUri,
            'callbackToken' => $callbackToken,
        ];
        $subs[] = $newSub;
    }
    saveSubscriptions($subs);

    $response = [
        'status' => 'success',
        'data'   => $newSub,
    ];
    apiLog('OUT', 'RESPONSE', "POST /subscriptions -> id={$subId}, callback=" . ($newSub['callbackUri']), json_encode($response));
    echo json_encode($response);
    exit;
}

// GET /subscriptions/{id}
if (preg_match('#^/subscriptions/(\d+)$#', $route, $m) && $method === 'GET') {
    $subId = (int)$m[1];
    $subs = loadSubscriptions();
    $found = null;
    foreach ($subs as $s) {
        if (($s['id'] ?? 0) === $subId) { $found = $s; break; }
    }
    if ($found) {
        echo json_encode(['status' => 'success', 'data' => $found]);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Subscription not found']);
    }
    exit;
}

// PUT /subscriptions/{id}
if (preg_match('#^/subscriptions/(\d+)$#', $route, $m) && $method === 'PUT') {
    $subId = (int)$m[1];
    $subs = loadSubscriptions();
    $updated = false;
    foreach ($subs as &$s) {
        if (($s['id'] ?? 0) === $subId) {
            $s['name']        = $body['name'] ?? $s['name'];
            $s['callbackUri'] = $body['callbackUri'] ?? $s['callbackUri'];
            $updated = true;
            break;
        }
    }
    unset($s);
    if ($updated) {
        saveSubscriptions($subs);
        echo json_encode(['status' => 'success']);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Subscription not found']);
    }
    exit;
}

// DELETE /subscriptions/{id}
if (preg_match('#^/subscriptions/(\d+)$#', $route, $m) && $method === 'DELETE') {
    $subId = (int)$m[1];
    $subs = loadSubscriptions();
    $subs = array_values(array_filter($subs, fn($s) => ($s['id'] ?? 0) !== $subId));
    saveSubscriptions($subs);
    echo json_encode(['status' => 'success']);
    exit;
}

// GET /rooms/{room_id}/folios/{guest_id}
if (preg_match('#^/rooms/([^/]+)/folios/([^/]+)$#', $route, $m) && $method === 'GET') {
    $roomId  = $m[1];
    $guestId = $m[2];
    $response = [
        'status' => 'success',
        'data' => [
            'id'      => '1',
            'status'  => 'open',
            'balance' => 0,
            'items'   => [],
        ],
    ];
    apiLog('OUT', 'RESPONSE', "GET /rooms/{$roomId}/folios/{$guestId} -> empty folio");
    echo json_encode($response);
    exit;
}

// Fallback
apiLog('OUT', 'RESPONSE', "404 Not Found: {$method} {$route}");
http_response_code(404);
echo json_encode(['status' => 'error', 'message' => "Unknown route: {$method} {$route}"]);
