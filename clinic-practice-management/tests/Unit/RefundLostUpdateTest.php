<?php

declare(strict_types=1);

/*
 * B1 — FinanceService::refundPayment lost-update regression test (pure Unit, no WP).
 *
 * The production code reads `refunded_amount` BEFORE opening the transaction
 * (FinanceService.php, refundPayment) and computes the new total from that stale
 * value inside the transaction, so two interleaved refunds on one payment can
 * overwrite each other / bypass the `refund <= amount - refunded` guard
 * (docs/state-machines/payment.md P3).
 *
 * Real parallelism is impossible in a single-threaded PHPUnit process, so the
 * interleaving is simulated deterministically: the fake wpdb fires a hook when
 * refund A takes its row lock (SELECT ... FOR UPDATE) and runs refund B to
 * completion at exactly that point — i.e. both refunds have already performed
 * their pre-transaction read when the first write happens.
 */

namespace {
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    if (!defined('OBJECT')) {
        define('OBJECT', 'OBJECT');
    }

    if (!class_exists('wpdb')) {
        /**
         * Minimal in-memory wpdb fake: only the query shapes used by
         * FinanceService::refundPayment and its authorization/audit path.
         */
        class wpdb
        {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public int $insert_id = 0;
            public bool $suppress_errors = false;

            /** @var array<string, list<array<string, mixed>>> */
            public array $tables = [];

            /** @var null|callable(string, wpdb): void */
            public $hook = null;

            public function suppress_errors($suppress = true)
            {
                $had = $this->suppress_errors;
                $this->suppress_errors = (bool) $suppress;

                return $had;
            }

            public function prepare($sql, ...$args)
            {
                $i = 0;

                return preg_replace_callback(
                    '/%[dsf]/',
                    static function (array $m) use (&$i, $args): string {
                        $v = $args[$i++] ?? null;
                        if ($m[0] === '%d') {
                            return (string) (int) $v;
                        }
                        if ($m[0] === '%f') {
                            return (string) (float) $v;
                        }

                        return "'" . addslashes((string) $v) . "'";
                    },
                    (string) $sql
                );
            }

            private function dispatch(string $sql): void
            {
                $this->last_error = '';
                if ($this->hook !== null) {
                    ($this->hook)($sql, $this);
                }
            }

            private function rowById(string $table, int $id): ?array
            {
                foreach ($this->tables[$table] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === $id) {
                        return $row;
                    }
                }

                return null;
            }

            public function get_row($sql, $output = ARRAY_A)
            {
                $this->dispatch((string) $sql);
                $sql = (string) $sql;

                if (preg_match('/FROM\s+(wp_cpms_payments)\s+WHERE\s+id\s*=\s*(\d+)/', $sql, $m)) {
                    return $this->rowById($m[1], (int) $m[2]);
                }
                if (preg_match('/FROM\s+(wp_cpms_invoices)\s+WHERE\s+id\s*=\s*(\d+)/', $sql, $m)) {
                    return $this->rowById($m[1], (int) $m[2]);
                }
                if (preg_match('/clinic_id\s*=\s*(\d+)/', $sql, $cm)
                    && preg_match('/wp_user_id\s*=\s*(\d+)/', $sql, $um)
                    && str_contains($sql, 'cpms_clinic_memberships')) {
                    $m = [0 => '', 1 => $cm[1], 2 => $um[1]];
                    $activeOnly = str_contains($sql, "status = 'active'");
                    foreach ($this->tables['wp_cpms_clinic_memberships'] ?? [] as $row) {
                        if ((int) $row['clinic_id'] === (int) $m[1] && (int) $row['wp_user_id'] === (int) $m[2]
                            && (!$activeOnly || $row['status'] === 'active')) {
                            return $row;
                        }
                    }

                    return null;
                }
                if (str_contains($sql, 'SELECT cli.wp_user_id FROM')) {
                    return ['wp_user_id' => $this->tables['__owning_clinician'] ?? null];
                }
                if (str_contains($sql, 'FROM wp_cpms_audit_seq')) {
                    return $this->rowById('wp_cpms_audit_seq', 1);
                }
                if (preg_match('/SELECT\s+row_hash\s+FROM\s+(wp_cpms_audit_logs)\s+ORDER\s+BY\s+id\s+DESC/', $sql)) {
                    $logs = $this->tables['wp_cpms_audit_logs'] ?? [];
                    $last = $logs === [] ? null : $logs[count($logs) - 1];

                    return $last === null ? null : ['row_hash' => $last['row_hash']];
                }
                if (str_contains($sql, 'cpms_payment_adjustments')) {
                    return ['credit' => 0, 'debit' => 0];
                }

                return null;
            }

            public function get_results($sql, $output = ARRAY_A)
            {
                $this->dispatch((string) $sql);
                $sql = (string) $sql;

                if (str_contains($sql, 'FROM wp_cpms_membership_capabilities')
                    && preg_match('/membership_id\s*=\s*(\d+)/', $sql, $m)) {
                    $out = [];
                    foreach ($this->tables['wp_cpms_membership_capabilities'] ?? [] as $row) {
                        if ((int) $row['membership_id'] === (int) $m[1]) {
                            $out[] = ['capability' => $row['capability'], 'effect' => $row['effect']];
                        }
                    }

                    return $out;
                }
                if (preg_match('/FROM\s+(wp_cpms_invoice_items)\s+WHERE\s+invoice_id\s*=\s*(\d+)/', $sql, $m)) {
                    $out = [];
                    foreach ($this->tables['wp_cpms_invoice_items'] ?? [] as $row) {
                        if ((int) $row['invoice_id'] === (int) $m[1]) {
                            $out[] = $row;
                        }
                    }

                    return $out;
                }
                if (preg_match('/FROM\s+(wp_cpms_payments)\s+WHERE\s+invoice_id\s*=\s*(\d+)/', $sql, $m)) {
                    $out = [];
                    foreach ($this->tables['wp_cpms_payments'] ?? [] as $row) {
                        if ((int) $row['invoice_id'] === (int) $m[1]) {
                            $out[] = $row;
                        }
                    }

                    return $out;
                }

                return [];
            }

            public function get_var($sql)
            {
                $row = $this->get_row($sql);

                return $row === null ? null : reset($row);
            }

            public function query($sql)
            {
                $this->dispatch((string) $sql);
                $sql = (string) $sql;
                if (preg_match('/\b(START TRANSACTION|COMMIT|ROLLBACK|SAVEPOINT|RELEASE)\b/i', $sql)) {
                    return 1;
                }
                if (preg_match('/UPDATE\s+(wp_cpms_audit_seq)\s+SET/i', $sql)) {
                    return 1;
                }

                return 0;
            }

            public function insert($table, $data, $format = null)
            {
                $this->dispatch('INSERT INTO ' . $table);
                $rows = $this->tables[$table] ?? [];
                $data['id'] = count($rows) + 1;
                $rows[] = $data;
                $this->tables[$table] = $rows;
                $this->insert_id = (int) $data['id'];

                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $this->dispatch('UPDATE ' . $table);
                $affected = 0;
                foreach ($this->tables[$table] ?? [] as $i => $row) {
                    $match = true;
                    foreach ($where as $k => $v) {
                        if ((string) ($row[$k] ?? '') !== (string) $v) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $this->tables[$table][$i] = array_merge($row, $data);
                        ++$affected;
                    }
                }

                return $affected;
            }

            public function delete($table, $where, $format = null)
            {
                $this->tables[$table] = array_values(array_filter(
                    $this->tables[$table] ?? [],
                    static fn (array $row): bool => (int) ($row['id'] ?? 0) !== (int) ($where['id'] ?? 0)
                ));

                return 1;
            }
        }
    }

    if (!function_exists('get_userdata')) {
        function get_userdata($id)
        {
            return (object) ['ID' => (int) $id, 'roles' => ['cpms_doctor']];
        }
    }
}

