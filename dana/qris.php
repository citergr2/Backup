<?php
declare(strict_types=1);

function dana_config(): array {
    $config = require __DIR__ . '/config.php';

    if (($config['environment'] ?? '') !== 'sandbox') {
        throw new RuntimeException('Pengujian hanya diizinkan pada sandbox.');
    }

    foreach (['client_id', 'private_key_path', 'merchant_id', 'store_id'] as $key) {
        if (empty($config[$key])) {
            throw new RuntimeException("Konfigurasi DANA belum lengkap: {$key}");
        }
    }

    if (!is_file($config['private_key_path']) ||
        !is_readable($config['private_key_path'])) {
        throw new RuntimeException('File private key tidak tersedia atau tidak bisa dibaca.');
    }

    return $config;
}

function dana_timestamp(): string {
    return date('Y-m-d\TH:i:sP');
}

function dana_create_qris(string $invoice, int $total): array {
    $config = dana_config();

    if (!preg_match('/^[A-Za-z0-9_-]{1,25}$/', $invoice) || $total < 1) {
        throw new InvalidArgumentException('Invoice atau nominal tidak valid.');
    }

    throw new RuntimeException(
        'Helper siap. Request API belum diaktifkan sebelum format signature dan credential sandbox diverifikasi.'
    );
}
