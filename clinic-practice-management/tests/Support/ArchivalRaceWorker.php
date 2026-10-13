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
    private const PROTOCOL = ['FD_PROBE:', 'READY:', 'LOCK_QUERY:', 'RESULT:', 'ERROR:'];

    /**
     * Protocol lines are never free text. A field value has to be one of these
     * shapes, so an exception message — which can carry credentials, SQL or
     * patient text — cannot travel through the protocol even by accident.
     */
    private const JSON_SCHEMA = [
        'FD_PROBE' => ['proc_fd', 'fds_beyond_stdio', 'socket_count', 'env_keys'],
        'READY' => ['connection_id'],
        'LOCK_QUERY' => ['connection_id', 'sha256', 'base64'],
        'RESULT' => ['outcome'],
        // Codes only, plus an optional opaque digest reference. The reference is
        // derived from the code, the exception class and the message length, so
        // it can be compared across runs without the message ever leaving.
        'ERROR' => ['code', 'ref'],
    ];

    /**
     * The worker's entire environment. Nothing else is forwarded: an allowlist
     * cannot leak a credential the test runner happens to export, and the child
     * gets no ambient configuration it does not need (no HOME, no WordPress or
     * Composer developer variables, no CI job metadata).
     *
     * WP_CORE_DIR is carried because wp-tests-config.php can define ABSPATH from
     * it; nothing else from wp-content comes across.
     */
    public const ENVIRONMENT_ALLOWLIST = [
        'PATH',
        'TMPDIR',
        'SYSTEMROOT',
        'WP_TESTS_DIR',
        'WP_TESTS_CONFIG_FILE_PATH',
        'WP_CORE_DIR',
        'CPMS_RACE_DB_NAME',
        'CPMS_RACE_DB_USER',
        'CPMS_RACE_DB_PASSWORD',
        'CPMS_RACE_DB_HOST',
        'CPMS_RACE_TABLE_PREFIX',
        'CPMS_RACE_CLINIC_ID',
        'CPMS_RACE_DEFINITION_ID',
        'CPMS_RACE_MANAGER_USER_ID',
        'CPMS_RACE_TARGET_USER_ID',
        'CPMS_RACE_ROLE_KEY',
        'CPMS_RACE_OPERATION',
        'CPMS_RACE_LOCK_WAIT_TIMEOUT',
        'CPMS_RACE_FAULT_INJECT',
    ];

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
     * Pinned descriptor targets, index => target, for this process. Static
     * because pinning happens before any worker handle exists and the set is a
     * property of the process, not of one handle: which descriptors were hidden
     * is what the worker's inventory is compared against.
     *
     * @var array<int, string>
     */
    private static array $pinnedDescriptors = [];

    /**
     * The worker's own descriptor inventory, captured on its first line.
     *
     * @var array<string, mixed>|null
     */
    private ?array $fdProbe = null;

    /**
     * The environment names this launcher actually sent to the worker — the
     * authoritative expectation for the worker's own inventory. Computed from
     * what was sent, not from this process's environment, because task
     * parameters normally exist only in the task array.
     *
     * @var list<string>
     */
    private array $launchEnvironmentKeys = [];

    /**
     * Pins for the names every worker needs. Process-global because the
     * environment is, and restored in close().
     *
     * @var array<string, array{real: string, marker: string}>
     */
    private static array $requiredEnvironmentPins = [];

    /** @var array<string, array{real: string, marker: string}> */
    private array $environmentRestores = [];

    /**
     * What the parent saw on the pinned fd numbers while the worker was running:
     * the parent's own descriptors, unchanged. This is the counterpart of the
     * worker's /dev/null inventory — same fd numbers, two processes, different
     * targets.
     *
     * @var array<int, string>
     */
    private array $observedFds = [];

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
     * Identity of one synthetic WordPress user: the login the test owns, its
     * numeric id, and the usermeta marker only fixture provisioning writes.
     *
     * @return list<array{login: string, id: int, user_rows: int, usermeta_rows: int}>|null
     */
    public static function ownershipKeys(\mysqli $conn, string $prefix, string $login): ?array
    {
        $stmt = $conn->prepare(
            'SELECT u.ID, u.user_login, '
            . '(SELECT COUNT(*) FROM `' . $prefix . 'users` WHERE ID = u.ID) AS user_rows, '
            . '(SELECT COUNT(*) FROM `' . $prefix . 'usermeta` WHERE user_id = u.ID AND meta_key = ?) AS usermeta_rows '
            . 'FROM `' . $prefix . 'users` u WHERE u.user_login = ? LIMIT 1'
        );
        if (!$stmt instanceof \mysqli_stmt) {
            return null;
        }
        $marker = 'cpms_fixture_owner';
        $stmt->bind_param('ss', $marker, $login);
        $stmt->execute();
        $row = ['id' => 0, 'login' => '', 'user_rows' => 0, 'usermeta_rows' => 0];
        $stmt->bind_result($row['id'], $row['login'], $row['user_rows'], $row['usermeta_rows']);
        $fetched = $stmt->fetch();
        $stmt->close();
        if (!$fetched) {
            return null;
        }

        return [[
            'login' => (string) $row['login'],
            'id' => (int) $row['id'],
            'user_rows' => (int) $row['user_rows'],
            'usermeta_rows' => (int) $row['usermeta_rows'],
        ]];
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
        // Allowlist only: the child sees exactly these names, each either from
        // the caller or from this process's environment when already set.
        $environment = [];
        // The required names are pinned too: even though they must reach the
        // child, no value of theirs may appear in the worker's output, and the
        // pins are restored when the handle closes.
        self::$requiredEnvironmentPins = self::pinProcessEnvironment(self::ENVIRONMENT_ALLOWLIST);
        $environment = [];
        foreach (self::ENVIRONMENT_ALLOWLIST as $name) {
            $value = array_key_exists($name, $task) ? $task[$name] : getenv($name);
            if (is_string($value) && $value !== '' || is_int($value)) {
                $environment[$name] = (string) $value;
            }
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Descriptor isolation. PHP never sets FD_CLOEXEC on the MySQL socket it
        // creates (main/streams/xp_socket.c) and proc_open() closes only the
        // parent ends of its own pipes, so a parent-held session socket would
        // survive exec into the worker as an inheritable descriptor. Every
        // descriptor this process owns beyond stdio is therefore pinned to
        // /dev/null: the descriptor-spec key is the child's fd number, so the
        // child dup2()s /dev/null over each one before execvp().
        self::$pinnedDescriptors = self::pinDescriptorTargets(array_keys($descriptors));
        foreach (self::$pinnedDescriptors as $fd => $target) {
            $descriptors[$fd] = ['null'];
        }

        $process = @proc_open([PHP_BINARY, self::scriptPath()], $descriptors, $pipes, dirname(self::scriptPath(), 3), $environment);
        if (!is_resource($process) || !isset($pipes[1], $pipes[2])) {
            throw new RuntimeException('could not launch the independent archival race worker');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $worker = new self($process, [1 => $pipes[1], 2 => $pipes[2]]);
        $worker->launchEnvironmentKeys = array_keys($environment);
        sort($worker->launchEnvironmentKeys);
        $worker->assertPinnedDescriptorsPresent();

        return $worker;
    }

    /**
     * Descriptor numbers this process currently holds beyond stdio, mapped to
     * their targets. Read through /proc, so no descriptor is opened, dup()ed or
     * closed by this method — the very mistake it exists to prevent.
     *
     * @return array<int, string>
     */
    private static function pinDescriptorTargets(array $exclude): array
    {
        $pinned = [];
        $dir = '/proc/self/fd';
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return $pinned; // /proc-less host: nothing can be enumerated, so nothing is pinned
        }
        foreach ($entries as $entry) {
            if (!is_string($entry) || !ctype_digit($entry)) {
                continue;
            }
            $fd = (int) $entry;
            if ($fd <= 2 || in_array($fd, $exclude, true)) {
                continue;
            }
            $target = @readlink($dir . '/' . $fd);
            if (is_string($target)) {
                $pinned[$fd] = $target;
            }
        }

        return $pinned;
    }

    /**
     * The pinned descriptors must still resolve while a worker is in flight: if
     * any of them were closed or reused, comparing the worker's inventory with
     * the pinned one afterwards would be meaningless. Runs in the parent, before
     * the child has executed anything.
     */
    private function assertPinnedDescriptorsPresent(): void
    {
        foreach (self::$pinnedDescriptors as $fd => $target) {
            if (self::readDescriptorTarget($fd) !== $target) {
                $this->terminate();

                throw new RuntimeException('descriptor ' . $fd . ' changed between pinning and launch; isolation cannot be asserted');
            }
        }
    }

    /** @return string|null */
    private static function readDescriptorTarget(int $fd): ?string
    {
        $target = @readlink('/proc/self/fd/' . $fd);

        return is_string($target) ? $target : null;
    }

    /**
     * @param list<int> $fds
     *
     * @return array<int, string>
     */
    private static function readDescriptorTargets(int $pid, array $fds): array
    {
        $targets = [];
        foreach ($fds as $fd) {
            $target = @readlink('/proc/' . $pid . '/fd/' . $fd);
            if (is_string($target)) {
                $targets[$fd] = $target;
            }
        }

        return $targets;
    }

    /**
     * Assertion-ready outcome of the worker's descriptor-isolation proof.
     *
     * `pinned_isolation_proven` is true only when the pinned inventory was
     * non-empty, every pinned fd is present in the worker's own inventory *and*
     * every target the worker reported for those fds is exactly /dev/null. On a
     * /proc-less host — where the inventory cannot be read at all — it is false
     * and the caller reports the mechanism as unproven rather than assuming it.
     *
     * @return array<string, mixed>
     */
    public function descriptorIsolationProbe(): array
    {
        $probe = $this->fdProbe;
        $fds = is_array($probe) && is_array($probe['fds_beyond_stdio'] ?? null)
            ? $probe['fds_beyond_stdio']
            : [];

        $targetsAreNull = true;
        foreach (array_keys(self::$pinnedDescriptors) as $fd) {
            $reported = $fds[(string) $fd] ?? $fds[$fd] ?? null;
            if ($reported !== 'dev-null') {
                $targetsAreNull = false;
            }
        }

        $socketCount = is_array($probe) && is_int($probe['socket_count'] ?? null) ? (int) $probe['socket_count'] : null;

        return [
            'proc_fd' => is_array($probe) ? (bool) ($probe['proc_fd'] ?? false) : false,
            'pinned_fd_count' => count(self::$pinnedDescriptors),
            'pinned_fds' => array_map('intval', array_keys(self::$pinnedDescriptors)),
            'pinned_isolation_proven' => self::$pinnedDescriptors !== [] && $targetsAreNull,
            'worker_fds_beyond_stdio' => $fds,
            'worker_socket_count' => $socketCount,
            'worker_env_keys' => is_array($probe) && is_array($probe['env_keys'] ?? null) ? $probe['env_keys'] : [],
            'launch_environment_keys' => $this->launchEnvironmentKeys,
            'parent_observed_fd_count' => count($this->observedFds),
        ];
    }

    /**
     * Identifiers the worker must have inherited but provably did not: each one
     * is set here, then replaced with a marker before launch, so the worker can
     * report the marker while the parent still reads the real value.
     *
     * @return array<string, array{real: string, marker: string}>
     */
    public function pinEnvironmentKeys(array $names): array
    {
        $pins = [];
        foreach ($names as $name) {
            $real = getenv($name);
            if (!is_string($real) || $real === '') {
                continue;
            }
            $marker = 'cpms-env-marker-' . bin2hex(random_bytes(6));
            $pin = ['real' => $real, 'marker' => $marker];
            @putenv($name . '=' . $marker);
            $_ENV[$name] = $marker;
            $_SERVER[$name] = $marker;
            $pins[$name] = $pin;
            $this->environmentRestores[$name] = $pin;
        }

        return $pins;
    }

    /**
     * Restores every name pinned at launch to its real value, so this process's
     * environment is exactly as it was before the launch.
     */
    public function releaseEnvironmentKeys(): void
    {
        self::restoreProcessEnvironment($this->environmentRestores);
        $this->environmentRestores = [];
    }

    /**
     * Names a worker must never inherit. The probe pins each of these to a
     * unique marker before launch: a forwarded name would show up in the
     * worker's environment inventory, and no marker value may appear anywhere in
     * the worker's output or in the evidence record.
     */
    public const PRIVACY_MARKER_VARS = [
        'CPMS_UNRELATED_SECRET',
        'GITHUB_REPOSITORY',
        'GITHUB_ACTION',
        'GITHUB_TOKEN',
        'HOME',
        'USER',
        'HOSTNAME',
        'LANG',
    ];

    /**
     * Replaces the value of every named variable that exists with a unique
     * marker and returns the pins that restore it. The environment is a
     * process-global resource, so the pins are handled explicitly by the caller
     * (the parent reads the real values; only the child is ever shown markers).
     *
     * @param list<string> $names
     *
     * @return array<string, array{real: string, marker: string}>
     */
    public static function pinProcessEnvironment(array $names): array
    {
        $pins = [];
        foreach ($names as $name) {
            $real = getenv($name);
            if (!is_string($real) || $real === '') {
                continue;
            }
            $marker = 'cpms-env-marker-' . bin2hex(random_bytes(6));
            $pins[$name] = ['real' => $real, 'marker' => $marker];
            @putenv($name . '=' . $marker);
            $_ENV[$name] = $marker;
            $_SERVER[$name] = $marker;
        }

        return $pins;
    }

    /**
     * @param array<string, array{real: string, marker: string}> $pins
     */
    public static function restoreProcessEnvironment(array $pins): void
    {
        foreach ($pins as $name => $pin) {
            @putenv($name . '=' . $pin['real']);
            $_ENV[$name] = $pin['real'];
            $_SERVER[$name] = $pin['real'];
        }
    }

    private static function bytesLower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
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
            while (($line = $this->shiftProtocolLine()) !== null) {
                if (str_starts_with($line, 'FD_PROBE:')) {
                    continue; // inventory, not a step of the handshake
                }

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
        if (($status['running'] ?? false) === true && self::$pinnedDescriptors !== [] && $this->observedFds === []) {
            $this->observedFds = self::readDescriptorTargets($this->pid, array_keys(self::$pinnedDescriptors));
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
                    if ($prefix === 'FD_PROBE:') {
                        $decoded = json_decode(substr($line, strlen($prefix)), true);
                        $this->fdProbe = is_array($decoded) ? $decoded : null;
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
        self::restoreProcessEnvironment(self::$requiredEnvironmentPins);
        self::$requiredEnvironmentPins = [];
        $this->releaseEnvironmentKeys();
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
