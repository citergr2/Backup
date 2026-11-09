<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    respond(405, ['error' => 'Method not allowed']);
}

$tokenFile = '/etc/xitercsx-buy/bot-token';
$token = @file_get_contents($tokenFile);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (
    $token === false ||
    trim($token) === '' ||
    !preg_match('/^Bearer ([a-f0-9]{64})$/i', $auth, $m) ||
    !hash_equals(trim($token), $m[1])
) {
    respond(401, ['error' => 'Unauthorized']);
}

$config = require __DIR__ . '/config.php';
$ordersFile = $config['data_dir'] . '/orders.json';
$proofDir = $config['data_dir'] . '/payment-proofs';

function with_orders(string $file, callable $callback): mixed {
    $fp = fopen($file, 'c+');
    if (!$fp) respond(500, ['error' => 'Storage unavailable']);

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        respond(500, ['error' => 'Storage unavailable']);
    }

    rewind($fp);
    $raw = stream_get_contents($fp);
    $orders = json_decode($raw ?: '{}', true);
    if (!is_array($orders)) $orders = [];

    try {
        $result = $callback($orders);

        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode(
            $orders,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $job = with_orders($ordersFile, function (&$orders) use ($proofDir) {
        foreach ($orders as $invoice => &$o) {
            if (
                empty($o['payment_proof']) ||
                !empty($o['proof_notified_at']) ||
                !empty($o['payment_verified']) ||
                !empty($o['server_created']) ||
                ($o['status'] ?? '') !== 'pending'
            ) {
                continue;
            }

            $name = (string)$o['payment_proof'];

            if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/i', $name)) {
                continue;
            }

            $realDir = realpath($proofDir);
            $realFile = realpath($proofDir . '/' . $name);

            if (
                !$realDir || !$realFile ||
                dirname($realFile) !== $realDir ||
                !is_file($realFile) || !is_readable($realFile)
            ) {
                continue;
            }

            $mime = (string)($o['payment_proof_mime'] ?? '');

            if (!in_array($mime, [
                'image/jpeg', 'image/png', 'image/webp'
            ], true)) {
                continue;
            }

            $bytes = file_get_contents($realFile);

            if ($bytes === false || strlen($bytes) > 2 * 1024 * 1024) {
                continue;
            }

            return [
                'invoice' => (string)$invoice,
                'product' => (string)($o['product_name'] ?? ''),
                'total' => (int)($o['total'] ?? 0),
                'status' => (string)($o['status'] ?? 'pending'),
                'mime' => $mime,
                'image_base64' => base64_encode($bytes)
            ];
        }

        unset($o);
        return null;
    });

    respond(200, ['job' => $job]);
}

$input = json_decode(file_get_contents('php://input') ?: '', true);

if (!is_array($input)) {
    respond(400, ['error' => 'Invalid request']);
}

$action = (string)($input['action'] ?? '');
$invoice = strtoupper(trim((string)($input['invoice'] ?? '')));

if (!preg_match('/^XC-\d{8}-[A-F0-9]{8}$/', $invoice)) {
    respond(400, ['error' => 'Invalid invoice']);
}

/* ADMIN: mengambil dan mengunci invoice untuk diproses */
if ($action === 'claim') {
    $result = with_orders($ordersFile, function (&$orders) use ($invoice) {
        if (!isset($orders[$invoice]) || !is_array($orders[$invoice])) {
            return ['status' => 404, 'error' => 'Invoice tidak ditemukan'];
        }

        $o = &$orders[$invoice];

        if (empty($o['payment_proof'])) {
            return ['status' => 409, 'error' => 'Bukti pembayaran belum diunggah'];
        }

        if (
            !preg_match('/^628[0-9]{7,11}$/', (string)($o['buyer_phone'] ?? ''))
        ) {
            return ['status' => 409, 'error' => 'Nomor WhatsApp pembeli tidak valid'];
        }

        if (!empty($o['payment_verified']) || !empty($o['server_created'])) {
            return ['status' => 409, 'error' => 'Invoice sudah diproses'];
        }

        if (($o['status'] ?? '') !== 'pending' || !empty($o['processing_token'])) {
            return ['status' => 409, 'error' => 'Invoice tidak tersedia atau sedang diproses'];
        }

        $processingToken = bin2hex(random_bytes(24));

        $o['status'] = 'processing';
        $o['processing_token'] = $processingToken;
        $o['processing_started_at'] = time();

        return [
            'status' => 200,
            'ok' => true,
            'processing_token' => $processingToken,
            'order' => [
                'invoice' => $invoice,
                'product_id' => (string)($o['product_id'] ?? ''),
                'product_name' => (string)($o['product_name'] ?? ''),
                'username' => (string)($o['username'] ?? ''),
                'email' => (string)($o['email'] ?? ''),
                'buyer_phone' => (string)$o['buyer_phone'],
                'total' => (int)($o['total'] ?? 0)
            ]
        ];
    });

    $status = (int)$result['status'];
    unset($result['status']);
    respond($status, $result);
}