namespace ClinicCore\Tests\Unit {

    use ClinicCore\Application\Finance\FinanceException;
    use ClinicCore\Application\Finance\FinanceService;
    use ClinicCore\Application\Scope\ClinicScope;
    use ClinicCore\Application\Scope\ScopeContext;
    use ClinicCore\Bootstrap\App;
    use ClinicCore\Infrastructure\Audit\AuditLogger;
    use ClinicCore\Infrastructure\Db\CpmsDb;
    use ClinicCore\Infrastructure\Logging\OpLogger;
    use ClinicCore\Infrastructure\Repository\InvoiceRepository;
    use ClinicCore\Infrastructure\Repository\PatientRepository;
    use ClinicCore\Infrastructure\Repository\PaymentRepository;
    use ClinicCore\Infrastructure\Repository\ServiceRepository;
    use ClinicCore\Infrastructure\Repository\VisitRepository;
    use ClinicCore\Application\Finance\VisitPricingService;
    use ClinicCore\Application\Visits\VisitService;
    use PHPUnit\Framework\TestCase;

    final class RefundLostUpdateTest extends TestCase
    {
        private const ACTOR = 7;
        private const PAYMENT_ID = 5;
        private const INVOICE_ID = 9;
        private const CAPTURED = 100;

        private \wpdb $fake;
        private FinanceService $service;

