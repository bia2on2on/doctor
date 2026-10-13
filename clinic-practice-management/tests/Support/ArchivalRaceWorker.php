<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

use RuntimeException;

/**
 * Launcher/handle for the independent archival-race waiter process.
 *
 * The worker is started with proc_open(): it inherits no live descriptor from
 * this process, so it can neither write to the parent's MySQL session nor
 * destroy it when it exits. Communication is bounded and line-oriented; every
 * protocol line is validated by the caller before it is trusted, and any other
 * output is kept as bounded, credential-redacted diagnostics.
 */
final class ArchivalRaceWorker
{
    private const PROTOCOL = ['READY:', 'LOCK_QUERY:', 'RESULT:', 'ERROR:'];

    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private string $stdoutBuffer = '';

    private string $diagnostics = '';

    private ?string $outcome = null;

    private ?int $exitCode = null;

    private bool $closed = false;

    private ?int $pid = null;

    /**
     * @param resource             $process
     * @param array<int, resource> $pipes
     */
    private function __construct($process, array $pipes)
    {
        $this->process = $process;
        $this->pipes   = $pipes;
    }

    public static function scriptPath(): string
    {
        return dirname(__DIR__) . '/bin/custom-role-archival-race-worker.php';
    }

    /**
     * Existence-only probe for the exact synthetic fixture rows a worker start
     * must never disturb: the target WordPress user, its Clinic membership and
     * the custom-role definition. Counts and a positive-key flag only — no row
     * values, no credentials, no identifiers beyond those the test already owns.
     *
     * @return array<string, int>
     */
    public static function fixtureProbe(
        \mysqli $conn,
        string $prefix,
        int $userId,
        int $clinicId,
        int $definitionId
    ): array {
        $probe = ['wp_user_rows' => -1, 'membership_rows' => -1, 'definition_rows' => -1];

        $checks = [
            'wp_user_rows' => ['SELECT COUNT(*) FROM `' . $prefix . 'users` WHERE `ID` = ?', 'i', $userId],
            'membership_rows' => ['SELECT COUNT(*) FROM `' . $prefix . 'cpms_clinic_memberships` WHERE `clinic_id` = ? AND `wp_user_id` = ?', 'ii', [$clinicId, $userId]],
            'definition_rows' => ['SELECT COUNT(*) FROM `' . $prefix . 'cpms_custom_role_defs` WHERE `id` = ? AND `clinic_id` = ?', 'ii', [$definitionId, $clinicId]],
        ];

        foreach ($checks as $key => [$sql, $types, $values]) {
            $stmt = $conn->prepare($sql);
            if (!$stmt instanceof \mysqli_stmt) {
                continue;
            }
            $args = is_array($values) ? array_values($values) : [$values];
            $stmt->bind_param($types, ...$args);
            $stmt->execute();
            $value = null;
            $stmt->bind_result($value);
            if ($stmt->fetch()) {
                $probe[$key] = (int) $value;
            }
            $stmt->close();
        }

        return $probe;
    }

    /**
     * Concurrency is mandatory in this suite: never SKIPPED.
     */
    public static function available(): bool
    {
        return function_exists('proc_open')
            && defined('PHP_BINARY')
            && PHP_BINARY !== ''
            && is_file(self::scriptPath());
    }