/* Bot hanya boleh menyelesaikan invoice dengan token klaim yang benar */
if (in_array($action, ['complete', 'release'], true)) {
    $processingToken = (string)($input['processing_token'] ?? '');

    if (!preg_match('/^[a-f0-9]{48}$/', $processingToken)) {
        respond(400, ['error' => 'Invalid processing token']);
    }

    $result = with_orders($ordersFile, function (&$orders) use (
        $invoice,
        $processingToken,
        $action,
        $input
    ) {
        if (!isset($orders[$invoice]) || !is_array($orders[$invoice])) {
            return ['status' => 404, 'error' => 'Invoice tidak ditemukan'];
        }

        $o = &$orders[$invoice];

        if (
            empty($o['processing_token']) ||
            !hash_equals((string)$o['processing_token'], $processingToken) ||
            ($o['status'] ?? '') !== 'processing'
        ) {
            return ['status' => 409, 'error' => 'Kunci proses tidak cocok'];
        }

        if ($action === 'release') {
            $o['status'] = 'pending';
            $o['processing_error'] = substr(
                trim((string)($input['reason'] ?? 'Proses dibatalkan sebelum server dibuat')),
                0,
                500
            );
            unset($o['processing_token']);

            return ['status' => 200, 'ok' => true];
        }

        $userId = (int)($input['panel_user_id'] ?? 0);
        $serverId = (int)($input['panel_server_id'] ?? 0);

        if ($userId < 1 || $serverId < 1) {
            return ['status' => 400, 'error' => 'ID panel tidak valid'];
        }

        $o['payment_verified'] = true;
        $o['server_created'] = true;
        $o['status'] = 'completed';
        $o['panel_user_id'] = $userId;
        $o['panel_server_id'] = $serverId;
        $o['completed_at'] = time();

        unset($o['processing_token']);

        return ['status' => 200, 'ok' => true];
    });

    $status = (int)$result['status'];
    unset($result['status']);
    respond($status, $result);
}

/* ACK notifikasi bukti pembayaran */
if ($action === 'ack') {
    $result = with_orders($ordersFile, function (&$orders) use ($invoice) {
        if (
            !isset($orders[$invoice]) ||
            empty($orders[$invoice]['payment_proof']) ||
            !empty($orders[$invoice]['payment_verified']) ||
            !empty($orders[$invoice]['server_created']) ||
            ($orders[$invoice]['status'] ?? '') !== 'pending'
        ) {
            return false;
        }

        $orders[$invoice]['proof_notified_at'] ??= time();
        return true;
    });

    respond($result ? 200 : 404, ['ok' => $result]);
}

/* TOLAK bukti pembayaran */
if ($action === 'reject') {
    $reason = trim((string)($input['reason'] ?? ''));

    if ($reason === '' || strlen($reason) > 500) {
        respond(400, ['error' => 'Alasan wajib diisi, maksimal 500 karakter']);
    }

    $result = with_orders($ordersFile, function (&$orders) use ($invoice, $reason) {
        if (!isset($orders[$invoice]) || !is_array($orders[$invoice])) {
            return ['status' => 404, 'error' => 'Invoice tidak ditemukan'];
        }

        $o = &$orders[$invoice];

        if (empty($o['payment_proof'])) {
            return ['status' => 409, 'error' => 'Invoice belum memiliki bukti pembayaran'];
        }

        if (
            !empty($o['payment_verified']) ||
            !empty($o['server_created']) ||
            !empty($o['processing_token']) ||
            ($o['status'] ?? '') === 'processing'
        ) {
            return ['status' => 409, 'error' => 'Invoice sudah diproses atau sedang diproses'];
        }

        if (($o['status'] ?? '') === 'rejected') {
            return ['status' => 409, 'error' => 'Invoice sudah ditolak'];
        }

        $o['status'] = 'rejected';
        $o['rejection_reason'] = $reason;
        $o['rejected_at'] = time();
        $o['proof_notified_at'] = time();

        return ['status' => 200, 'ok' => true];
    });

    $status = (int)$result['status'];
    unset($result['status']);
    respond($status, $result);
}

respond(400, ['error' => 'Invalid action']);
