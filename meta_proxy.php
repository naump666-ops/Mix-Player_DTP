<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(30);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$action = isset($_GET['action']) ? $_GET['action'] : '';
$url    = isset($_GET['url']) ? $_GET['url'] : '';

// ===== PING =====
if ($action === 'ping' || isset($_GET['ping'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok', 'time' => time(), 'curl' => function_exists('curl_init')]);
    exit;
}

// ===== СИНХРОНИЗАЦИЯ ПУЛЬТА / КОМНАТ (action=state) =====
// GET  ?action=state&room=ABCD          -> вернуть состояние
// GET/POST ?action=state&room=ABCD&set=1&data={...} -> сохранить состояние
if ($action === 'state') {
    header('Content-Type: application/json; charset=utf-8');
    $room = isset($_GET['room']) ? preg_replace('/[^A-Z0-9]/i', '', strtoupper($_GET['room'])) : '';
    if (strlen($room) !== 4) { echo json_encode(['error' => 'invalid room']); exit; }

    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mixplayer_state';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $file = $dir . DIRECTORY_SEPARATOR . $room . '.json';

    // Сохранение
    if (isset($_GET['set'])) {
        $raw = isset($_GET['data']) ? $_GET['data'] : file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if ($decoded === null) { echo json_encode(['error' => 'bad json']); exit; }
        @file_put_contents($file, $raw);
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // Чтение
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false && $raw !== '') { echo $raw; exit; }
    }
    echo json_encode(['version' => 0]);
    exit;
}

if (!$url) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'no url']);
    exit;
}

