<?php

declare(strict_types=1);

/**
 * Shared helpers for the explicitly invoked disposable-site WP-CLI tools.
 *
 * This file is development tooling only. It is outside the installable plugin,
 * registers no WordPress hook, and must never be copied into a release ZIP.
 */

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Db\CpmsDb;

const CPMS_DS_FORMAT = 1;
const CPMS_DS_MOBILE = '09000000000';
const CPMS_DS_CONFIRM = 'I_UNDERSTAND_THIS_IS_A_DISPOSABLE_EMPTY_SITE';
const CPMS_DS_DESTROY_CONFIRM = 'DESTROY_DISPOSABLE_SITE_AND_DATABASE';

/** @return never */
function cpms_ds_fail(string $code, string $message): void
{
    throw new RuntimeException($code . ': ' . $message);
}

function cpms_ds_env(string $name, bool $required = true): string
{
    $value = trim((string) getenv($name));
    if ($required && $value === '') {
        cpms_ds_fail('CONFIG', $name . ' is required');
    }

    return $value;
}

function cpms_ds_path_within(string $path, string $parent): bool
{
    $path = rtrim($path, DIRECTORY_SEPARATOR);
    $parent = rtrim($parent, DIRECTORY_SEPARATOR);

    return $path === $parent || str_starts_with($path . DIRECTORY_SEPARATOR, $parent . DIRECTORY_SEPARATOR);
}

/** Resolve a new/existing secret path and enforce the local-file boundary. */
function cpms_ds_secret_path(string $path): string
{
    if ($path === '' || !str_starts_with($path, DIRECTORY_SEPARATOR) || basename($path) === '') {
        cpms_ds_fail('SECRET_PATH', 'an absolute regular-file path is required');
    }
    $parent = realpath(dirname($path));
    if ($parent === false || !is_dir($parent)) {
        cpms_ds_fail('SECRET_PATH', 'the protected parent directory must already exist');
    }
    if (((int) fileperms($parent)) & 077) {
        cpms_ds_fail('SECRET_PATH', 'the protected parent directory must not be accessible by group/other');
    }
    $web_root = realpath(ABSPATH);
    if ($web_root !== false && cpms_ds_path_within($parent, $web_root)) {
        cpms_ds_fail('SECRET_PATH', 'secret files must be outside the WordPress web root');
    }
    $repository = realpath(dirname(__DIR__, 3));
    if ($repository !== false && cpms_ds_path_within($parent, $repository)) {
        cpms_ds_fail('SECRET_PATH', 'secret files must be outside the Git worktree');
    }
    if (is_link($path)) {
        cpms_ds_fail('SECRET_PATH', 'symbolic links are refused');
    }
    if (file_exists($path)) {
        if (!is_file($path)) {
            cpms_ds_fail('SECRET_PATH', 'a regular file is required');
        }
        if (((int) fileperms($path)) & 077) {
            cpms_ds_fail('SECRET_PATH', 'the secret file mode must be 0600 or stricter');
        }
    }

    return $parent . DIRECTORY_SEPARATOR . basename($path);
}

/** @return array<string, mixed> */
function cpms_ds_read_json(string $path): array
{
    $path = cpms_ds_secret_path($path);
    if (!is_file($path)) {
        cpms_ds_fail('SECRET_FILE_MISSING', 'the protected JSON file does not exist');
    }
    $raw = file_get_contents($path);
    if ($raw === false || strlen($raw) > 1048576) {
        cpms_ds_fail('SECRET_FILE_INVALID', 'the protected JSON file cannot be read safely');
    }
    try {
        $data = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        cpms_ds_fail('SECRET_FILE_INVALID', 'the protected JSON file is malformed');
    }
    if (!is_array($data)) {
        cpms_ds_fail('SECRET_FILE_INVALID', 'the protected JSON root must be an object');
    }

    return $data;
}

