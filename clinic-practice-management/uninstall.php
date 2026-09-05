<?php
// uninstall.php — حذف عمومی داده پزشکی ممنوع (SRS FR-22.3).
// فقط با تنظیم `cpms_wipe_on_uninstall` (فنی) و تأیید صریح، جادولهایی که خود افزونه ساخته پاک می‌شوند.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$wipe = (bool) get_option('cpms_wipe_on_uninstall', false);
if (!$wipe) {
    return;
}

global $wpdb;
$prefix = 'cpms_';
$tables = $wpdb->get_col("SHOW TABLES LIKE '{$prefix}%'");
foreach ($tables as $t) {
    $wpdb->query("DROP TABLE IF EXISTS {$t}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — نام‌ها از SHOW TABLES معتبرند
}
delete_option('cpms_wipe_on_uninstall');
delete_option('cpms_schema_version');
