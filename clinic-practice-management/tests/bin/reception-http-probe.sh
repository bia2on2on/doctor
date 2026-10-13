#!/usr/bin/env bash
# Bounded, read-only Apache/mod_php liveness and clock observations for Reception.
# Output is limited to route, status, latency, PHP time, and process state; never bodies/cookies.
set +e

base="${1:-http://localhost:8080}"
label="${2:-unspecified}"
wp_dir="${3:-${WP_DIR:-}}"
base="${base%/}"

for route in /wp-login.php /wp-json/; do
    metrics="$(curl --silent --show-error --max-time 5 --output /dev/null \
        --write-out 'http=%{http_code} connect_s=%{time_connect} ttfb_s=%{time_starttransfer} total_s=%{time_total}' \
        "${base}${route}" 2>/dev/null)"
    curl_rc=$?
    printf 'RECEPTION_HTTP_PROBE label=%s route=%s curl_rc=%s %s\n' \
        "$label" "$route" "$curl_rc" "${metrics:-no_response}"
done

# Exercise the actual Apache PHP SAPI for a single bounded request. The temporary
# endpoint returns only the PHP clock and is removed on every normal/handled exit.
php_probe=""
body_file=""
cleanup_probe() {
    [ -z "$body_file" ] || rm -f -- "$body_file"
    [ -z "$php_probe" ] || rm -f -- "$php_probe"
}
trap cleanup_probe EXIT HUP INT TERM
if [ -n "$wp_dir" ] && [ -d "$wp_dir" ] && [ -w "$wp_dir" ]; then
    php_probe="$(mktemp "${wp_dir%/}/reception-clock-probe-XXXXXX.php" 2>/dev/null)"
    if [ -n "$php_probe" ]; then
        chmod 0644 "$php_probe" 2>/dev/null
        cat > "$php_probe" <<'PHP'
<?php
header('Content-Type: text/plain; charset=utf-8');
echo 'RECEPTION_PHP_CLOCK epoch=', time(),
    ' timezone=', date_default_timezone_get(),
    ' local=', date('Y-m-d\TH:i:sP'),
    ' utc=', gmdate('Y-m-d\TH:i:s\Z'), "\n";
PHP
        body_file="$(mktemp /tmp/reception-clock-probe-response.XXXXXX 2>/dev/null)"
        if [ -n "$body_file" ]; then
            metrics="$(curl --silent --show-error --max-time 5 --output "$body_file" \
                --write-out 'http=%{http_code} connect_s=%{time_connect} ttfb_s=%{time_starttransfer} total_s=%{time_total}' \
                "${base}/$(basename "$php_probe")" 2>/dev/null)"
            curl_rc=$?
            php_clock="$(grep -E '^RECEPTION_PHP_CLOCK epoch=[0-9]{9,} timezone=[[:alnum:]_+./-]+ local=[0-9T:+.-]+ utc=[0-9TZ:+.-]+$' "$body_file" 2>/dev/null | head -n 1)"
            printf 'RECEPTION_APACHE_PHP_CLOCK label=%s runner_epoch=%s curl_rc=%s %s php=%s\n' \
                "$label" "$(date +%s)" "$curl_rc" "${metrics:-no_response}" "${php_clock:-MISSING}"
        else
            printf 'RECEPTION_APACHE_PHP_CLOCK label=%s status=unavailable reason=response-tempfile-failed\n' "$label"
        fi
    else
        printf 'RECEPTION_APACHE_PHP_CLOCK label=%s status=unavailable reason=docroot-not-writable\n' "$label"
    fi
else
    printf 'RECEPTION_APACHE_PHP_CLOCK label=%s status=unavailable reason=docroot-not-writable\n' "$label"
fi
cleanup_probe
php_probe=""
body_file=""

apache_pid="$(pgrep -xo apache2 2>/dev/null)"
apache_count="$(pgrep -xc apache2 2>/dev/null)"
if [ -n "$apache_pid" ]; then
    apache_state="$(ps -o stat= -p "$apache_pid" 2>/dev/null | tr -d '[:space:]')"
else
    apache_state=MISSING
fi
printf 'RECEPTION_APACHE_PROBE label=%s state=%s process_count=%s\n' \
    "$label" "${apache_state:-UNKNOWN}" "${apache_count:-0}"
exit 0
