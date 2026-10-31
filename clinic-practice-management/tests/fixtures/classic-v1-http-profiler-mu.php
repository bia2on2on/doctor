<?php
/** CI-only synthetic HTTP observer. Never included in the release ZIP. */
if (!defined('ABSPATH')) { exit; }
$GLOBALS['cpms_ci_start'] = microtime(true);
add_filter('query', static function ($sql) {
    $GLOBALS['cpms_ci_queries'] = (int) ($GLOBALS['cpms_ci_queries'] ?? 0) + 1;
    return $sql;
});
add_filter('rest_post_dispatch', static function ($response) {
    if ($response instanceof WP_REST_Response) {
        $response->header('X-CI-Queries', (string) ($GLOBALS['cpms_ci_queries'] ?? 0));
        $response->header('X-CI-Milliseconds', sprintf('%.3f', (microtime(true) - $GLOBALS['cpms_ci_start']) * 1000));
    }
    return $response;
}, 999);
add_action('shutdown', static function () {
    if (!headers_sent() && !defined('REST_REQUEST')) {
        header('X-CI-Queries: ' . (int) ($GLOBALS['cpms_ci_queries'] ?? 0));
        header('X-CI-Milliseconds: ' . sprintf('%.3f', (microtime(true) - $GLOBALS['cpms_ci_start']) * 1000));
    }
}, PHP_INT_MAX);