    /**
     * @param array<string, string|int> $task non-secret task parameters only
     */
    public static function launch(array $task): self
    {
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        foreach ($task as $name => $value) {
            $environment[$name] = (string) $value;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open([PHP_BINARY, self::scriptPath()], $descriptors, $pipes, dirname(self::scriptPath(), 3), $environment);
        if (!is_resource($process) || !isset($pipes[1], $pipes[2])) {
            throw new RuntimeException('could not launch the independent archival race worker');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return new self($process, [1 => $pipes[1], 2 => $pipes[2]]);
    }

    public function pid(): int
    {
        $this->refreshStatus();

        // The pid is captured on the first status poll: after close() the handle
        // is no longer a process resource and must not be polled again.
        return $this->pid ?? 0;
    }

    public function isRunning(): bool
    {
        if ($this->closed) {
            return false;
        }

        $status = $this->refreshStatus();

        return is_array($status) && ($status['running'] ?? false) === true;
    }

    /**
     * Next validated protocol message, or null when none arrived in time.
     * Non-protocol output is retained as bounded diagnostics.
     */
    public function nextLine(float $timeoutSeconds): ?string
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $this->pump();
            $line = $this->shiftProtocolLine();
            if ($line !== null) {
                return $line;
            }
            if (!$this->isRunning() && $this->stdoutBuffer === '') {
                $this->pump();
                $line = $this->shiftProtocolLine();
                if ($line !== null) {
                    return $line;
                }

                return null;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * Waits for the worker to finish and reaps it. Returns its exit code, or -1.
     */
    public function waitForExit(float $timeoutSeconds): int
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $this->pump();
            if ($this->closed) {
                return $this->exitCode ?? -1;
            }
            $status = $this->refreshStatus();
            if (is_array($status) && ($status['running'] ?? false) === false) {
                $exitCode = $this->exitCode ?? (int) ($status['exitcode'] ?? -1);
                $this->close();

                return $exitCode;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        return -1;
    }

    public function outcome(): ?string
    {
        $this->pump();
        $this->shiftProtocolLine();

        return $this->outcome;
    }

    public function diagnostics(): string
    {
        $this->pump();

        return $this->redact($this->diagnostics);
    }

    /**
     * Bounded termination: never leaves an orphan, never blocks the suite.
     */
    public function terminate(): void
    {
        if ($this->closed) {
            return;
        }
        $this->pump();
        if ($this->isRunning()) {
            @proc_terminate($this->process);
            $deadline = microtime(true) + 2.0;
            while ($this->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            if ($this->isRunning()) {
                @proc_terminate($this->process, 9);
                $deadline = microtime(true) + 2.0;
                while ($this->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
            }
        }
        $this->close();
    }

    /**
     * Polls the process once and caches the two facts a single poll can report:
     * the pid, and the exit code.
     *
     * PHP's proc_get_status() waits with WNOHANG on every call, so the child is
     * reaped by whichever call observes it first and every later call reports
     * running=false with exitcode -1. Caching the first observation is the only
     * way for a caller to learn the real exit code.
     *
     * @return array<string, mixed>|null
     */
    private function refreshStatus(): ?array
    {
        if ($this->closed || !is_resource($this->process)) {
            return null;
        }
        $status = @proc_get_status($this->process);
        if (!is_array($status)) {
            return null;
        }
        if ($this->pid === null && (int) ($status['pid'] ?? 0) > 0) {
            $this->pid = (int) $status['pid'];
        }
        if (($status['running'] ?? false) === false && $this->exitCode === null) {
            $this->exitCode = (int) ($status['exitcode'] ?? -1);
        }

        return $status;
    }

    private function pump(): void
    {
        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
            $chunk = (string) stream_get_contents($this->pipes[1]);
            if ($chunk !== '') {
                $this->stdoutBuffer .= $chunk;
            }
        }
        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            $chunk = (string) stream_get_contents($this->pipes[2]);
            if ($chunk !== '') {
                $this->diagnostics .= $chunk;
                if (strlen($this->diagnostics) > 4000) {
                    $this->diagnostics = substr($this->diagnostics, -4000);
                }
            }
        }
    }

    private function shiftProtocolLine(): ?string
    {
        while (($position = strpos($this->stdoutBuffer, "\n")) !== false) {
            $line = trim(substr($this->stdoutBuffer, 0, $position));
            $this->stdoutBuffer = substr($this->stdoutBuffer, $position + 1);
            if ($line === '') {
                continue;
            }
            foreach (self::PROTOCOL as $prefix) {
                if (str_starts_with($line, $prefix)) {
                    if ($prefix === 'RESULT:' || $prefix === 'ERROR:') {
                        $this->outcome = substr($line, strlen($prefix));
                    }

                    return $line;
                }
            }
            $this->diagnostics .= $line . "\n";
        }

        return null;
    }

    private function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        if (is_resource($this->process)) {
            @proc_close($this->process); // reap; no zombie survives the suite
        }
    }

    /**
     * Diagnostics leave the harness only with credentials redacted.
     */
    private function redact(string $text): string
    {
        foreach (['CPMS_RACE_DB_PASSWORD', 'CPMS_RACE_DB_USER'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $text = str_replace($value, '[redacted]', $text);
            }
        }

        return $text;
    }
}