if ($action === 'meta') {
    header('Content-Type: application/json; charset=utf-8');
    $title = getMetaCached($url);
    if ($title !== null && $title !== '') {
        echo json_encode(['title' => $title], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['error' => 'no metadata'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'fetch') {
    header('Content-Type: application/json; charset=utf-8');
    $data = curlGet($url);
    echo ($data === null) ? json_encode(['error' => 'fetch failed']) : $data;
    exit;
}

if ($action === 'stream') {
    proxyStream($url);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'unknown action']);

function getMetaCached($url) {
    $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mixplayer_meta';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    $file = $cacheDir . DIRECTORY_SEPARATOR . md5($url) . '.json';
    $ttl  = 15;
    if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
        $cached = json_decode(@file_get_contents($file), true);
        if ($cached && array_key_exists('title', $cached)) return $cached['title'];
    }
    $title = getMetaSmart($url);
    @file_put_contents($file, json_encode(['title' => $title, 'time' => time()]));
    return $title;
}
function getMetaSmart($url) {
    if (strpos($url, 'radiorecord.hostingradio.ru') !== false) {
        $title = getRadioRecordMeta($url);
        if ($title) return $title;
    }
    $title = getIcyStreamTitle($url);
    if ($title) return $title;
    return null;
}
function getRadioRecordMeta($url) {
    if (!preg_match('/\/([a-z0-9]+)\d*\.aacp/i', $url, $m)) return null;
    $stationId = $m[1];
    $apiUrls = [
        "https://www.radiorecord.ru/api/now/?station={$stationId}",
        "https://api.radiorecord.ru/v2/stations/{$stationId}/now",
        "https://radiorecord.hostingradio.ru/api/now/{$stationId}"
    ];
    foreach ($apiUrls as $apiUrl) {
        $data = curlGet($apiUrl);
        if ($data) {
            $json = json_decode($data, true);
            if ($json) {
                if (isset($json['artist']) && isset($json['title'])) return $json['artist'] . ' — ' . $json['title'];
                if (isset($json['now']['artist']) && isset($json['now']['title'])) return $json['now']['artist'] . ' — ' . $json['now']['title'];
                if (isset($json['title'])) return $json['title'];
            }
        }
    }
    $pageUrl = "https://www.radiorecord.ru/stations/{$stationId}/";
    $html = curlGet($pageUrl);
    if ($html) {
        if (preg_match('/"now_playing"\s*:\s*\{[^}]*"artist"\s*:\s*"([^"]*)"[^}]*"title"\s*:\s*"([^"]*)"/i', $html, $m)) return $m[1] . ' — ' . $m[2];
        if (preg_match('/"current_track"\s*:\s*"([^"]*)"/i', $html, $m)) return $m[1];
    }
    return null;
}
function getIcyStreamTitle($url) {
    if (function_exists('curl_init')) {
        $r = getIcyViaCurl($url);
        if ($r !== null) return $r;
    }
    if (ini_get('allow_url_fopen')) return getIcyViaFopen($url);
    return null;
}
function getIcyViaCurl($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MixPlayer/1.0)',
        CURLOPT_HTTPHEADER => ['Icy-MetaData: 1', 'Connection: close'],
        CURLOPT_RANGE => '0-65535',
        CURLOPT_ENCODING => '',
    ]);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $httpCode < 200 || $httpCode >= 400) return null;
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $metaint = 0;
    foreach (preg_split('/\r\n|\r|\n/', $headers) as $line) {
        if (stripos($line, 'icy-metaint:') === 0) { $metaint = (int)trim(substr($line, 12)); break; }
    }
    if ($metaint <= 0) return null;
    if (strlen($body) <= $metaint) return null;
    $lenByte = ord($body[$metaint]);
    $metaLen = $lenByte * 16;
    if ($metaLen <= 0) return null;
    if (strlen($body) < $metaint + 1 + $metaLen) return null;
    $metaBlock = rtrim(substr($body, $metaint + 1, $metaLen), "\0");
    if (preg_match("/StreamTitle='([^']*)'/", $metaBlock, $m)) {
        $title = trim($m[1]);
        if (!mb_check_encoding($title, 'UTF-8')) $title = @iconv('Windows-1251', 'UTF-8//IGNORE', $title);
        return $title;
    }
    return null;
}
function getIcyViaFopen($url) {
    $context = stream_context_create([
        'http' => ['method'=>'GET','header'=>"Icy-MetaData: 1\r\nUser-Agent: Mozilla/5.0 (compatible; MixPlayer/1.0)\r\nConnection: close\r\n",'timeout'=>8,'ignore_errors'=>true],
        'ssl' => ['verify_peer'=>false,'verify_peer_name'=>false],
    ]);
    $fp = @fopen($url, 'rb', false, $context);
    if (!$fp) return null;
    stream_set_timeout($fp, 8);
    $metaint = 0;
    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line === '') break;
        if (stripos($line, 'icy-metaint:') === 0) $metaint = (int)trim(substr($line, 12));
    }
    if ($metaint <= 0) { fclose($fp); return null; }
    $remaining = $metaint;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(8192, $remaining));
        if ($chunk === false || $chunk === '') break;
        $remaining -= strlen($chunk);
    }
    $lenByte = fread($fp, 1);
    if ($lenByte === false || $lenByte === '') { fclose($fp); return null; }
    $metaLen = ord($lenByte) * 16;
    $metaBlock = ($metaLen > 0) ? fread($fp, $metaLen) : '';
    fclose($fp);
    if ($metaBlock !== '' && preg_match("/StreamTitle='([^']*)'/", $metaBlock, $m)) {
        $title = trim($m[1]);
        if (!mb_check_encoding($title, 'UTF-8')) $title = @iconv('Windows-1251', 'UTF-8//IGNORE', $title);
        return $title;
    }
    return null;
}
function curlGet($url) {
    if (!function_exists('curl_init')) return @file_get_contents($url);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MixPlayer/1.0)',
        CURLOPT_ENCODING => '',
    ]);
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($data === false || $httpCode >= 400) ? null : $data;
}
function proxyStream($url) {
    if (!function_exists('curl_init')) { proxyStreamFopen($url); return; }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0', CURLOPT_HEADER => true,
    ]);
    $head = curl_exec($ch);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    $contentType = 'audio/mpeg';
    if ($head && preg_match('/content-type:\s*([^\r\n]+)/i', $head, $m)) $contentType = trim($m[1]);
    header('Content-Type: ' . $contentType);
    header('Access-Control-Allow-Origin: *');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $finalUrl ?: $url, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0', CURLOPT_TIMEOUT => 0, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_WRITEFUNCTION => function($ch, $data) { echo $data; @flush(); if (connection_aborted()) return 0; return strlen($data); },
    ]);
    curl_exec($ch);
    curl_close($ch);
}
function proxyStreamFopen($url) {
    $context = stream_context_create([
        'http' => ['method'=>'GET','header'=>"User-Agent: Mozilla/5.0\r\n",'timeout'=>15,'ignore_errors'=>true],
        'ssl' => ['verify_peer'=>false,'verify_peer_name'=>false],
    ]);
    $fp = @fopen($url, 'rb', false, $context);
    if (!$fp) { http_response_code(502); exit; }
    $contentType = 'audio/mpeg';
    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line === '') break;
        if (stripos($line, 'content-type:') === 0) $contentType = trim(substr($line, 13));
    }
    header('Content-Type: ' . $contentType);
    header('Access-Control-Allow-Origin: *');
    while (!feof($fp)) { echo fread($fp, 8192); @flush(); }
    fclose($fp);
}