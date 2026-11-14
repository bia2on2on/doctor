<?php
/**
 * Process-output supervisor for the Day-2 workflows fixture.
 *
 * The Pilot gate invokes the fixture through a public tee, so the
 * fixture re-executes itself under this supervisor: the child's
 * stdout/stderr are private files inside a private directory from the
 * moment the child starts (before its wp-load, before any query,
 * before any warning/fatal/uncaught exception can be produced). The
 * supervisor publishes exactly one fixed-schema line and nothing else:
 *
 *   success: `DAY2_FIXTURE_OK status=ok`            (stdout, exit 0)
 *   failure: `DAY2_FIXTURE_ERROR step=<token>`      (stderr, nonzero)
 *
 * Success requires ALL of: child exit status 0; exactly one completion
 * line `DAY2_FIXTURE_COMPLETE nonce=<nonce>` in the captured stdout,
 * where <nonce> is the per-run unpredictable value the supervisor
 * generated and passed to the child through the environment (never
 * argv); and the env file present after the run (any pre-existing env
 * file is removed before launch, so presence proves this run created
 * it). A worker that exits 0 without that evidence has NOT finished
 * the fixture, whatever it printed.
 *
 * This file only defines functions; including it executes nothing.
 * Residual (honest): loading THIS file, or a parse/startup error in
 * the including script, happens before any of this code can run; the
 * PHP engine's own diagnostic for that is outside this boundary and
 * its content is UNKNOWN to this code.
 */

declare(strict_types=1);

/**
 * Run $command supervised and return [exitCode, fixedLine, toStderr].
 *
 * The returned line is ALWAYS one of the two fixed schemas above; raw
 * child output is captured privately, used only for exact-token
 * extraction, and deleted. Nothing the child prints can appear in the
 * returned line beyond an allowlisted step token.
 *
 * @param list<string> $command      argv for the child (no shell).
 * @param string       $envFile      file the child must (re)create.
 * @param list<string> $allowedSteps allowlisted d2w_fail step tokens.
 * @param string|null  $tempBase     parent dir for the private temp dir.
 * @return array{0:int,1:string,2:bool}
 */
function d2w_supervise(array $command, string $envFile, array $allowedSteps, ?string $tempBase = null): array
{
    // FIRST: silence engine display and swallow routine diagnostics
    // before any fallible call, so no warning text from this
    // supervisor can reach the caller's stdout/stderr. A shutdown
    // function is the last-resort fixed-token path for fatals.
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    set_error_handler(static function (): bool {
        return true;
    });
    $state = new class {
        public bool $finished = false;
        public ?string $tempDir = null;
    };
    register_shutdown_function(static function () use ($state): void {
        if ($state->tempDir !== null) {
            foreach (glob($state->tempDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($state->tempDir);
            $state->tempDir = null;
        }
        if (!$state->finished) {
            $last = error_get_last();
            if (is_array($last) && in_array($last['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                fwrite(STDERR, "DAY2_FIXTURE_ERROR step=supervisor\n");
            }
        }
    });

    $cleanup = static function () use ($state): void {
        if ($state->tempDir !== null) {
            foreach (glob($state->tempDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($state->tempDir);
            $state->tempDir = null;
        }
    };
    $fail = static function (int $code, string $token) use ($cleanup, $state): array {
        restore_error_handler();
        $cleanup();
        $state->finished = true;
        return [$code !== 0 ? $code : 1, 'DAY2_FIXTURE_ERROR step=' . $token, true];
    };

    try {
        $nonce = bin2hex(random_bytes(16));

        // Private temp directory (0700) and capture files (0600),
        // modes verified before the child starts; anything else fails
        // closed without launching the child.
        $base = $tempBase ?? sys_get_temp_dir();
        $dir = $base . '/d2w-sup-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700) || !chmod($dir, 0700)) {
            return $fail(1, 'supervisor');
        }
        $state->tempDir = $dir;
        if (((int) fileperms($dir) & 0777) !== 0700) {
            return $fail(1, 'supervisor');
        }
        $outPath = $dir . '/stdout.txt';
        $errPath = $dir . '/stderr.txt';
        foreach ([$outPath, $errPath] as $path) {
            $handle = fopen($path, 'x');
            if ($handle === false) {
                return $fail(1, 'supervisor');
            }
            fclose($handle);
            if (!chmod($path, 0600) || (((int) fileperms($path)) & 0777) !== 0600) {
                return $fail(1, 'supervisor');
            }
        }

        // The env file must be created by THIS run: remove any stale
        // one first, and fail closed if that is impossible.
        if (file_exists($envFile) && !unlink($envFile)) {
            return $fail(1, 'supervisor');
        }

        $env = getenv() ?: [];
        $env['DAY2_FIXTURE_SUPERVISED'] = '1';
        $env['DAY2_FIXTURE_NONCE'] = $nonce;
        $env['DAY2_FIXTURE_ENV_PATH'] = $envFile;

        $proc = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $outPath, 'a'],
                2 => ['file', $errPath, 'a'],
            ],
            $pipes,
            null,
            $env
        );
        if (!is_resource($proc)) {
            return $fail(1, 'supervisor');
        }
        $code = proc_close($proc);
        if (!is_int($code) || $code < 0 || $code > 255) {
            // Includes termination by signal (proc_close reports -1).
            $code = 1;
            $signalled = true;
        } else {
            $signalled = false;
        }

        // Scan captured output: count completion lines (valid and
        // forged alike) and extract at most one allowlisted failure
        // token. Raw lines are never copied anywhere else.
        $completeValid = 0;
        $completeSeen = 0;
        $token = null;
        foreach ([$errPath, $outPath] as $path) {
            $handle = fopen($path, 'r');
            if ($handle === false) {
                return $fail(1, 'supervisor');
            }
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if (str_starts_with($line, 'DAY2_FIXTURE_COMPLETE')) {
                    $completeSeen++;
                    if ($line === 'DAY2_FIXTURE_COMPLETE nonce=' . $nonce) {
                        $completeValid++;
                    }
                }
                if ($token === null
                    && preg_match('/^DAY2_FIXTURE_ERROR step=([a-z_]+)$/', $line, $m)
                    && in_array($m[1], $allowedSteps, true)) {
                    $token = $m[1];
                }
            }
            fclose($handle);
        }

        if ($code === 0 && !$signalled && $completeValid === 1 && $completeSeen === 1
            && is_file($envFile)) {
            restore_error_handler();
            $cleanup();
            $state->finished = true;
            return [0, 'DAY2_FIXTURE_OK status=ok', false];
        }
        if ($code === 0) {
            // Exit 0 without complete evidence: protocol failure.
            return $fail(1, $token ?? 'supervisor');
        }
        return $fail($code, $token ?? 'unknown');
    } catch (Throwable) {
        return $fail(1, 'supervisor');
    }
}

/**
 * Whether this process was legitimately launched as the supervised
 * fixture child: BOTH the argv flag AND the supervised marker AND a
 * well-formed per-run nonce in the environment are required. Anything
 * less must fail closed before WordPress is loaded.
 */
function d2w_is_supervised_child(array $argv): bool
{
    return in_array('--d2w-supervised-child', $argv, true)
        && getenv('DAY2_FIXTURE_SUPERVISED') === '1'
        && preg_match('/^[a-f0-9]{32}$/', (string) getenv('DAY2_FIXTURE_NONCE')) === 1;
}
