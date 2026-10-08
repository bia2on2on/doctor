<?php
// Out-of-tree server identity only. Never phpinfo(), config, cookies or secrets.
header('Content-Type: application/json');
echo json_encode([
    'php_version' => PHP_VERSION,
    'php_sapi' => PHP_SAPI,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '',
]);
