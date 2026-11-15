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
 * Threat model for the nonce (stated precisely): it proves the
 * completion line was produced by a child that received THIS run's
 * environment — it protects against accidental, stale or unrelated
 * output being mistaken for completion. It does NOT protect against
 * a malicious or compromised child: the child can read its own
 * environment, so it could print a valid completion line without
 * doing the work. The fixture child is this repository's own code
 * re-executed, so the accidental-output threat is the one in scope.
 * The completion line is contractual ONLY as the LAST line of the
 * child's stdout: anything printed after it fails the run.
 *
 * This file only defines functions; including it executes nothing.
 * Residual (honest): loading THIS file, or a parse/startup error in
 * the including script, happens before any of this code can run; the
 * PHP engine's own diagnostic for that is outside this boundary and
 * its content is UNKNOWN to this code.
 */

declare(strict_types=1);

/**
 * Hard caps for captured child output (item C): a single captured
 * line may not exceed D2W_CAPTURE_LINE_MAX bytes and the captured
 * total may not exceed D2W_CAPTURE_TOTAL_MAX bytes. The total cap
 * is enforced WHILE the child runs (the wait loop polls capture
 * file sizes every 20 ms and terminates the child on excess) as
 * well as at scan time. That polling bounds growth IN PRACTICE —
 * by the polling interval multiplied by the child's write rate,
 * plus the bounded SIGTERM/SIGKILL escalation below — it is NOT a
 * hard byte guarantee, and descendant processes of the child are
 * NOT covered by the termination (stated LIMITATION, not tested).
 * Exceeding
 * either cap is a protocol failure (fixed token, non-success
 * exit). The child writes to FILES, not pipes, so output volume
 * can never block the child on a full pipe (no deadlock) and never
 * grows supervisor memory during the run; the scan itself holds at
 * most one capped line. Legitimate fixture output (fixed step
 * tokens + the completion line) is orders of magnitude below both
 * caps.
 *
 * Honest limit (observed in CI, round 8/10): a MEMORY-exhaustion
 * fatal inside this supervisor on PHP 8.1 makes the PHP engine print
 * its own fatal line to stderr despite display/log suppression, and
 * the shutdown token does not land. That engine behaviour is NOT
 * closed by this file; the two fatal tests in the Unit suite (a fatal
 * raised after d2w_supervise returns with cleanup pending, and a fatal
 * raised inside d2w_supervise — proven for process termination
 * inside supervise, an explicitly stated limitation there)
 * are NOT memory-fatal proofs. Closing the whole-process
 * boundary (including engine diagnostics) needs the future
 * workflow-authorized environment PR (private-file redirection of
 * the whole process, fixed tokens only, never cat the file).
 */
const D2W_CAPTURE_LINE_MAX = 8192;
const D2W_CAPTURE_TOTAL_MAX = 262144;

