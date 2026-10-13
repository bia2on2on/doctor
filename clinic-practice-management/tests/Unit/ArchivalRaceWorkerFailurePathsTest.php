<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Tests\Support\ArchivalRaceWorker;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/ArchivalRaceWorker.php';

/**
 * Failure paths, descriptor isolation and error privacy of the independent
 * archival-race worker launcher.
 *
 * The archival race suite depends on a worker process that must never be able to
 * touch the parent's MySQL session, and whose output must never carry text this
 * process did not choose. Both properties are only trustworthy if they are
 * exercised: every test here drives the launcher with inputs that cannot reach a
 * database at all, so nothing runs against the shared suite database.
 */
final class ArchivalRaceWorkerFailurePathsTest extends TestCase
{
    private const MISSING_CONFIG = '/nonexistent-cpms-fixture/wp-tests-config.php';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('proc_open') || !ArchivalRaceWorker::available()) {
            self::markTestSkipped('proc_open is required to launch the independent worker');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        $this->temporaryFiles = [];
        parent::tearDown();
    }

    /**
     * A worker whose WordPress configuration is missing must report a precise,
     * sanitized protocol error and exit non-zero — never a silent or empty
     * success, and never free text.
     */
    public function testWorkerBootstrapFailureIsReportedAndExitsNonZero(): void
    {
        $worker = ArchivalRaceWorker::launch($this->task([
            'WP_TESTS_CONFIG_FILE_PATH' => self::MISSING_CONFIG,
        ]));

        self::assertGreaterThan(0, $worker->pid(), 'the worker must be a real process');
        self::assertNotSame(getmypid(), $worker->pid(), 'the worker must never be this process');

        $line = $worker->nextLine(20.0);
        self::assertIsString($line, 'a failing worker must still speak the protocol: ' . $worker->diagnostics());
        self::assertSame('ERROR:CONFIG_NOT_FOUND', $line, 'failures carry a fixed code, not a message');

        self::assertSame(1, $worker->waitForExit(20.0), 'a failed bootstrap must exit non-zero');
        self::assertSame('CONFIG_NOT_FOUND', $worker->outcome(), 'the failure status must be preserved');
        $worker->terminate();
    }

    /**
     * A configuration that exists but defines no WordPress core location must be
     * rejected as well: the worker may never fall back to guessing a bootstrap.
     */
    public function testWorkerRejectsConfigurationWithoutCore(): void
    {
        $config = $this->temporaryFile("<?php\n// deliberately empty configuration\n");

        $worker = ArchivalRaceWorker::launch($this->task([
            'WP_TESTS_CONFIG_FILE_PATH' => $config,
            'WP_CORE_DIR' => '/nonexistent-cpms-core',
        ]));

        $line = $worker->nextLine(20.0);
        self::assertIsString($line, 'expected a protocol error: ' . $worker->diagnostics());
        self::assertSame('ERROR:CORE_UNAVAILABLE', $line);
        self::assertSame(1, $worker->waitForExit(20.0));
        $worker->terminate();
    }

    /**
     * Unexpected, caller-controlled error text must not travel: the protocol
     * carries a code and an opaque digest reference derived from the message
     * *length*, never from the message itself.
     */
    public function testUnexpectedErrorTextIsSanitizedToACodeAndReference(): void
    {
        $canary = 'cpms-secret-canary-' . bin2hex(random_bytes(6));
        $config = $this->temporaryFile("<?php\nthrow new \\RuntimeException('" . $canary . "');\n");

        $worker = ArchivalRaceWorker::launch($this->task([
            'WP_TESTS_CONFIG_FILE_PATH' => $config,
            'WP_CORE_DIR' => '/nonexistent-cpms-core',
        ]));

        $line = $worker->nextLine(20.0);
        self::assertIsString($line, 'expected a sanitized failure line: ' . $worker->diagnostics());
        self::assertMatchesRegularExpression(
            '/^ERROR:BOOTSTRAP_FAILED:ref=[0-9a-f]{8}$/',
            $line,
            'the failure line must be a code plus an opaque reference'
        );
        self::assertStringNotContainsString(
            $canary,
            $line . $worker->diagnostics() . json_encode($worker->descriptorIsolationProbe())
        );
        self::assertStringNotContainsString($canary, (string) $worker->outcome());
        self::assertSame(1, $worker->waitForExit(20.0));
        $worker->terminate();
    }

    /**
     * The worker must begin with stdio and inert /dev/null descriptors only: no
     * inherited socket can exist, so no parent-owned MySQL session is reachable
     * through a descriptor.
     */
    public function testWorkerDescriptorInventoryIsStdioAndDevNullOnly(): void
    {
        // Hold a real socket in this process first. A socket beyond stdio is
        // exactly the shape the isolation exists for (a parent-owned MySQL
        // session socket), so this proves the mechanism in this suite instead of
        // only where a database happens to be open.
        self::assertTrue(function_exists('stream_socket_pair'), 'the isolation proof needs a real socket');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair, 'the isolation proof needs a live socket pair');

        try {
            $worker = ArchivalRaceWorker::launch($this->task([
                'WP_TESTS_CONFIG_FILE_PATH' => self::MISSING_CONFIG,
            ]));

            self::assertIsString($worker->nextLine(20.0), 'protocol: ' . $worker->diagnostics());
            $probe = $worker->descriptorIsolationProbe();

            self::assertTrue($probe['proc_fd'], 'this proof requires /proc');
            self::assertGreaterThan(
                0,
                $probe['pinned_fd_count'],
                'this process holds a socket, so the launcher must have descriptors to pin'
            );
            self::assertTrue($probe['pinned_isolation_proven'], json_encode($probe));
            self::assertSame(0, $probe['worker_socket_count'], 'no inherited socket may reach the worker: ' . json_encode($probe));
            foreach ($probe['worker_fds_beyond_stdio'] as $fd => $class) {
                self::assertSame('dev-null', $class, 'descriptor ' . $fd . ' is not inert: ' . json_encode($probe));
            }
            self::assertGreaterThan(0, $probe['parent_observed_fd_count'], 'the parent side must be observed too');
            $worker->terminate();
        } finally {
            fclose($pair[0]);
            fclose($pair[1]);
        }
    }

    /**
     * The child's environment is exactly the allowlist. Names the launcher holds
     * but does not allowlist — pinned to unique markers before launch — must not
     * appear, and no marker value may appear in any worker output.
     */
    public function testWorkerEnvironmentIsAllowlistedAndMarkerFree(): void
    {
        $canary = 'cpms-env-canary-' . bin2hex(random_bytes(6));
        @putenv('CPMS_UNRELATED_SECRET=' . $canary);
        $_ENV['CPMS_UNRELATED_SECRET'] = $canary;
        $pins = ArchivalRaceWorker::pinProcessEnvironment(array_values(array_diff(
            ArchivalRaceWorker::PRIVACY_MARKER_VARS,
            ['CPMS_UNRELATED_SECRET'] // this one keeps the canary: it must be live in this process at launch
        )));
        self::assertNotSame('', (string) getenv('CPMS_UNRELATED_SECRET'), 'the canary must be present in this process when the worker starts');

        try {
            $worker = ArchivalRaceWorker::launch($this->task([
                'WP_TESTS_CONFIG_FILE_PATH' => self::MISSING_CONFIG,
            ]));
            self::assertIsString($worker->nextLine(20.0), 'protocol: ' . $worker->diagnostics());
            $probe = $worker->descriptorIsolationProbe();

            foreach (array_merge(array_keys($pins), ['CPMS_UNRELATED_SECRET']) as $name) {
                self::assertNotContains($name, $probe['worker_env_keys'], 'name reached the worker: ' . $name);
            }
            self::assertNotEmpty($probe['launch_environment_keys'], 'the launcher must have sent a non-empty environment');
            self::assertSame(
                $probe['launch_environment_keys'],
                $probe['worker_env_keys'],
                'the child sees exactly what the launcher sent through the allowlist, and nothing else'
            );
            foreach ($probe['worker_env_keys'] as $name) {
                self::assertContains($name, ArchivalRaceWorker::ENVIRONMENT_ALLOWLIST, 'a non-allowlisted name reached the worker: ' . $name);
            }
            self::assertStringNotContainsString($canary, $worker->diagnostics());
            self::assertStringNotContainsString($canary, json_encode($probe));
            $worker->terminate();
        } finally {
            ArchivalRaceWorker::restoreProcessEnvironment($pins);
        }
    }

    /**
     * Termination is bounded and reaps the process: no orphaned worker may
     * outlive a failing test.
     */
    public function testTerminationIsBoundedAndLeavesNoRunningProcess(): void
    {
        $worker = ArchivalRaceWorker::launch($this->task([
            'WP_TESTS_CONFIG_FILE_PATH' => self::MISSING_CONFIG,
        ]));

        $started = microtime(true);
        $worker->terminate();
        self::assertLessThan(10.0, microtime(true) - $started, 'termination must be bounded');
        self::assertFalse($worker->isRunning(), 'no worker process may outlive the test');
    }

    private function temporaryFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'cpms-race-config-');
        self::assertIsString($file);
        file_put_contents($file, $contents);
        $this->temporaryFiles[] = $file;

        return $file;
    }

    /**
     * @param array<string, string|int> $overrides
     *
     * @return array<string, string|int>
     */
    private function task(array $overrides): array
    {
        return array_merge([
            'WP_TESTS_DIR' => '/nonexistent-cpms-fixture',
            'CPMS_RACE_DB_NAME' => 'cpms_unit_failure_path_no_database',
            'CPMS_RACE_DB_USER' => 'cpms_unit_no_user',
            'CPMS_RACE_DB_PASSWORD' => 'cpms_unit_no_secret',
            'CPMS_RACE_DB_HOST' => '127.0.0.1',
            'CPMS_RACE_TABLE_PREFIX' => 'wptests_',
            'CPMS_RACE_CLINIC_ID' => 1,
            'CPMS_RACE_DEFINITION_ID' => 1,
            'CPMS_RACE_MANAGER_USER_ID' => 1,
            'CPMS_RACE_TARGET_USER_ID' => 1,
            'CPMS_RACE_ROLE_KEY' => 'unit_failure_path_key',
            'CPMS_RACE_OPERATION' => 'assign',
            'CPMS_RACE_LOCK_WAIT_TIMEOUT' => 1,
        ], $overrides);
    }
}
