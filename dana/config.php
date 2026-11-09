<?php
declare(strict_types=1);

return [
    'environment' => 'sandbox',
    'base_url' => 'https://api.sandbox.dana.id',

    // Isi melalui environment VPS, jangan hardcode secret.
    'client_id' => getenv('DANA_CLIENT_ID') ?: '',
    'client_secret' => getenv('DANA_CLIENT_SECRET') ?: '',
    'private_key_path' => getenv('DANA_PRIVATE_KEY_PATH') ?: '',
    'public_key_path' => getenv('DANA_PUBLIC_KEY_PATH') ?: '',

    'merchant_id' => getenv('DANA_MERCHANT_ID') ?: '',
    'store_id' => getenv('DANA_STORE_ID') ?: '',

    'finish_payment_url' => 'https://xitercsx.my.id/dana/finish.php',
    'finish_notify_url' => 'https://xitercsx.my.id/dana/finish-notify.php',
];