        protected function setUp(): void
        {
            parent::setUp();
            $this->fake = new \wpdb();
            $now = gmdate('Y-m-d H:i:s') . '.000';
            $this->fake->tables = [
                'wp_cpms_clinic_memberships' => [
                    ['id' => 1, 'clinic_id' => 1, 'wp_user_id' => self::ACTOR, 'status' => 'active', 'role_key' => 'cpms_doctor'],
                ],
                'wp_cpms_membership_capabilities' => [],
                'wp_cpms_payments' => [
                    [
                        'id' => self::PAYMENT_ID, 'clinic_id' => 1, 'invoice_id' => self::INVOICE_ID,
                        'payment_number' => 'PAY-260106-0001', 'amount' => (float) self::CAPTURED,
                        'method' => 'cash', 'transaction_ref' => null, 'status' => 'captured',
                        'refunded_amount' => 0.0, 'paid_at' => $now, 'void_reason' => null,
                        'voided_at' => null, 'voided_by_wp_user_id' => null, 'patient_id' => 3,
                        'idempotency_key' => 'k1', 'created_at' => $now,
                    ],
                ],
                'wp_cpms_invoices' => [
                    [
                        'id' => self::INVOICE_ID, 'clinic_id' => 1, 'visit_id' => 2, 'patient_id' => 3,
                        'location_id' => null, 'status' => 'paid', 'subtotal' => (float) self::CAPTURED,
                        'discount' => 0.0, 'tax' => 0.0, 'total' => (float) self::CAPTURED,
                        'paid_amount' => (float) self::CAPTURED, 'balance' => 0.0, 'currency' => 'IRR',
                        'invoice_number' => 'INV-260106-0001', 'void_reason' => null, 'created_at' => $now,
                    ],
                ],
                'wp_cpms_invoice_items' => [],
                'wp_cpms_payment_adjustments' => [],
                'wp_cpms_audit_seq' => [['id' => 1, 'last_audit_id' => 0, 'updated_at' => $now]],
                'wp_cpms_audit_logs' => [],
                'wp_cpms_operational_logs' => [],
                '__owning_clinician' => self::ACTOR,
            ];
            // tables entry '__owning_clinician' holds a scalar, not rows — keep it out of row scans.
            $this->fake->tables['__owning_clinician'] = self::ACTOR;

            global $wpdb;
            $wpdb = $this->fake;

            $db = new CpmsDb($this->fake);
            $reset = new \ReflectionProperty(App::class, 'db');
            $reset->setValue(null, null);

            ScopeContext::set(ClinicScope::forClinic(1));

            $dummy = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $this->service = new FinanceService(
                $db,
                $dummy(ServiceRepository::class),
                new InvoiceRepository($db),
                new PaymentRepository($db),
                $dummy(VisitRepository::class),
                $dummy(VisitService::class),
                $dummy(PatientRepository::class),
                new AuditLogger($db, new OpLogger($db)),
                $dummy(VisitPricingService::class)
            );
        }

        protected function tearDown(): void
        {
            ScopeContext::clear();
            $this->fake->hook = null;
            parent::tearDown();
        }

        private function refundedAmount(): float
        {
            return (float) $this->fake->tables['wp_cpms_payments'][0]['refunded_amount'];
        }

        private function invoicePaid(): float
        {
            return (float) $this->fake->tables['wp_cpms_invoices'][0]['paid_amount'];
        }

        public function testPositiveControlSinglePartialThenFullRefund(): void
        {
            $r1 = $this->service->refundPayment(self::ACTOR, self::PAYMENT_ID, 'r1', ['amount' => 40]);
            $this->assertSame(40.0, (float) $r1['payment']['refunded_amount']);
            $this->assertSame('captured', $r1['payment']['status']);
            $this->assertSame(40.0, $this->refundedAmount());

            $r2 = $this->service->refundPayment(self::ACTOR, self::PAYMENT_ID, 'r2', ['amount' => 60]);
            $this->assertSame(100.0, (float) $r2['payment']['refunded_amount']);
            $this->assertSame('refunded', $r2['payment']['status']);
            $this->assertSame(100.0, $this->refundedAmount());
            $this->assertSame(0.0, $this->invoicePaid());
        }

        public function testRefundAboveRemainingAmountRejectedWithNoStateChange(): void
        {
            $this->service->refundPayment(self::ACTOR, self::PAYMENT_ID, 'r1', ['amount' => 60]);
            $this->assertSame(60.0, $this->refundedAmount());

            try {
                $this->service->refundPayment(self::ACTOR, self::PAYMENT_ID, 'r2', ['amount' => 50]);
                $this->fail('refund above remaining amount must be rejected');
            } catch (FinanceException $e) {
                $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
                $this->assertSame(422, $e->httpStatus);
            }
            $this->assertSame(60.0, $this->refundedAmount(), 'no state change after rejected refund');
            $this->assertSame(40.0, $this->invoicePaid(), 'invoice must stay unchanged after rejected refund');
        }

