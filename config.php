<?php
declare(strict_types=1);

/*
 * Xitercsx Panel Website
 * Keep this file private. Never put API keys in frontend JavaScript.
 */
return [
    'site_name' => 'Xitercsx Shop',
    'panel_api_url' => getenv('YANNPANEL_URL') ?: 'https://panel.xitercsx.biz.id/api/application',
    'panel_api_key' => getenv('YANNPANEL_API_KEY') ?: 'ptla_yPvxzPINwTAtPuNr2S6jHssiON0LWp9SFRTJeDCQIVa',
    'panel_url' => getenv('YANNPANEL_PANEL_URL') ?: 'https://panel.xitercsx.biz.id',
    'timeout_seconds' => 25,
    'data_dir' => __DIR__ . '/data',
];