/** @param array<string, mixed> $data */
function cpms_ds_write_json(string $path, array $data): void
{
    $path = cpms_ds_secret_path($path);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    $temporary = tempnam(dirname($path), '.cpms-ds-');
    if ($temporary === false) {
        cpms_ds_fail('SECRET_WRITE', 'cannot allocate a protected temporary file');
    }
    try {
        if (!chmod($temporary, 0600) || file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            cpms_ds_fail('SECRET_WRITE', 'cannot write the protected JSON file');
        }
        if (!rename($temporary, $path) || !chmod($path, 0600)) {
            cpms_ds_fail('SECRET_WRITE', 'cannot atomically replace the protected JSON file');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    if (((int) fileperms($path)) & 077) {
        cpms_ds_fail('SECRET_WRITE', 'protected JSON permission verification failed');
    }
}

/** @return resource */
function cpms_ds_lock(string $state_path)
{
    $state_path = cpms_ds_secret_path($state_path);
    $lock_path = $state_path . '.lock';
    if (is_link($lock_path)) {
        cpms_ds_fail('SECRET_PATH', 'lock symbolic links are refused');
    }
    $handle = fopen($lock_path, 'c');
    if ($handle === false || !chmod($lock_path, 0600) || !flock($handle, LOCK_EX)) {
        cpms_ds_fail('LOCK', 'cannot obtain the provisioning lock');
    }

    return $handle;
}

function cpms_ds_db(): CpmsDb
{
    if (!class_exists(App::class)) {
        cpms_ds_fail('PLUGIN_INACTIVE', 'CPMS Classic must be installed and active');
    }

    return App::db();
}

function cpms_ds_admin_id(): int
{
    $actor_id = (int) get_current_user_id();
    $user = $actor_id > 0 ? get_userdata($actor_id) : false;
    $administrator = $user !== false
        && user_can($actor_id, 'manage_options')
        && (in_array('administrator', (array) $user->roles, true) || (function_exists('is_super_admin') && is_super_admin($actor_id)));
    if (!$administrator) {
        cpms_ds_fail('ADMIN_REQUIRED', 'invoke WP-CLI as an existing WordPress Administrator');
    }

    return $actor_id;
}

/** @return list<string> */
function cpms_ds_empty_tables(): array
{
    return [
        // The three structural bootstrap rows are checked separately below.
        'cpms_organizations',
        'cpms_clinics',
        'cpms_locations',
        'cpms_clinicians',
        'cpms_clinic_memberships',
        'cpms_organization_memberships',
        'cpms_organization_membership_capabilities',
        'cpms_membership_capabilities',
        'cpms_membership_locations',
        'cpms_clinician_locations',
        'cpms_schedule',
        'cpms_schedule_exceptions',
        'cpms_schedule_slots',
        'cpms_slot_holds',
        'cpms_patients',
        'cpms_patient_identities',
        'cpms_patient_identity_links',
        'cpms_patient_user_links',
        'cpms_patient_merges',
        'cpms_appointments',
        'cpms_visits',
        'cpms_clinical_notes',
        'cpms_prescriptions',
        'cpms_medical_attachments',
        'cpms_services',
        'cpms_invoices',
        'cpms_invoice_items',
        'cpms_payments',
        'cpms_payment_adjustments',
        'cpms_sms_messages',
        'cpms_otp_tokens',
        'cpms_idempotency_keys',
        'cpms_rate_limits',
        'cpms_notifications',
        'cpms_settings',
    ];
}

/** @return array<string, int> */
function cpms_ds_counts(): array
{
    $db = cpms_ds_db();
    $counts = [];
    foreach (cpms_ds_empty_tables() as $table) {
        $counts[$table] = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table($table));
    }

    return $counts;
}

function cpms_ds_assert_empty_site(): void
{
    $counts = cpms_ds_counts();
    foreach ($counts as $table => $count) {
        // A fresh CPMS installation necessarily contains its three structural
        // bootstrap rows. They are validated below; every operational table
        // must still be empty before any synthetic account or fixture write.
        if (in_array($table, ['cpms_organizations', 'cpms_clinics', 'cpms_locations', 'cpms_settings'], true)) {
            continue;
        }
        if ($count !== 0) {
            cpms_ds_fail('NONEMPTY_SITE', 'refusing provisioning because ' . $table . ' is not empty');
        }
    }

    $db = cpms_ds_db();
    // Activation can run the production queue wake/tick path. Its only
    // acceptable pre-provisioning setting is the inert system heartbeat;
    // FirstPracticeSetupService uses the same contract. Any setup, SMS,
    // storage, or other setting is still a non-empty-site refusal.
    $settings = $db->fetchAll(
        'SELECT clinic_id, `key`, value_json, updated_by_wp_user_id FROM ' . $db->table('cpms_settings') . ' ORDER BY id'
    );
    foreach ($settings as $setting) {
        $heartbeat = json_decode((string) ($setting['value_json'] ?? ''), true);
        $is_inert_heartbeat = (int) ($setting['clinic_id'] ?? 0) === 1
            && (string) ($setting['key'] ?? '') === 'jobs.last_tick_at'
            && in_array($setting['updated_by_wp_user_id'] ?? null, [null, 0, '0'], true)
            && is_int($heartbeat) && $heartbeat > 0;
        if (!$is_inert_heartbeat) {
            cpms_ds_fail('NONEMPTY_SITE', 'refusing provisioning because a non-heartbeat CPMS setting exists');
        }
    }
    $organizations = $db->fetchAll('SELECT id, slug, status FROM ' . $db->table('cpms_organizations') . ' ORDER BY id');
    $clinics = $db->fetchAll('SELECT id, organization_id, slug, status FROM ' . $db->table('cpms_clinics') . ' ORDER BY id');
    $locations = $db->fetchAll('SELECT id, clinic_id, slug, is_primary, is_active FROM ' . $db->table('cpms_locations') . ' ORDER BY id');
    $bootstrap_ok = count($organizations) === 1
        && (int) $organizations[0]['id'] === 1
        && $organizations[0]['slug'] === 'default'
        && $organizations[0]['status'] === 'active'
        && count($clinics) === 1
        && (int) $clinics[0]['id'] === 1
        && (int) $clinics[0]['organization_id'] === 1
        && $clinics[0]['slug'] === 'default'
        && $clinics[0]['status'] === 'active'
        && count($locations) === 1
        && (int) $locations[0]['clinic_id'] === 1
        && $locations[0]['slug'] === 'main'
        && (int) $locations[0]['is_primary'] === 1
        && (int) $locations[0]['is_active'] === 1;
    if (!$bootstrap_ok || $counts['cpms_organizations'] !== 1 || $counts['cpms_clinics'] !== 1 || $counts['cpms_locations'] !== 1) {
        cpms_ds_fail('NONEMPTY_SITE', 'only the untouched default CPMS bootstrap Organization/Clinic/Location is allowed');
    }

    $existing = get_users([
        'search' => 'cpms-disposable-*',
        'search_columns' => ['user_login'],
        'fields' => 'ID',
        'number' => 1,
    ]);
    if ($existing !== []) {
        cpms_ds_fail('NONEMPTY_SITE', 'a disposable-looking WordPress account already exists');
    }
    if (defined('CPMS_DEV_OTP_ECHO')) {
        cpms_ds_fail('UNSAFE_OTP_CONFIG', 'CPMS_DEV_OTP_ECHO must not be defined');
    }
}

/** @return array<string, mixed> */
function cpms_ds_load_state(string $path, bool $require_active = true): array
{
    $state = cpms_ds_read_json($path);
    if ((int) ($state['format'] ?? 0) !== CPMS_DS_FORMAT
        || preg_match('/^[a-f0-9]{16}$/', (string) ($state['fixture_id'] ?? '')) !== 1
        || ($require_active && (string) ($state['status'] ?? '') !== 'active')
    ) {
        cpms_ds_fail('STATE_INVALID', 'the protected fixture marker is invalid or incomplete');
    }
    $site = (array) ($state['site'] ?? []);
    global $wpdb;
    if ((string) ($site['home_url'] ?? '') !== home_url()
        || (string) ($site['table_prefix'] ?? '') !== (string) $wpdb->prefix
    ) {
        cpms_ds_fail('STATE_SITE_MISMATCH', 'the protected fixture belongs to a different site or prefix');
    }
    if ($require_active) {
        $accounts = (array) ($state['accounts'] ?? []);
        $expected = ['organization_manager', 'clinic_manager', 'doctor', 'receptionist', 'accountant', 'patient'];
        if (array_keys($accounts) !== $expected || (int) ($state['clinic_id'] ?? 0) <= 0) {
            cpms_ds_fail('STATE_INVALID', 'the protected fixture is missing its six-account or Clinic marker');
        }
    }

    return $state;
}

/** @param array<string, mixed> $state */
function cpms_ds_assert_fixture_owner(array $state): void
{
    $db = cpms_ds_db();
    $clinic_id = (int) $state['clinic_id'];
    $organization_id = (int) ($state['organization_id'] ?? 0);
    $fixture_id = (string) $state['fixture_id'];
    $row = $db->fetchRow(
        'SELECT id, organization_id, slug FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d LIMIT 1',
        [$clinic_id]
    );
    if ($row === null || (int) $row['organization_id'] !== $organization_id
        || (string) $row['slug'] !== 'cpms-disposable-clinic-' . $fixture_id
    ) {
        cpms_ds_fail('STATE_OWNERSHIP', 'the protected fixture marker does not match its synthetic Clinic');
    }
}

function cpms_ds_safe_summary(array $state, bool $idempotent): string
{
    return wp_json_encode([
        'status' => 'active',
        'idempotent' => $idempotent,
        'accounts' => count((array) ($state['accounts'] ?? [])),
        'organization_id' => (int) ($state['organization_id'] ?? 0),
        'clinic_id' => (int) ($state['clinic_id'] ?? 0),
        'location_id' => (int) ($state['location_id'] ?? 0),
        'shortcode' => (string) ($state['shortcode'] ?? ''),
        'links' => (array) ($state['links'] ?? []),
        'credential_file' => 'protected-local-file',
        'credential_mode' => '0600',
    ], JSON_UNESCAPED_SLASHES);
}

/** Print only a non-secret operational error marker; never a stack/state dump. */
function cpms_ds_report_failure(Throwable $error, string $stage): void
{
    $message = $error instanceof RuntimeException ? $error->getMessage() : 'unexpected internal failure';
    if (!preg_match('/^[A-Z][A-Z0-9_]+: [^\r\n]{1,240}$/', $message)) {
        $message = 'unexpected internal failure';
    }
    fwrite(STDERR, 'CPMS_DISPOSABLE_ERROR stage=' . $stage . ' type=' . get_class($error) . ' message=' . $message . PHP_EOL);
}
