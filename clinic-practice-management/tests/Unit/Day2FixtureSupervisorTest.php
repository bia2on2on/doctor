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
 * the environment, exactly as in production. No assertion in this file receives a supervisor-returned value,
 * captured process output, or anything containing them as an
 * operand: every such comparison is computed internally and
 * asserted as a boolean with a FIXED message, so a PHPUnit failure
 * export cannot republish child output. (Round 8 used assertSame
 * on the returned line; round 10 removed that too.)
 *
 * Fatal coverage note: the fatal test below uses CPU-limit
 * exhaustion. It is NOT a memory-exhaustion proof: on PHP 8.1 the
 * engine prints its own memory-fatal line despite suppression
 * (observed in CI, rounds 8/10), which this PR does not close —
 * that needs the future workflow-authorized whole-process boundary.
 * (An earlier version of this file described the replaced trigger
 * as memory-exhaustion coverage; that wording was wrong.)
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
        self::assertTrue($code === 0, 'supervised success must exit 0');
        self::assertTrue($line === 'DAY2_FIXTURE_OK status=ok', 'supervised success must publish the fixed OK line');
        self::assertTrue($toStderr === false, 'supervised success must publish to stdout');
        self::assertFileExists($this->envFile);
    }

    public function test_exit_zero_without_marker_fails(): void
    {
        [$code, $line] = $this->supervise($this->phpChild(
            'file_put_contents(getenv("DAY2_FIXTURE_ENV_PATH"), "DAY2_OK=1\n");'
        ));
        self::assertTrue($code === 1, 'exit 0 without completion evidence must fail');
        self::assertTrue($line === 'DAY2_FIXTURE_ERROR step=supervisor', 'exit 0 without completion evidence must publish the supervisor token');
    }

    public function test_empty_output_and_nonzero_exit_fails_as_unknown(): void
    {
        [$code, $line] = $this->supervise($this->phpChild('exit(4);'));
        self::assertTrue($code === 4, 'child exit status must be preserved');
        self::assertTrue($line === 'DAY2_FIXTURE_ERROR step=unknown', 'empty failing output must publish the unknown token');
    }

    public function test_marker_with_nonzero_exit_fails_and_preserves_exit(): void
    {
        [$code, $line] = $this->supervise($this->phpChild($this->markerCode() . 'exit(3);'));
        self::assertTrue($code === 3, 'child exit status must be preserved');
        self::assertTrue($line !== 'DAY2_FIXTURE_OK status=ok', 'marker with nonzero exit must not publish success');
    }

    public function test_forged_nonce_marker_fails(): void
    {
        [$code, $line] = $this->supervise($this->phpChild(
            'fwrite(STDOUT, "DAY2_FIXTURE_COMPLETE nonce=forgedforgedforgedforged00\n");'
            . 'file_put_contents(getenv("DAY2_FIXTURE_ENV_PATH"), "DAY2_OK=1\n");'
        ));
        self::assertTrue($code === 1, 'forged completion must fail');
        self::assertTrue($line === 'DAY2_FIXTURE_ERROR step=supervisor', 'forged completion must publish the supervisor token');
    }

    public function test_duplicate_marker_fails(): void
    {
        [$code, $line] = $this->supervise($this->phpChild($this->markerCode() . $this->markerCode()));
        self::assertTrue($code === 1, 'duplicate completion must fail');
        self::assertTrue($line === 'DAY2_FIXTURE_ERROR step=supervisor', 'duplicate completion must publish the supervisor token');
    }

    public function test_child_error_token_and_exit_are_preserved_without_leak(): void
    {
        [$code, $line] = $this->supervise($this->phpChild(
            'fwrite(STDERR, "RAW SYNTH_TOKEN_9f3 driver text\n");'
            . 'fwrite(STDERR, "DAY2_FIXTURE_ERROR step=seed_verify\n"); exit(7);'
        ));
        self::assertTrue($code === 7, 'child exit status must be preserved');
        self::assertTrue($line === 'DAY2_FIXTURE_ERROR step=seed_verify', 'allowlisted child token must be republished exactly');
        self::assertTrue(!str_contains($line, 'SYNTH_TOKEN_9f3'), 'fixed line must not carry child output');
    }

    public function test_child_killed_by_signal_fails(): void
    {
        if (!file_exists('/bin/sh')) {
            self::markTestSkipped('/bin/sh unavailable for the signal case');
        }
        [$code, $line] = $this->supervise(['/bin/sh', '-c', 'kill -9 $$']);
        self::assertTrue($code !== 0, 'signalled child must fail');
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
        self::assertTrue($code !== 0, 'unlaunchable child must fail');
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
        self::assertTrue($code === 1, 'output after the completion line must fail');
        self::assertTrue($line === 'DAY2_FIXTURE_ERROR step=supervisor', 'output after the completion line must publish the supervisor token');
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
        self::assertTrue($code === 1, 'cleanup failure must fail closed');
        self::assertTrue($line === 'DAY2_FIXTURE_ERROR step=supervisor', 'cleanup failure must publish the supervisor token');
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
        // Drive d2w_supervise() in a subprocess with a 1-second CPU
        // limit; the fake child emits tens of millions of tiny lines,
        // so scanning them exhausts the CPU limit (E_ERROR) AFTER the
        // supervisor installed display/log suppression. The shutdown
        // function must answer with only the fixed token and clean
        // its private dir. (A memory-exhaustion trigger was tried
        // first and abandoned: on PHP 8.1 the engine prints its own
        // memory-fatal line to stderr despite display/log suppression
        // and the shutdown token does not land — an engine diagnostic
        // outside this boundary, observed in CI; CPU exhaustion does
        // not have that behaviour.)
        $base = $this->workDir . '/fatalbase';
        mkdir($base, 0700, true);
        $helper = $this->workDir . '/fatal-helper.php';
        file_put_contents($helper, '<?php set_time_limit(1); require ' . var_export(dirname(__DIR__) . '/fixtures/day2-fixture-supervisor.php', true) . ';'
            . '$r = d2w_supervise([PHP_BINARY, "-r", ' . var_export('for ($i = 0; $i < 30000000; $i++) { echo "x\n"; }', true) . '], '
            . var_export($this->envFile, true) . ', ["supervisor"], ' . var_export($base, true) . ');'
            . 'fwrite(STDOUT, "UNREACHED\n");');
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', $helper],
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
        // Bounded diagnostic only (two integers, never content): a
        // mismatch reports the observed byte length and fixed-token
        // line count so a CI failure stays debuggable without
        // publishing any of the captured stderr.
        self::assertTrue($err === "DAY2_FIXTURE_ERROR step=supervisor\n", sprintf(
            'fatal path must print only the fixed token (observed length=%d, token lines=%d)',
            strlen((string) $err),
            substr_count((string) $err, 'DAY2_FIXTURE_ERROR step=supervisor')
        ));
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
        self::assertTrue($code === 5, 'child exit status must be preserved');
        self::assertTrue($line === 'DAY2_FIXTURE_ERROR step=seed_verify', 'allowlisted child token must be republished exactly');
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
        self::assertTrue($code === 1, 'empty command must fail closed');
        self::assertTrue($line === 'DAY2_FIXTURE_ERROR step=supervisor', 'empty command must publish the supervisor token');
    }

    public function test_unusable_temp_dir_fails_closed(): void
    {
        [$code, $line] = $this->supervise(
            $this->phpChild($this->markerCode()),
            $this->workDir . '/missing-parent/child'
        );
        self::assertTrue($code === 1, 'unusable temp dir must fail closed');
        self::assertTrue($line === 'DAY2_FIXTURE_ERROR step=supervisor', 'unusable temp dir must publish the supervisor token');
    }

    public function test_stale_env_file_is_removed_and_cannot_prove_success(): void
    {
        file_put_contents($this->envFile, "DAY2_STALE=1\n");
        [$code, $line] = $this->supervise($this->phpChild('exit(0);'));
        self::assertTrue($code === 1, 'exit 0 without completion evidence must fail');
        self::assertTrue($line === 'DAY2_FIXTURE_ERROR step=supervisor', 'stale env file must not prove success');
        self::assertFileDoesNotExist($this->envFile);
    }


    public function test_cleanup_failure_surviving_to_shutdown_prints_only_token(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('permission-based cleanup injection needs a non-root user');
        }
        // Subprocess: the fake child makes the supervisor's private
        // dir non-writable, so cleanup fails on the return path AND
        // again in the shutdown retry. The helper prints NOTHING
        // itself; the only stderr allowed is the fixed token (from
        // the shutdown path), the exit must be non-success, and no
        // PHP warning text may appear (exact-match boolean compare).
        $base = $this->workDir . '/shutdownbase';
        mkdir($base, 0700, true);
        $helper = $this->workDir . '/shutdown-helper.php';
        $sabotage = '$ds = glob(' . var_export($base, true) . ' . "/d2w-sup-*") ?: [];'
            . 'foreach ($ds as $d) { foreach (glob($d . "/*") ?: [] as $f) { chmod($f, 0400); } chmod($d, 0500); }';
        file_put_contents($helper, '<?php require ' . var_export(dirname(__DIR__) . '/fixtures/day2-fixture-supervisor.php', true) . ';'
            . '$r = d2w_supervise([PHP_BINARY, "-r", ' . var_export($sabotage . 'echo "done\n";', true) . '], '
            . var_export($this->envFile, true) . ', ["supervisor"], ' . var_export($base, true) . ');'
            . 'exit($r[0]);');
        $proc = proc_open(
            [PHP_BINARY, $helper],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertTrue(is_resource($proc), 'shutdown-path helper could not launch');
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        unlink($helper);
        self::assertTrue($exit !== 0, 'shutdown cleanup failure must exit non-success');
        self::assertTrue($out === '', 'shutdown cleanup failure must print nothing to stdout');
        self::assertTrue($err === "DAY2_FIXTURE_ERROR step=supervisor\n", 'shutdown cleanup failure must print only the fixed token (no warning text)');
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

    public function test_oversized_child_output_fails_closed_within_caps(): void
    {
        // One huge newline-less line (far beyond D2W_CAPTURE_LINE_MAX)
        // and, separately, many short lines beyond
        // D2W_CAPTURE_TOTAL_MAX: both must fail closed with the
        // fixed token, without the scan ever holding the output.
        [$code, $line] = $this->supervise($this->phpChild('echo str_repeat("A", 4194304);'));
        self::assertTrue($code !== 0, 'huge single line must fail');
        self::assertTrue($line === 'DAY2_FIXTURE_ERROR step=supervisor', 'huge single line must publish the supervisor token');
        [$code, $line] = $this->supervise($this->phpChild('for ($i = 0; $i < 60000; $i++) { echo "padding line\n"; }'));
        self::assertTrue($code !== 0, 'output beyond the total cap must fail');
        self::assertTrue($line === 'DAY2_FIXTURE_ERROR step=supervisor', 'output beyond the total cap must publish the supervisor token');
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
            self::assertTrue($exit !== 0, 'guard case must fail closed: ' . $label);
            // Boolean compare with a FIXED message: the captured
            // streams are never assertion operands, so a failure
            // export cannot republish child output.
            self::assertTrue(($out . $err) === "DAY2_FIXTURE_ERROR step=supervisor\n", 'guard case must print only the fixed token: ' . $label);
        }
    }
}
