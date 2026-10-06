#!/usr/bin/env php
<?php

declare(strict_types=1);

use ClinicCore\Bootstrap\App;

/**
 * Phase 17 disposable-Pilot queue start-latency producer.
 *
 * enqueue: persist 100 non-PHI, system-scoped backup.run samples through the
 * production JobQueue API. collect: read only their persisted enqueue/first-
 * claim timestamps and terminal processing states. The random correlation
 * token is never printed or written to the raw evidence file.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$pilot_job_start_refuse = static function (): never {
    fwrite(STDERR, "JOB_START: precondition or collection refused\n");
    exit(1);
};

$wp_home = getenv('WP_HOME');
if (!is_string($wp_home) || trim($wp_home) === '') {
    $pilot_job_start_refuse();
}
$wp_load = rtrim($wp_home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wp-load.php';
if (!is_file($wp_load)) {
    $pilot_job_start_refuse();
}

require_once $wp_load;
App::boot();

$action = $argv[1] ?? '';
$token = getenv('CPMS_JOB_START_TOKEN');
if (!is_string($token) || preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
    $pilot_job_start_refuse();
}

$sample_count = 100;
$job_type = 'backup.run';

try {
    $db = App::db();

    // This is a SYSTEM-scope job. The one seeded synthetic Clinic check is only
    // a fail-closed Pilot bootstrap precondition; no Clinic id is selected,
    // stored in a payload, or used as job authority.
    $clinic_count = (int) $db->fetchValue(
        'SELECT COUNT(*) FROM ' . $db->table('cpms_clinics')
    );
    if ($clinic_count !== 1) {
        $pilot_job_start_refuse();
    }

    // BackupRunHandler is a no-op while backups are disabled. Never let this
    // measurement create backup files or invoke a configured storage provider.
    if (App::installationSettings()->getBackupEnabled() !== false) {
        $pilot_job_start_refuse();
    }

    $priority = App::RECURRING_JOBS[$job_type] ?? null;
    if (!is_int($priority) || $priority !== 1) {
        $pilot_job_start_refuse();
    }

    $table = $db->table('cpms_jobs');

    if ($action === 'enqueue') {
        $existing = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $table .
            ' WHERE type = %s AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, ' .
            "'$.pilot_measurement_correlation')) = %s",
            [$job_type, $token]
        );
        if ($existing !== 0) {
            $pilot_job_start_refuse();
        }

        for ($index = 0; $index < $sample_count; $index++) {
            $id = App::jobs()->enqueue(
                $job_type,
                ['pilot_measurement_correlation' => $token],
                priority: $priority,
                maxAttempts: 1
            );
            if ($id < 1) {
                $pilot_job_start_refuse();
            }
        }
        exit(0);
    }

    if ($action !== 'collect') {
        $pilot_job_start_refuse();
    }

    $rows = $db->fetchAll(
        'SELECT status, attempts, max_attempts, created_at, started_at FROM ' . $table .
        ' WHERE type = %s AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, ' .
        "'$.pilot_measurement_correlation')) = %s ORDER BY id ASC",
        [$job_type, $token]
    );
    if (count($rows) !== $sample_count) {
        $pilot_job_start_refuse();
    }

    $samples = [];
    foreach ($rows as $row) {
        $samples[] = [
            'created_at' => (string) ($row['created_at'] ?? ''),
            'started_at' => $row['started_at'] === null ? null : (string) $row['started_at'],
            'status' => (string) ($row['status'] ?? ''),
            'attempts' => (int) ($row['attempts'] ?? -1),
            'max_attempts' => (int) ($row['max_attempts'] ?? -1),
        ];
    }

    $raw_path = getenv('CPMS_JOB_START_RAW_PATH');
    if (!is_string($raw_path) || $raw_path === '' || file_exists($raw_path)) {
        $pilot_job_start_refuse();
    }
    $temporary_root = realpath(sys_get_temp_dir());
    $raw_directory = realpath(dirname($raw_path));
    if ($temporary_root === false || $raw_directory === false) {
        $pilot_job_start_refuse();
    }
    $temporary_prefix = $temporary_root . DIRECTORY_SEPARATOR . 'cpms-job-start.';
    if (!str_starts_with($raw_directory, $temporary_prefix)) {
        $pilot_job_start_refuse();
    }

    $raw = [
        'schema' => 'cpms.pilot-job-start-raw/1',
        'status' => 'ok',
        'job_type' => $job_type,
        'sample_count' => $sample_count,
        'samples' => $samples,
    ];
    $encoded = wp_json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (!is_string($encoded)) {
        $pilot_job_start_refuse();
    }
    $json = $encoded . "\n";
    $previous_umask = umask(0077);
    try {
        // Keep raw rows in the private, disposable Pilot temp directory only.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $written = file_put_contents($raw_path, $json, LOCK_EX);
    } finally {
        umask($previous_umask);
    }
    // Enforce owner-only access on this private, disposable-run evidence file.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
    if (!is_int($written) || $written !== strlen($json) || !chmod($raw_path, 0600)) {
        $pilot_job_start_refuse();
    }
} catch (\Throwable) {
    // Never echo DB errors, queue payloads, IDs, or arbitrary exception text.
    $pilot_job_start_refuse();
}
