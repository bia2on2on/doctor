<?php
/**
 * Disposable real-WordPress/DB regression fixture for the reception recovery
 * boundary. Synthetic identities only; the private fixture file is not a CI
 * artifact. Execute with WP-CLI eval-file (which cannot use strict_types).
 */

use ClinicCore\Application\Membership\ReceptionRecoveryService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}
$db = App::db();
$now = $db->nowUtcSql();
$assert = static function (bool $ok, string $code): void {
    if (!$ok) {
        WP_CLI::error('reception proof failed: ' . $code);
    }
};
$denied = static function (callable $operation, string $code) use ($assert): void {
    $rejected = false;
    try {
        $operation();
    } catch (RuntimeException | DomainException) {
        $rejected = true;
    }
    $assert($rejected, $code);
};
$insert = static function (string $table, array $data) use ($db, $assert): int {
    $assert($db->insert($table, $data), 'fixture-insert');
    $id = (int) $db->wpdb_last_insert_id();
    $assert($id > 0, 'fixture-id');
    return $id;
};
$password = (string) getenv('RECOVERY_PROOF_PASSWORD');
$assert($password !== '', 'fixture-password');
$manager = wp_create_user('ci_recovery_manager', $password, 'ci-recovery-manager@example.test');
$doctor = wp_create_user('ci_recovery_doctor', $password, 'ci-recovery-doctor@example.test');
$browser_doctor = wp_create_user('ci_recovery_browser_doctor', $password, 'ci-recovery-browser@example.test');
$admin = wp_create_user('ci_recovery_admin', $password, 'ci-recovery-admin@example.test');
foreach ([$manager, $doctor, $browser_doctor, $admin] as $user) {
    $assert(!is_wp_error($user) && (int) $user > 0, 'fixture-user');
}
$manager = (int) $manager;
$doctor = (int) $doctor;
$browser_doctor = (int) $browser_doctor;
$admin = (int) $admin;
get_userdata($manager)->set_role(RolesAndCapabilities::ROLE_MANAGER);
get_userdata($doctor)->set_role(RolesAndCapabilities::ROLE_DOCTOR);
get_userdata($browser_doctor)->set_role(RolesAndCapabilities::ROLE_DOCTOR);
get_userdata($admin)->set_role('administrator');

