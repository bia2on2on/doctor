<?php

/**
 * TEST FIXTURE — NON-PRODUCTION. قالبِ مستقلِ پوستهٔ CPMS (proof shell template).
 *
 * این قالب کل سند HTML را می‌سازد و عمداً هیچ‌یک از این‌ها را صدا نمی‌زند:
 * `get_header()` / `get_footer()` / `wp_head()` / `wp_footer()` / `wp_body_open()`.
 * دلیل: قرارداد مالک — تم فعالِ وردپرس نباید header/footer/layout/navigation/
 * پوستهٔ بصریِ پورتال را تعیین کند؛ حتی `wp_head()` خروجیِ سراسریِ تم/افزونه‌ها را
 * چاپ می‌کند و زیر جابه‌جاییِ تم، قراردادِ پوسته را شکننده می‌سازد.
 *
 * نشانگرهای قرارداد (assertion های تست — طراحی بصری نیستند):
 *   CPMS-STANDALONE-SHELL-PROOF        — نشانگر سند/پوستهٔ CPMS
 *   CPMS-SHELL-PROOF-USER:{login}      — نشست وردپرس در دسترس (یا `anonymous`)
 *   CPMS-SHELL-PROOF-RUNTIME-LOADED    — runtime افزونهٔ CPMS روی همین درخواست حاضر است
 *   data-cpms-standalone-shell="proof-fixture" — مالکیتِ کل سند توسط پوستهٔ CPMS
 * و در پیکربندی JSON (بدون PHI):
 *   rest_root / users_me_url / nonce (`wp_rest`) / logout_url / user
 *
 * عمداً بدون هیچ asset جهانی/فایل اضافی — استایل inline حداقلی فقط برای خوانایی.
 */

defined('ABSPATH') || exit;

// Fail-safe: این فایل فقط به‌عنوان «قالب» (خروجیِ فیلترِ template_include) اجرا
// می‌شود. اگر به‌اشتباه زودتر include شود (مثلاً به‌عنوان mu-plugin در ریشه)، هنوز
// pluggable.php بار نشده است — بی‌صدا خارج شو تا bootstrap وردپرس هرگز fatal نگیرد.
if (!function_exists('wp_get_current_user')) {
    return;
}

$user        = wp_get_current_user();
$user_login  = ($user instanceof WP_User && $user->ID > 0) ? (string) $user->user_login : 'anonymous';
$is_logged_in = $user_login !== 'anonymous';

$config = [
    'fixture'      => 'standalone-shell-proof',
    'shell_contract' => 'v1',
    'rest_root'    => (string) rest_url(''),
    'users_me_url' => (string) rest_url('wp/v2/users/me'),
    'nonce'        => (string) wp_create_nonce('wp_rest'),
    'logout_url'   => (string) wp_logout_url(),
    'user'         => $user_login,
];

$runtime_marker = class_exists(\ClinicCore\Bootstrap\App::class)
    ? 'CPMS-SHELL-PROOF-RUNTIME-LOADED'
    : 'CPMS-SHELL-PROOF-RUNTIME-MISSING';
?><!DOCTYPE html>
<html lang="fa" dir="rtl" data-cpms-standalone-shell="proof-fixture">
<head>
<meta charset="<?php echo esc_attr((string) get_bloginfo('charset')); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html((string) get_bloginfo('name')); ?></title>
<style>
.cpms-shell-proof { font-family: Tahoma, sans-serif; margin: 2rem; }
</style>
</head>
<body>
<div id="cpms-standalone-shell-proof" class="cpms-shell-proof" data-cpms-shell="standalone-proof-fixture" data-shell-contract="v1" data-shell-user="<?php echo esc_attr($user_login); ?>">
<h1>CPMS-STANDALONE-SHELL-PROOF</h1>
<p data-role="runtime"><?php echo esc_html($runtime_marker); ?></p>
<p data-role="session">CPMS-SHELL-PROOF-USER:<?php echo esc_html($is_logged_in ? $user_login : 'anonymous'); ?></p>
<script type="application/json" class="cpms-shell-proof__config"><?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
</div>
</body>
</html>
