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
 * the environment, exactly as in production. NO test failure message
 * includes child output: assertions compare the fixed result line
 * against fixed literals, and leak checks use a synthetic token with
 * a static failure message.
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
        self::assertStringNotContainsString('SYNTH_TOKEN_9f3', $line, 'fixed line must not carry child output');
    }

    public function test_child_killed_by_signal_fails(): void
    {
        if (!file_exists('/bin/sh')) {
            self::markTestSkipped('/bin/sh unavailable for the signal case');
        }
        [$code, $line] = $this->supervise(['/bin/sh', '-c', 'kill -9 $$']);
        self::assertNotSame(0, $code);
        self::assertStringStartsWith('DAY2_FIXTURE_ERROR step=', $line);
    }

    public function test_unlaunchable_child_fails_closed(): void
    {
        // A binary that does not exist: PHP >= 8.3 makes proc_open
        // fail (supervisor token, exit 1); PHP 8.1/8.2 launch a child
        // that exits 127 with no output (unknown token, exit kept).
        // Both are fail-closed with a fixed line — assert exactly
        // that version-independent contract.
        [$code, $line] = $this->supervise(['/nonexistent/d2w-no-such-binary']);
        self::assertNotSame(0, $code);
        self::assertStringStartsWith('DAY2_FIXTURE_ERROR step=', $line);
        self::assertNotSame('DAY2_FIXTURE_OK status=ok', $line);
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
            self::assertSame("DAY2_FIXTURE_ERROR step=supervisor\n", $out . $err, 'guard case must print only the fixed token: ' . $label);
        }
    }
}
