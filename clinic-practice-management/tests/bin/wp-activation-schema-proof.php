<?php
/**
 * Disposable WP-CLI installation/upgrade probe; synthetic data only.
 * Executed on real MySQL and MariaDB by the facility-matrix workflow.
 */

// WP-CLI evaluates this file with a closing-PHP-tag prefix. PHP requires
// declare(strict_types=1) to be the first statement, which is incompatible
// with that eval mode. Keep this disposable harness usable on all supported
// WP-CLI versions without relying on --use-include.

use ClinicCore\Bootstrap\App;

if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}

$stage = $args[0] ?? '';
$state_path = '/tmp/cpms-activation-upgrade-state.json';
$db = App::db();
$logs = $db->table('cpms_audit_logs');
$schema = App::migrations()->currentVersion();
$expect = static function (bool $condition, string $code): void {
    if (!$condition) {
        WP_CLI::error('activation proof failed: ' . $code);
    }
};

if ($stage === 'baseline') {
    $expect($schema === '2026_10_27_0030', 'baseline-not-0030');
    // Create a real durable predecessor before the upgrade; no patient/OTP data.
    $seed_id = App::audit()->log('CI_UPGRADE_BASELINE', null, 'ci_upgrade', null, null, null, null, [], null, true);
    $seed = $db->fetchRow('SELECT id, row_hash FROM ' . $logs . ' WHERE id = %d', [$seed_id]);
    $expect(is_array($seed) && (int) ($seed['id'] ?? 0) === $seed_id, 'baseline-audit-missing');
    $hash = (string) ($seed['row_hash'] ?? '');
    $expect(preg_match('/^[a-f0-9]{64}$/', $hash) === 1, 'baseline-audit-hash-invalid');
    $state = json_encode(['seed_id' => $seed_id, 'seed_hash' => $hash], JSON_THROW_ON_ERROR);
    $expect(file_put_contents($state_path, $state, LOCK_EX) !== false, 'baseline-state-write');
    echo "ACTIVATION_PROOF baseline=0030 synthetic_audit=recorded\n";
    return;
}

$expect(in_array($stage, ['fresh', 'fresh-repeat', 'candidate', 'candidate-repeat'], true), 'invalid-stage');
$expect($schema === '2026_10_31_0031', 'candidate-not-0031');
$migration_count = (int) $db->fetchValue(
    'SELECT COUNT(*) FROM ' . $db->table('cpms_schema_migrations') . ' WHERE version = %s',
    ['2026_10_31_0031']
);
$expect($migration_count === 1, 'migration-not-exactly-once');
$guard = $db->fetchRow('SELECT id, last_audit_id FROM ' . $db->table('cpms_audit_seq') . ' WHERE id = %d', [1]);
$expect(is_array($guard) && (int) ($guard['id'] ?? 0) === 1, 'guard-missing');
$max_id = (int) $db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM ' . $logs);
$expect((int) ($guard['last_audit_id'] ?? -1) === $max_id, 'guard-tail-mismatch');

if ($stage === 'fresh' || $stage === 'fresh-repeat') {
    echo 'ACTIVATION_PROOF ' . $stage . " schema=0031 migration_count=1 guard=present\n";
    return;
}

$state_raw = @file_get_contents($state_path);
$expect(is_string($state_raw), 'baseline-state-missing');
$state = json_decode((string) $state_raw, true);
$expect(is_array($state) && isset($state['seed_id'], $state['seed_hash']), 'baseline-state-invalid');
$seed_id = (int) $state['seed_id'];
$seed = $db->fetchRow('SELECT row_hash FROM ' . $logs . ' WHERE id = %d', [$seed_id]);
$expect(is_array($seed) && hash_equals((string) $state['seed_hash'], (string) ($seed['row_hash'] ?? '')), 'baseline-history-changed');

if ($stage === 'candidate') {
    $new_id = App::audit()->log('CI_UPGRADE_CANDIDATE', null, 'ci_upgrade', null, null, null, null, [], null, true);
    $expect($new_id > $seed_id, 'candidate-audit-missing');
    $before_count = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $logs);
    $before_guard = (int) $db->fetchValue('SELECT last_audit_id FROM ' . $db->table('cpms_audit_seq') . ' WHERE id = %d', [1]);
    $rolled_back = false;
    try {
        $db->transactional(static function (): void {
            App::audit()->log('CI_UPGRADE_ROLLBACK', null, 'ci_upgrade', null, null, null, null, [], null, true);
            throw new RuntimeException('intentional-rollback-probe');
        });
    } catch (RuntimeException $error) {
        $rolled_back = $error->getMessage() === 'intentional-rollback-probe';
    }
    $expect($rolled_back, 'rollback-exception');
    $expect((int) $db->fetchValue('SELECT COUNT(*) FROM ' . $logs) === $before_count, 'rollback-audit-survived');
    $expect((int) $db->fetchValue('SELECT last_audit_id FROM ' . $db->table('cpms_audit_seq') . ' WHERE id = %d', [1]) === $before_guard, 'rollback-guard-advanced');
}

$chain = App::audit()->verifyFullChain();
$suffix = App::audit()->verifyChain(1);
$expect(($chain['ok'] ?? false) === true && (int) ($chain['checked'] ?? 0) >= 2, 'full-chain-invalid');
$expect(($suffix['ok'] ?? false) === true && (int) ($suffix['checked'] ?? 0) === 1, 'suffix-invalid');
// Cross-check the suffix against the actual preceding durable row, independently
// of verifyChain()'s predecessor query. No values are printed.
$tail = $db->fetchRow('SELECT id, prev_hash FROM ' . $logs . ' ORDER BY id DESC LIMIT 1');
$expect(is_array($tail), 'tail-missing');
$predecessor = $db->fetchRow('SELECT row_hash FROM ' . $logs . ' WHERE id < %d ORDER BY id DESC LIMIT 1', [(int) $tail['id']]);
$expect(is_array($predecessor) && hash_equals((string) ($predecessor['row_hash'] ?? ''), (string) ($tail['prev_hash'] ?? '')), 'suffix-predecessor-mismatch');
echo 'ACTIVATION_PROOF ' . $stage . " schema=0031 baseline_history=preserved full_chain=ok suffix_predecessor=ok" . ($stage === 'candidate' ? ' audit_rollback=ok' : '') . "\n";
