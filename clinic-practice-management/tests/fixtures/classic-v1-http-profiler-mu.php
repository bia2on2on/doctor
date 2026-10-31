<?php
/** CI-only synthetic HTTP observer. Never included in the release ZIP. */
if (!defined('ABSPATH') || !defined('CPMS_CI_HTTP_PROFILER') || !CPMS_CI_HTTP_PROFILER) { return; }
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
// Buffer the CI-only HTML response so the final query count is known before
// the first byte is emitted. This does not alter the response body.
add_action('template_redirect', static function () {
    if (defined('REST_REQUEST')) {
        return;
    }
    ob_start(static function ($html) {
        header('X-CI-Queries: ' . (int) ($GLOBALS['cpms_ci_queries'] ?? 0));
        header('X-CI-Milliseconds: ' . sprintf('%.3f', (microtime(true) - $GLOBALS['cpms_ci_start']) * 1000));
        return $html;
    });
}, 0);
