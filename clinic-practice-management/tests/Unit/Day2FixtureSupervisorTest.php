<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Real-PHP contract tests for the Day-2 fixture output supervisor
 * (tests/fixtures/day2-fixture-supervisor.php).
 *
 * These run under the PHPUnit Unit suite on CI's real PHP CLI — the
 * environment that can actually `proc_open`. They were NOT run
 * locally during development (local PHP is a PHP-WASM build whose
 * proc_open shim is broken); CI is the proof for this file.
 *
 * Fake children are tiny `php -r` programs. The supervisor's nonce is
 * generated inside `d2w_supervise()` and reaches the child only via
 * the environment, exactly as in production. Wherever an assertion
 * examines captured process output or a value derived from it, the
 * comparison is done internally and asserted as a boolean with a
 * FIXED message — raw child output is never passed as an assertion
 * operand, so a PHPUnit failure export cannot republish it. (Plain
 * assertSame on the supervisor's own returned fixed line vs a fixed
 * literal is used only where that line is itself the contract.)
 */
final class Day2FixtureSupervisorTest extends TestCase
{
    private string $workDir;
    private string $envFile;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/fixtures/day2-fixture-supervisor.php';
        $this->workDir = sys_get_temp_dir() . '/d2w-sup-test-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0700, true);
        $this->envFile = $this->workDir . '/day2-workflows.env';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->workDir);
    }

    /** @param list<string> $command @return array{0:int,1:string,2:bool} */
    private function supervise(array $command, ?string $tempBase = null): array
    {
        return d2w_supervise($command, $this->envFile, ['insert', 'seed_verify', 'supervisor'], $tempBase);
    }

    /** @return list<string> */
    private function phpChild(string $code): array
    {
        return [PHP_BINARY, '-r', $code];
    }

    private function markerCode(): string
    {
        return 'fwrite(STDOUT, "DAY2_FIXTURE_COMPLETE nonce=" . getenv("DAY2_FIXTURE_NONCE") . "\n");'
            . 'file_put_contents(getenv("DAY2_FIXTURE_ENV_PATH"), "DAY2_OK=1\n");';
    }

    public function test_normal_success_requires_all_evidence(): void
    {
        [$code, $line, $toStderr] = $this->supervise($this->phpChild($this->markerCode()));
        self::assertSame(0, $code);
        self::assertSame('DAY2_FIXTURE_OK status=ok', $line);
        self::assertFalse($toStderr);
        self::assertFileExists($this->envFile);
    }

    public function test_exit_zero_without_marker_fails(): void
    {
        [$code, $line] = $this->supervise($this->phpChild(
            'file_put_contents(getenv("DAY2_FIXTURE_ENV_PATH"), "DAY2_OK=1\n");'
        ));
        self::assertSame(1, $code);
        self::assertSame('DAY2_FIXTURE_ERROR step=supervisor', $line);
    }

    public function test_empty_output_and_nonzero_exit_fails_as_unknown(): void
    {
        [$code, $line] = $this->supervise($this->phpChild('exit(4);'));
        self::assertSame(4, $code);
        self::assertSame('DAY2_FIXTURE_ERROR step=unknown', $line);
    }

    public function test_marker_with_nonzero_exit_fails_and_preserves_exit(): void
    {
        [$code, $line] = $this->supervise($this->phpChild($this->markerCode() . 'exit(3);'));
        self::assertSame(3, $code);
        self::assertNotSame('DAY2_FIXTURE_OK status=ok', $line);
    }

    public function test_forged_nonce_marker_fails(): void
    {
        [$code, $line] = $this->supervise($this->phpChild(
            'fwrite(STDOUT, "DAY2_FIXTURE_COMPLETE nonce=forgedforgedforgedforged00\n");'
            . 'file_put_contents(getenv("DAY2_FIXTURE_ENV_PATH"), "DAY2_OK=1\n");'
        ));
        self::assertSame(1, $code);
        self::assertSame('DAY2_FIXTURE_ERROR step=supervisor', $line);
    }

    public function test_duplicate_marker_fails(): void
    {
        [$code, $line] = $this->supervise($this->phpChild($this->markerCode() . $this->markerCode()));
        self::assertSame(1, $code);
        self::assertSame('DAY2_FIXTURE_ERROR step=supervisor', $line);
    }

    public function test_child_error_token_and_exit_are_preserved_without_leak(): void
    {
        [$code, $line] = $this->supervise($this->phpChild(
            'fwrite(STDERR, "RAW SYNTH_TOKEN_9f3 driver text\n");'
            . 'fwrite(STDERR, "DAY2_FIXTURE_ERROR step=seed_verify\n"); exit(7);'
        ));
        self::assertSame(7, $code);
        self::assertSame('DAY2_FIXTURE_ERROR step=seed_verify', $line);
        self::assertTrue(!str_contains($line, 'SYNTH_TOKEN_9f3'), 'fixed line must not carry child output');
    }

    public function test_child_killed_by_signal_fails(): void
    {
        if (!file_exists('/bin/sh')) {
            self::markTestSkipped('/bin/sh unavailable for the signal case');
        }
        [$code, $line] = $this->supervise(['/bin/sh', '-c', 'kill -9 $$']);
        self::assertNotSame(0, $code);
        self::assertTrue(
            in_array($line, ['DAY2_FIXTURE_ERROR step=supervisor', 'DAY2_FIXTURE_ERROR step=unknown'], true),
            'signalled child must yield a fixed error line'
        );
    }

    public function test_unlaunchable_child_fails_closed(): void
    {
        // A binary that does not exist: PHP >= 8.3 makes proc_open
        // fail (supervisor token, exit 1); PHP 8.1/8.2 launch a child
        // that exits 127 with no output (unknown token, exit kept).
        // Whole-line membership in the explicit two-token allowlist
        // is version-independent AND stricter than a prefix check:
        // no other token, suffix or extra text passes.
        [$code, $line] = $this->supervise(['/nonexistent/d2w-no-such-binary']);
        self::assertNotSame(0, $code);
        self::assertTrue(
            in_array($line, ['DAY2_FIXTURE_ERROR step=supervisor', 'DAY2_FIXTURE_ERROR step=unknown'], true),
            'unlaunchable child must yield an allowlisted fixed line'
        );
    }

    public function test_completion_marker_must_be_the_last_stdout_line(): void
    {
        [$code, $line] = $this->supervise($this->phpChild(
            $this->markerCode() . 'fwrite(STDOUT, "trailing junk\n");'
        ));
        self::assertSame(1, $code);
        self::assertSame('DAY2_FIXTURE_ERROR step=supervisor', $line);
    }

    public function test_cleanup_failure_after_success_fails_closed(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('permission-based cleanup injection needs a non-root user');
        }
        $base = $this->workDir . '/supbase';
        mkdir($base, 0700, true);
        // The fake child finds the supervisor's private dir (the test
        // embeds the base path) and makes it non-writable, so the
        // supervisor's checked cleanup cannot unlink its capture
        // files after an otherwise-successful run.
        $sabotage = '$ds = glob(' . var_export($base, true) . ' . "/d2w-sup-*") ?: [];'
            . 'foreach ($ds as $d) { foreach (glob($d . "/*") ?: [] as $f) { chmod($f, 0400); } chmod($d, 0500); }';
        [$code, $line] = $this->supervise(
            $this->phpChild($sabotage . $this->markerCode()),
            $base
        );
        self::assertSame(1, $code);
        self::assertSame('DAY2_FIXTURE_ERROR step=supervisor', $line);
        // Restore permissions and remove what the supervisor could
        // not; its shutdown registration also retries at process end.
        foreach (glob($base . '/d2w-sup-*') ?: [] as $d) {
            chmod($d, 0700);
            foreach (glob($d . '/*') ?: [] as $f) {
                chmod($f, 0600);
                unlink($f);
            }
            rmdir($d);
        }
        rmdir($base);
    }

    public function test_fatal_in_supervised_process_prints_only_fixed_token(): void
    {
        // Drive d2w_supervise() in a subprocess with a tiny memory
        // limit; the fake child emits one huge line, so scanning it
        // exhausts memory (E_ERROR) AFTER the supervisor installed
        // display/log suppression. The shutdown function must answer
        // with only the fixed token and clean its private dir.
        $base = $this->workDir . '/fatalbase';
        mkdir($base, 0700, true);
        $helper = $this->workDir . '/fatal-helper.php';
        file_put_contents($helper, '<?php require ' . var_export(dirname(__DIR__) . '/fixtures/day2-fixture-supervisor.php', true) . ';'
            . '$r = d2w_supervise([PHP_BINARY, "-r", "echo str_repeat(\"A\", 67108864);"], '
            . var_export($this->envFile, true) . ', ["supervisor"], ' . var_export($base, true) . ');'
            . 'fwrite(STDOUT, "UNREACHED\n");');
        $proc = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=20M', $helper],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertTrue(is_resource($proc), 'fatal-path helper could not launch');
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        unlink($helper);
        self::assertTrue($exit !== 0, 'fatal-path helper must exit nonzero');
        self::assertTrue($out === '', 'fatal path must print nothing to stdout');
        self::assertTrue($err === "DAY2_FIXTURE_ERROR step=supervisor\n", 'fatal path must print only the fixed token');
        self::assertTrue((glob($base . '/d2w-sup-*') ?: []) === [], 'shutdown cleanup must remove the private dir');
        rmdir($base);
    }

    public function test_failure_messages_never_contain_child_output(): void
    {
        // Regression for the assertion style itself: drive a failing
        // run whose child output carries a synthetic secret, then run
        // the boolean-with-fixed-message assertion this file uses and
        // prove the resulting PHPUnit failure message stays clean.
        $secret = 'SYNTH_SECRET_R8_4c2f';
        [$code, $line] = $this->supervise($this->phpChild(
            'fwrite(STDERR, "RAW ' . $secret . ' driver text\n");'
            . 'fwrite(STDERR, "DAY2_FIXTURE_ERROR step=seed_verify\n"); exit(5);'
        ));
        self::assertSame(5, $code);
        self::assertSame('DAY2_FIXTURE_ERROR step=seed_verify', $line);
        $message = null;
        try {
            self::assertTrue($line === 'DAY2_FIXTURE_OK status=ok', 'supervisor contract mismatch (content withheld)');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $message = $e->getMessage();
        }
        self::assertTrue($message !== null, 'boolean assertion must have failed');
        self::assertTrue(!str_contains((string) $message, $secret), 'failure message must not contain child output');
    }

    public function test_empty_command_fails_closed(): void
    {
        [$code, $line] = $this->supervise([]);
        self::assertSame(1, $code);
        self::assertSame('DAY2_FIXTURE_ERROR step=supervisor', $line);
    }

    public function test_unusable_temp_dir_fails_closed(): void
    {
        [$code, $line] = $this->supervise(
            $this->phpChild($this->markerCode()),
            $this->workDir . '/missing-parent/child'
        );
        self::assertSame(1, $code);
        self::assertSame('DAY2_FIXTURE_ERROR step=supervisor', $line);
    }

    public function test_stale_env_file_is_removed_and_cannot_prove_success(): void
    {
        file_put_contents($this->envFile, "DAY2_STALE=1\n");
        [$code, $line] = $this->supervise($this->phpChild('exit(0);'));
        self::assertSame(1, $code);
        self::assertSame('DAY2_FIXTURE_ERROR step=supervisor', $line);
        self::assertFileDoesNotExist($this->envFile);
    }

    public function test_child_guard_requires_both_markers(): void
    {
        $fixture = dirname(__DIR__) . '/fixtures/pilot-day2-workflows-fixture.php';
        $cases = [
            'arg only' => [[PHP_BINARY, $fixture, '--d2w-supervised-child'], []],
            'env only' => [[PHP_BINARY, $fixture], ['DAY2_FIXTURE_SUPERVISED' => '1', 'DAY2_FIXTURE_NONCE' => str_repeat('a', 32)]],
            'nonce only' => [[PHP_BINARY, $fixture], ['DAY2_FIXTURE_NONCE' => str_repeat('b', 32)]],
        ];
        foreach ($cases as $label => [$command, $extraEnv]) {
            $env = array_merge(getenv() ?: [], $extraEnv);
            unset($env['WP_HOME']);
            if ($label !== 'env only') {
                unset($env['DAY2_FIXTURE_SUPERVISED']);
            }
            if ($label === 'arg only') {
                unset($env['DAY2_FIXTURE_SUPERVISED'], $env['DAY2_FIXTURE_NONCE']);
            }
            $proc = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            self::assertIsResource($proc, 'guard case could not launch: ' . $label);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            self::assertNotSame(0, $exit, 'guard case must fail closed: ' . $label);
            // Boolean compare with a FIXED message: the captured
            // streams are never assertion operands, so a failure
            // export cannot republish child output.
            self::assertTrue(($out . $err) === "DAY2_FIXTURE_ERROR step=supervisor\n", 'guard case must print only the fixed token: ' . $label);
        }
    }
}