$org = $insert('cpms_organizations', [
    'name' => 'Synthetic reception proof organization', 'slug' => 'ci-reception-proof', 'status' => 'active',
    'created_at' => $now, 'updated_at' => $now,
]);
$make_clinic = static function (string $slug) use ($insert, $org, $now): int {
    return $insert('cpms_clinics', [
        'organization_id' => $org, 'name' => 'Synthetic ' . $slug, 'slug' => $slug,
        'timezone' => 'Asia/Tehran', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
};
$clinic_a = $make_clinic('ci-reception-a');
$clinic_b = $make_clinic('ci-reception-b');
$make_location = static function (int $clinic, string $slug, int $primary) use ($insert, $now): int {
    return $insert('cpms_locations', [
        'clinic_id' => $clinic, 'name' => 'Synthetic ' . $slug, 'slug' => $slug,
        'is_active' => 1, 'is_primary' => $primary, 'timezone' => 'Asia/Tehran',
        'created_at' => $now, 'updated_at' => $now,
    ]);
};
$a1 = $make_location($clinic_a, 'a-one', 1);
$a2 = $make_location($clinic_a, 'a-two', 0);
$b1 = $make_location($clinic_b, 'b-one', 1);
$b2 = $make_location($clinic_b, 'b-two', 0);
$make_membership = static function (int $clinic, int $user, string $role) use ($insert, $now): int {
    return $insert('cpms_clinic_memberships', [
        'clinic_id' => $clinic, 'wp_user_id' => $user, 'role_key' => $role,
        'scope_mode' => 'clinic', 'status' => 'active', 'is_primary' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
};
$manager_membership = $make_membership($clinic_a, $manager, RolesAndCapabilities::ROLE_MANAGER);
$doctor_membership = $make_membership($clinic_a, $doctor, RolesAndCapabilities::ROLE_DOCTOR);
$make_membership($clinic_b, $doctor, RolesAndCapabilities::ROLE_DOCTOR);
$make_membership($clinic_a, $browser_doctor, RolesAndCapabilities::ROLE_DOCTOR);
$clinician = $insert('cpms_clinicians', [
    'clinic_id' => $clinic_a, 'wp_user_id' => $doctor, 'full_name' => 'پزشک آزمایشی',
    'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
]);
$browser_clinician = $insert('cpms_clinicians', [
    'clinic_id' => $clinic_a, 'wp_user_id' => $browser_doctor, 'full_name' => 'پزشک مرورگر آزمایشی',
    'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
]);
$insert('cpms_clinician_locations', [
    'clinician_id' => $clinician, 'location_id' => $b1, 'is_primary' => 1, 'created_at' => $now,
]);
$service = new ReceptionRecoveryService($db, new MembershipRepository($db), App::authorization_service(), App::audit());
$assignments = static function (int $id) use ($db): array {
    return $db->fetchAll('SELECT location_id, is_primary FROM ' . $db->table('cpms_clinician_locations') . ' WHERE clinician_id = %d ORDER BY location_id', [$id]);
};
$audit_count = static function () use ($db): int {
    return (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_audit_logs') . ' WHERE action = %s', ['CLINICIAN_LOCATION_RECOVERY']);
};
$foreign_before = $assignments($clinician);

// Reproduce the PRE-FIX composition in an outer transaction that is always
// rolled back: actor authorized in A + global primitive accepts B and replaces
// B's original row. This is not a live-site exploit or a browser result.
$legacy_exposure = false;
try {
    $db->transactional(static function () use ($manager, $clinic_a, $clinician, $b1, $b2, $assignments, &$legacy_exposure): void {
        App::authorization_service()->authorize($manager, $clinic_a, RolesAndCapabilities::CONFIG);
        App::membership_service()->assign_clinician_locations($clinician, [$b2], $b2);
        $rows = $assignments($clinician);
        $legacy_exposure = count($rows) === 1 && (int) $rows[0]['location_id'] === $b2 && $b1 !== $b2;
        throw new RuntimeException('restore-legacy-probe');
    });
} catch (RuntimeException $e) {
    $assert($e->getMessage() === 'restore-legacy-probe', 'legacy-probe-error');
}
$assert($legacy_exposure && $assignments($clinician) === $foreign_before, 'legacy-probe-or-rollback');

// The new boundary rejects foreign selections even when the clinician is a
// member in both Clinics. Existing foreign rows and their primary are intact.
$denied(static fn() => $service->assign($manager, $clinic_a, $clinician, [$b2], null), 'foreign-location-accepted');
$assert($assignments($clinician) === $foreign_before, 'foreign-location-mutated');
$assert($service->assign($manager, $clinic_a, $clinician, [$a1], null), 'authorized-assignment');
$after = $assignments($clinician);
$assert(count($after) === 2, 'assignment-count');
$foreign = array_values(array_filter($after, static fn(array $row): bool => (int) $row['location_id'] === $b1));
$assert(count($foreign) === 1 && (int) $foreign[0]['is_primary'] === 1, 'foreign-primary-changed');
$count = $audit_count();
$assert(!$service->assign($manager, $clinic_a, $clinician, [$a1], null), 'idempotent-return');
$assert($audit_count() === $count && $assignments($clinician) === $after, 'idempotent-mutated');
$denied(static fn() => $service->assign($manager, $clinic_a, $clinician, [$a1], $a1), 'foreign-primary-moved');
$denied(static fn() => $service->assign($admin, $clinic_a, $clinician, [$a2], null), 'wp-admin-bypass');

$db->update('cpms_clinic_memberships', ['status' => 'suspended'], ['id' => $manager_membership]);
$denied(static fn() => $service->assign($manager, $clinic_a, $clinician, [$a2], null), 'suspended-manager-accepted');
$db->update('cpms_clinic_memberships', ['status' => 'active'], ['id' => $manager_membership]);
$db->update('cpms_clinic_memberships', ['status' => 'suspended'], ['id' => $doctor_membership]);
$denied(static fn() => $service->assign($manager, $clinic_a, $clinician, [$a2], null), 'suspended-clinician-accepted');
$db->update('cpms_clinic_memberships', ['status' => 'active'], ['id' => $doctor_membership]);
$db->update('cpms_clinicians', ['is_active' => 0], ['id' => $clinician]);
$denied(static fn() => $service->assign($manager, $clinic_a, $clinician, [$a2], null), 'inactive-clinician-accepted');
$db->update('cpms_clinicians', ['is_active' => 1], ['id' => $clinician]);
$db->update('cpms_locations', ['is_active' => 0], ['id' => $a2]);
$denied(static fn() => $service->assign($manager, $clinic_a, $clinician, [$a2], null), 'inactive-location-accepted');
$db->update('cpms_locations', ['is_active' => 1], ['id' => $a2]);
$db->update('cpms_clinics', ['status' => 'suspended'], ['id' => $clinic_a]);
$denied(static fn() => $service->assign($manager, $clinic_a, $clinician, [$a2], null), 'suspended-clinic-accepted');
$db->update('cpms_clinics', ['status' => 'active'], ['id' => $clinic_a]);
$db->update('cpms_organizations', ['status' => 'suspended'], ['id' => $org]);
$denied(static fn() => $service->assign($manager, $clinic_a, $clinician, [$a2], null), 'suspended-organization-accepted');
$db->update('cpms_organizations', ['status' => 'active'], ['id' => $org]);

$insert('cpms_membership_capabilities', ['membership_id' => $manager_membership, 'capability' => RolesAndCapabilities::CONFIG, 'effect' => 'deny', 'created_at' => $now]);
$denied(static fn() => $service->assign($manager, $clinic_a, $clinician, [$a2], null), 'explicit-deny-ignored');
$db->delete('cpms_membership_capabilities', ['membership_id' => $manager_membership, 'capability' => RolesAndCapabilities::CONFIG]);
foreach ([$manager_membership, $doctor_membership] as $limited_membership) {
    $db->update('cpms_clinic_memberships', ['scope_mode' => 'location'], ['id' => $limited_membership]);
    $insert('cpms_membership_locations', ['membership_id' => $limited_membership, 'location_id' => $a1]);
    $denied(static fn() => $service->assign($manager, $clinic_a, $clinician, [$a2], null), 'location-scope-bypassed');
    $db->delete('cpms_membership_locations', ['membership_id' => $limited_membership]);
    $db->update('cpms_clinic_memberships', ['scope_mode' => 'clinic'], ['id' => $limited_membership]);
}
$assert($assignments($clinician) === $after && $audit_count() === $count, 'denial-mutated');

// Remove the singleton only INSIDE an outer transaction, force the actual
// AuditLogger to fail after the proposed write, then roll back the deletion.
$guard_failure = false;
try {
    $db->transactional(static function () use ($db, $service, $manager, $clinic_a, $clinician, $a2, $assignments, $after, $audit_count, $count, $assert, &$guard_failure): void {
        $db->delete('cpms_audit_seq', ['id' => 1]);
        try {
            $service->assign($manager, $clinic_a, $clinician, [$a2], null);
        } catch (RuntimeException $e) {
            $guard_failure = str_contains($e->getMessage(), 'audit guard row missing');
        }
        $assert($guard_failure && $assignments($clinician) === $after && $audit_count() === $count, 'audit-failure-not-atomic');
        throw new RuntimeException('restore-guard-probe');
    });
} catch (RuntimeException $e) {
    $assert($e->getMessage() === 'restore-guard-probe', 'guard-probe-error');
}
$assert($guard_failure && (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_audit_seq') . ' WHERE id = 1') === 1, 'guard-not-restored');

// WordPress's query filter injects genuine SQL errors at precise reads/updates.
// Error display suppression is scoped to this disposable test and restored;
// production must throw the expected safe error and roll back its writes.
$wpdb = $db->wpdb();
$inject_sql_failure = static function (string $query_prefix, string $bad_sql, string $expected, callable $work) use ($wpdb, $assert): void {
    $filter = static function (string $sql) use ($query_prefix, $bad_sql): string {
        return str_starts_with($sql, $query_prefix) ? $bad_sql : $sql;
    };
    $was_suppressed = $wpdb->suppress_errors(true);
    add_filter('query', $filter);
    $expected_failure = false;
    try {
        $work();
    } catch (RuntimeException $error) {
        $expected_failure = $error->getMessage() === $expected;
    } finally {
        remove_filter('query', $filter);
        $wpdb->suppress_errors($was_suppressed);
    }
    $assert($expected_failure, 'sql-failure-not-rejected');
};
$bad_select = 'SELECT cpms_nonexistent_proof_column FROM ' . $db->table('cpms_audit_logs');
$attempt_change = static fn() => $service->assign($manager, $clinic_a, $clinician, [$a2], null);
$inject_sql_failure(
    'SELECT location_id, is_primary FROM ' . $db->table('cpms_clinician_locations'),
    $bad_select,
    'recovery assignment read failed',
    $attempt_change
);
$inject_sql_failure(
    'SELECT row_hash FROM ' . $db->table('cpms_audit_logs') . ' ORDER BY id DESC',
    $bad_select,
    'audit tail read failed',
    $attempt_change
);
$inject_sql_failure(
    'UPDATE ' . $db->table('cpms_audit_seq') . ' SET last_audit_id',
    'UPDATE ' . $db->table('cpms_audit_seq') . ' SET cpms_nonexistent_proof_column = 1 WHERE id = 1',
    'audit guard update failed',
    $attempt_change
);
$assert($assignments($clinician) === $after, 'sql-failure-assignment-rollback');
$assert($audit_count() === $count, 'sql-failure-audit-rollback');
$inject_sql_failure(
    'SELECT * FROM ' . $db->table('cpms_audit_logs') . ' ORDER BY id ASC',
    $bad_select,
    'audit verification read failed',
    static fn() => App::audit()->verifyFullChain()
);
$inject_sql_failure(
    'SELECT * FROM ' . $db->table('cpms_audit_logs') . ' ORDER BY id DESC',
    $bad_select,
    'audit verification read failed',
    static fn() => App::audit()->verifyChain(1)
);
$inject_sql_failure(
    'SELECT row_hash FROM ' . $db->table('cpms_audit_logs') . ' WHERE id <',
    $bad_select,
    'audit predecessor read failed',
    static fn() => App::audit()->verifyChain(1)
);

$fixture = [
    'manager_login' => 'ci_recovery_manager', 'admin_login' => 'ci_recovery_admin', 'password' => $password,
    'clinic_a' => $clinic_a, 'clinic_b' => $clinic_b, 'a1' => $a1, 'a2' => $a2, 'b1' => $b1, 'b2' => $b2,
    'clinician' => $clinician, 'browser_clinician' => $browser_clinician,
];
$fixture_path = '/tmp/cpms-reception-proof-fixture.json';
$assert(file_put_contents($fixture_path, json_encode($fixture, JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'fixture-file');
$assert(chmod($fixture_path, 0600), 'fixture-permissions');
echo "RECEPTION_SERVICE_PROOF legacy_composition=reproduced_and_rolled_back cross_clinic=denied foreign_primary=preserved idempotence=ok auth_and_suspension=ok location_scope=ok audit_failure_rollback=ok sql_fail_closed=ok\n";
