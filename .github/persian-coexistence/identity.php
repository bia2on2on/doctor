<?php

declare(strict_types=1);

// Test-only local endpoint. It is served by the same Apache/mod_php pool as
// WordPress but does not bootstrap WordPress or load any plugin.
header('Content-Type: application/json; charset=utf-8');

if (PHP_SAPI !== 'apache2handler'
    || !function_exists('posix_geteuid')
    || !function_exists('posix_getegid')
    || !function_exists('posix_getpwuid')
    || !function_exists('posix_getgrgid')) {
    http_response_code(503);
    echo '{"error":"effective process identity unavailable"}';
    return;
}

$uid = posix_geteuid();
$gid = posix_getegid();
$user = posix_getpwuid($uid);
$group = posix_getgrgid($gid);

$payload = [
    'effective_uid' => $uid,
    'effective_user' => is_array($user) ? (string) ($user['name'] ?? '') : '',
    'effective_gid' => $gid,
    'effective_group' => is_array($group) ? (string) ($group['name'] ?? '') : '',
    'php_sapi' => PHP_SAPI,
];

echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