        /**
         * What this actually proves: a second refund of the same amount is refused
         * because it no longer fits under the capacity ceiling (60 + 60 > 100), not
         * because the request is recognised as a retry. There is no refund
         * idempotency key, so a retry that still fits WOULD be applied twice.
         * See the debts appended to docs/agent-log.md for this PR.
         */
        public function testRepeatRefundExceedingRemainingCapacityIsRejectedNotRecognisedAsRetry(): void
        {
            $this->service->refundPayment(self::ACTOR, self::PAYMENT_ID, 'r1', ['amount' => 60]);
            try {
                $this->service->refundPayment(self::ACTOR, self::PAYMENT_ID, 'r1-retry', ['amount' => 60]);
                $this->fail('a second refund of 60 on a 100 payment must be rejected');
            } catch (FinanceException $e) {
                $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            }
            $this->assertSame(60.0, $this->refundedAmount());
            $this->assertSame(40.0, $this->invoicePaid());
        }

        public function testRefundOfZeroOrNegativeAmountIsRejectedWithNoStateChange(): void
        {
            foreach ([0, -1, -50] as $bad) {
                try {
                    $this->service->refundPayment(self::ACTOR, self::PAYMENT_ID, 'bad', ['amount' => $bad]);
                    $this->fail('refund amount <= 0 must be rejected: ' . $bad);
                } catch (FinanceException $e) {
                    $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
                    $this->assertSame(422, $e->httpStatus);
                }
                $this->assertSame(0.0, $this->refundedAmount(), 'no state change after rejected amount ' . $bad);
                $this->assertSame(100.0, $this->invoicePaid(), 'invoice unchanged after rejected amount ' . $bad);
            }
            $this->assertSame('captured', $this->fake->tables['wp_cpms_payments'][0]['status']);
        }

        public function testRefundOnNonCapturedPaymentIsRejectedWith409AndNothingChanged(): void
        {
            $this->fake->tables['wp_cpms_payments'][0]['status'] = 'voided';

            try {
                $this->service->refundPayment(self::ACTOR, self::PAYMENT_ID, 'r1', ['amount' => 10]);
                $this->fail('refund on a non-captured payment must be rejected');
            } catch (FinanceException $e) {
                $this->assertSame('CLINIC_INVALID_TRANSITION', $e->errorCode);
                $this->assertSame(409, $e->httpStatus);
                $this->assertSame('voided', $e->data['status'] ?? null);
            }
            $this->assertSame(0.0, $this->refundedAmount(), 'no refund recorded');
            $this->assertSame('voided', $this->fake->tables['wp_cpms_payments'][0]['status'], 'payment status unchanged');
            $this->assertSame(100.0, $this->invoicePaid(), 'invoice unchanged');
            $this->assertCount(0, $this->fake->tables['wp_cpms_audit_logs'] ?? [], 'no audit row written');
        }

        /**
         * Deterministic interleaving: refund A (60) and refund B (70) on a payment
         * captured at 100. Both perform their pre-transaction read before either
         * writes (simulated by running B to completion while A holds the row lock).
         * Correct behavior: the second writer re-validates under the lock, so one
         * refund is rejected and refunded_amount never exceeds captured.
         */
        public function testInterleavedConcurrentRefundsDoNotLoseUpdate(): void
        {
            $armed = true;
            $service = $this->service;
            $this->fake->hook = static function (string $sql) use (&$armed, $service): void {
                if ($armed
                    && str_contains($sql, 'SELECT * FROM wp_cpms_payments')
                    && str_contains($sql, 'FOR UPDATE')) {
                    $armed = false;
                    // Refund B runs to completion at the exact point where refund A
                    // holds the lock but has not written yet; both already did their
                    // pre-transaction reads, so B's write lands first.
                    $service->refundPayment(self::ACTOR, self::PAYMENT_ID, 'refund B', ['amount' => 70]);
                }
            };

            try {
                $this->service->refundPayment(self::ACTOR, self::PAYMENT_ID, 'refund A', ['amount' => 60]);
                $this->fail('with 70 already refunded under the lock, refunding another 60 of 100 must be rejected');
            } catch (FinanceException $e) {
                $this->assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
                $this->assertSame(422, $e->httpStatus);
            }

            $this->assertSame(70.0, $this->refundedAmount(), 'exactly one interleaved refund may be applied');
            $this->assertLessThanOrEqual((float) self::CAPTURED, $this->refundedAmount(), 'refunded must never exceed captured');
            $this->assertSame(30.0, $this->invoicePaid());
        }
    }
}
