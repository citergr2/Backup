<?php
declare(strict_types=1);

// Cookie session tetap dikelola PHP.
session_start();
$config = require __DIR__ . '/config.php';

// Identitas pelanggan persisten agar invoice tetap terlihat
// setelah browser ditutup, tanpa membuka invoice pelanggan lain.
$legacyCustomerId = $_SESSION['customer_id'] ?? null;
$existingAccessToken = (string)($_COOKIE['customer_access'] ?? '');
$hasValidAccessToken = (bool)preg_match('/^[a-f0-9]{64}$/D', $existingAccessToken);

if ($hasValidAccessToken) {
    $_SESSION['customer_id'] = hash('sha256', $existingAccessToken);
} else {
    $newAccessToken = bin2hex(random_bytes(32));
    setcookie('customer_access', $newAccessToken, [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_SESSION['customer_id'] = hash('sha256', $newAccessToken);
    $migrateLegacyCustomerId = is_string($legacyCustomerId)
        && preg_match('/^[a-f0-9]{32}$/iD', $legacyCustomerId);
}

if (!is_dir($config['data_dir'])) {
    mkdir($config['data_dir'], 0750, true);
}
$ordersFile = $config['data_dir'] . '/orders.json';
if (!file_exists($ordersFile)) {
    file_put_contents($ordersFile, '{}', LOCK_EX);
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}


function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function money(mixed $n): string {
    return is_numeric($n) ? 'Rp' . number_format((float)$n, 0, ',', '.') : (string)($n ?? '-');
}
function read_orders(): array {
    global $ordersFile;
    $fp = fopen($ordersFile, 'c+');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}
function save_order(string $invoice, array $details): void {
    global $ordersFile;
    $fp = fopen($ordersFile, 'c+');
    if (!$fp) throw new RuntimeException('Tidak bisa menyimpan riwayat order.');
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) $data = [];
    // Invoice owner tidak boleh diambil alih oleh request lain.
    if (!isset($data[$invoice])) {
        $data[$invoice] = $details + ['customer_id' => $_SESSION['customer_id'], 'created_at' => time()];
    } else {
        $customerId = $data[$invoice]['customer_id'] ?? null;
        $createdAt = $data[$invoice]['created_at'] ?? time();

        $data[$invoice] = array_merge($data[$invoice], $details);

        // Pertahankan pemilik invoice dan waktu pembuatan awal.
        if ($customerId !== null) {
            $data[$invoice]['customer_id'] = $customerId;
        }
        $data[$invoice]['created_at'] = $createdAt;
        $data[$invoice]['updated_at'] = time();
    }
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}
function migrate_legacy_customer_orders(string $oldId, string $newId): void {
    global $ordersFile;
    $fp = fopen($ordersFile, 'c+');
    if (!$fp) {
        throw new RuntimeException('Tidak bisa membaca riwayat invoice.');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('Tidak bisa mengunci riwayat invoice.');
    }
    rewind($fp);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) $data = [];

    foreach ($data as &$order) {
        if (isset($order['customer_id'])
            && hash_equals($oldId, (string)$order['customer_id'])) {
            $order['customer_id'] = $newId;
        }
    }
    unset($order);

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

if (!empty($migrateLegacyCustomerId) && is_string($legacyCustomerId)) {
    migrate_legacy_customer_orders(
        $legacyCustomerId,
        (string)$_SESSION['customer_id']
    );
}

function customer_owns(string $invoice): bool {
    $all = read_orders();
    return isset($all[$invoice]) && hash_equals((string)$all[$invoice]['customer_id'], (string)$_SESSION['customer_id']);
}
function api_request(string $method, string $path, ?array $body = null): array {
    global $config;
    $key = trim((string)$config['panel_api_key']);
    if ($key === '' || str_contains($key, 'ISI_API_KEY')) {
        throw new RuntimeException('API key belum dikonfigurasi di config.php atau environment YANNPANEL_API_KEY.');
    }
    $url = rtrim((string)$config["panel_api_url"], "/") . "/" . ltrim($path, "/");
    $headers = [
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
    ];
    $opts = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers) . ($body !== null ? "\r\nContent-Type: application/json" : ''),
            'ignore_errors' => true,
            'timeout' => max(5, (int)$config['timeout_seconds']),
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ];
    if ($body !== null) $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_SLASHES);
    $context = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) throw new RuntimeException('Tidak bisa menghubungi server API panel.');
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int)$m[1];
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) $json = ['message' => substr($raw, 0, 250)];
    if ($status < 200 || $status >= 300 || ($json['success'] ?? true) === false || ($json['status'] ?? true) === false) {
        $msg = $json['message'] ?? $json['error'] ?? ('HTTP ' . $status);
        throw new RuntimeException('API menolak permintaan: ' . (is_string($msg) ? $msg : 'Permintaan gagal.'));
    }
    return $json;
}
function unwrap_data(array $j): mixed {
    return $j['data'] ?? $j['result'] ?? $j;
}
function list_data(array $j): array {
    $d = unwrap_data($j);
    if (is_array($d) && array_is_list($d)) return $d;
    if (is_array($d)) {
        foreach (['products','orders','items','list','data'] as $k) {
            if (isset($d[$k]) && is_array($d[$k])) return $d[$k];
        }
    }
    return [];
}
function field(array $x, array $keys, mixed $fallback = null): mixed {
    foreach ($keys as $k) {
        if (isset($x[$k]) && $x[$k] !== '') return $x[$k];
    }
    return $fallback;
}
function csrf_check(): void {
    $token = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf'], $token)) {
        throw new RuntimeException('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}
function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}
function redirect_home(): never {
    header('Location: index.php');
    exit;
}