// Bounded graces for the runaway-child escalation (item C): grace
// for SIGTERM to take effect, then grace for SIGKILL to take
// effect. The escalation is bounded in practice on the normal
// path; SIGKILL failure or an uninterruptible child state is not
// covered (see the escalation block), and descendants of the child
// are not covered either.
const D2W_TERM_GRACE_MS = 2000;
const D2W_KILL_GRACE_MS = 2000;

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
    // Engine diagnostics (warnings, and the fatal message itself) must
    // not reach stderr either: display AND logging are off, and the
    // error handler below swallows routine diagnostics. A fatal is
    // answered by the shutdown function's fixed token — with one
    // documented exception this file does NOT close: a
    // MEMORY-exhaustion fatal on PHP 8.1, where the engine prints
    // its own fatal line despite this suppression (observed in CI;
    // see the D2W_CAPTURE_* note above).
    ini_set('log_errors', '0');
    set_error_handler(static function (): bool {
        return true;
    });
    $state = new class {
        public bool $finished = false;
        public ?string $tempDir = null;
    };
    // Checked private cleanup: every removal result is verified.
    // Returns false if anything could not be removed (the directory
    // then stays registered so the shutdown function retries).
    $cleanup = static function () use ($state): bool {
        if ($state->tempDir === null) {
            return true;
        }
        $ok = true;
        foreach (glob($state->tempDir . '/*') ?: [] as $file) {
            if (!unlink($file)) {
                $ok = false;
            }
        }
        if (!rmdir($state->tempDir)) {
            $ok = false;
        }
        if ($ok) {
            $state->tempDir = null;
        }
        return $ok;
    };
    register_shutdown_function(static function () use ($state, $cleanup): void {
        // Last resort. By shutdown time the caller's error handler
        // has been restored (every return path restores it after its
        // checked cleanup), so if that cleanup FAILED and the private
        // dir is still registered, an unguarded retry here would let
        // unlink/rmdir warning text escape to stderr. This function
        // therefore installs its OWN silent handler for the retry,
        // wraps it in Throwable handling, checks the result, and —
        // if cleanup still fails, or this process never finished —
        // emits ONLY the fixed token. (Non-success status: the fatal
        // path is already dying non-zero, and a failed return-path
        // cleanup already returned the fail-closed token + code 1;
        // the shutdown retry guarantees the token, it does not need
        // to invent an exit code.)
        set_error_handler(static function (): bool {
            return true;
        });
        $clean = true;
        try {
            $clean = $cleanup();
        } catch (Throwable) {
            $clean = false;
        }
        restore_error_handler();
        if ((!$clean || !$state->finished) && defined('STDERR')) {
            // Allocation-lean on purpose — after a memory-exhaustion
            // fatal, complex shutdown work may not complete — so the
            // fixed token is written directly.
            fwrite(STDERR, "DAY2_FIXTURE_ERROR step=supervisor\n");
        }
    });

    // Every return path funnels through here EXCEPT success: cleanup
    // runs FIRST, while the swallowing error handler is still active
    // (a cleanup warning must not reach stderr), and any cleanup
    // failure converts the result to the fail-closed supervisor token
    // with a non-success exit — captured private output is never left
    // behind silently. The temporary handler is restored only after
    // cleanup, immediately before returning.
    $fail = static function (int $code, string $token) use ($cleanup, $state): array {
        $clean = $cleanup();
        restore_error_handler();
        $state->finished = true;
        if (!$clean) {
            return [1, 'DAY2_FIXTURE_ERROR step=supervisor', true];
        }
        return [$code !== 0 ? $code : 1, 'DAY2_FIXTURE_ERROR step=' . $token, true];
    };

    try {
        $nonce = bin2hex(random_bytes(16));

        // Private temp directory (0700) and capture files (0600),
        // modes verified before the child starts; anything else fails
        // closed without launching the child.
        $base = $tempBase ?? sys_get_temp_dir();
        $dir = $base . '/d2w-sup-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700)) {
            return $fail(1, 'supervisor');
        }
        // Registered for cleanup IMMEDIATELY after mkdir succeeds —
        // before chmod/mode checks — so no later failure can orphan it.
        $state->tempDir = $dir;
        if (!chmod($dir, 0700)) {
            return $fail(1, 'supervisor');
        }
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

        // Wait loop with IN-EXECUTION cap enforcement: a flooding
        // child could otherwise grow the capture files for its whole
        // lifetime before the post-exit scan ever saw them. File
        // sizes are polled every 20 ms; exceeding the per-file bound
        // (the total cap, applied per file as well as combined)
        // terminates the child (SIGTERM, then SIGKILL after
        // D2W_TERM_GRACE_MS if it ignores SIGTERM) and fails closed.
        // This bounds growth in practice (poll interval x write
        // rate), not as a hard byte guarantee; descendants of the
        // child are not terminated (LIMITATION, not tested).
        // Poll-only: no output is read here, so nothing can deadlock.
        $capExceeded = false;
        $sigtermSent = false;
        $status = proc_get_status($proc);
        while (is_array($status) && ($status['running'] ?? false)) {
            $grown = 0;
            foreach ([$outPath, $errPath] as $capturePath) {
                clearstatcache(true, $capturePath);
                $size = filesize($capturePath);
                $size = is_int($size) ? $size : 0;
                if ($size > D2W_CAPTURE_TOTAL_MAX) {
                    $capExceeded = true;
                }
                $grown += $size;
            }
            if ($grown > D2W_CAPTURE_TOTAL_MAX) {
                $capExceeded = true;
            }
            if ($capExceeded) {
                $sigtermSent = proc_terminate($proc);
                break;
            }
            usleep(20000);
            $status = proc_get_status($proc);
        }
        if ($capExceeded) {
            // Bounded escalation, best-effort. SIGTERM was sent when
            // the cap fired (its return value was checked there: if
            // it could not be delivered there is no grace to wait
            // out). Poll for exit for up to D2W_TERM_GRACE_MS, then
            // SIGKILL, then poll for up to D2W_KILL_GRACE_MS. The
            // polls do NOT depend on a successful status lookup: a
            // false proc_get_status return is treated as "not
            // confirmed exited" and still proceeds to SIGKILL.
            $running = true;
            if ($sigtermSent) {
                $deadline = microtime(true) + (D2W_TERM_GRACE_MS / 1000.0);
                while ($running && microtime(true) < $deadline) {
                    $status = proc_get_status($proc);
                    $running = !is_array($status) || ($status['running'] ?? true);
                    if ($running) {
                        usleep(20000);
                    }
                }
            }
            $killFailed = false;
            if ($running) {
                $killFailed = !proc_terminate($proc, 9);
                $deadline = microtime(true) + (D2W_KILL_GRACE_MS / 1000.0);
                while (!$killFailed && $running && microtime(true) < $deadline) {
                    $status = proc_get_status($proc);
                    $running = !is_array($status) || ($status['running'] ?? true);
                    if ($running) {
                        usleep(20000);
                    }
                }
            }
            if ($killFailed || $running) {
                // Fail closed WITHOUT claiming a bound: the child
                // could not be confirmed dead (SIGKILL failed, or it
                // is in an uninterruptible state). proc_close is
                // deliberately not called here; the process resource
                // destructor may still wait for the child at
                // shutdown — whether it does is UNKNOWN; the
                // backstop is the job timeout. Residual stated
                // honestly; descendants are NOT covered either
                // (LIMITATION, not tested). Checked cleanup still
                // runs via $fail.
                return $fail(1, 'supervisor');
            }
        }
        $code = proc_close($proc);
        if ($capExceeded) {
            return $fail(1, 'supervisor');
        }
        if (!is_int($code) || $code < 0 || $code > 255) {
            // Includes termination by signal (proc_close reports -1).
            // (proc_get_status was polled above; when it has already
            // reaped the exit code, proc_close may report -1 for a
            // normally-exited child — the status array's exitcode is
            // only trusted when it is a real 0..255 value.)
            $statusCode = is_array($status) ? ($status['exitcode'] ?? -1) : -1;
            if (is_int($statusCode) && $statusCode >= 0 && $statusCode <= 255
                && is_array($status) && !($status['running'] ?? true)
                && ($status['signaled'] ?? true) === false) {
                $code = $statusCode;
                $signalled = false;
            } else {
                $code = 1;
                $signalled = true;
            }
        } else {
            $signalled = false;
        }

        // Scan captured output within the D2W_CAPTURE_* caps (the
        // wait loop above already enforced the total cap while the
        // child ran; this is the per-line/total enforcement on what
        // was captured): count completion lines (valid and forged
        // alike) and extract at most one allowlisted failure token.
        // Raw lines are never copied anywhere else. The child is
        // already finished and wrote to files, so an over-cap scan
        // simply fails closed — no pipe to drain, no deadlock,
        // memory bounded by the line cap.
        $completeValid = 0;
        $completeSeen = 0;
        $token = null;
        $lastOutLine = null;
        $scannedTotal = 0;
        foreach ([$errPath, $outPath] as $path) {
            $handle = fopen($path, 'r');
            if ($handle === false) {
                return $fail(1, 'supervisor');
            }
            while (($chunk = fgets($handle, D2W_CAPTURE_LINE_MAX + 1)) !== false) {
                $scannedTotal += strlen($chunk);
                if ($scannedTotal > D2W_CAPTURE_TOTAL_MAX
                    || (strlen($chunk) === D2W_CAPTURE_LINE_MAX && !str_ends_with($chunk, "\n"))) {
                    fclose($handle);
                    return $fail(1, 'supervisor');
                }
                $line = rtrim($chunk, "\r\n");
                if ($path === $outPath) {
                    $lastOutLine = $line;
                }
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
            && $lastOutLine === 'DAY2_FIXTURE_COMPLETE nonce=' . $nonce
            && is_file($envFile)) {
            // Success path: cleanup is checked too, while the handler
            // is still active; a cleanup failure fails closed.
            if (!$cleanup()) {
                restore_error_handler();
                $state->finished = true;
                return [1, 'DAY2_FIXTURE_ERROR step=supervisor', true];
            }
            restore_error_handler();
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
