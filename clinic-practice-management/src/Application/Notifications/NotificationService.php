<?php

declare(strict_types=1);

namespace ClinicCore\Application\Notifications;

use ClinicCore\Application\Auth\PatientSessionScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Notifications\NotificationEvents;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\NotificationRepository;
use ClinicCore\Settings\Settings;
use ClinicCore\Settings\SettingsFactory;
use Closure;
use Throwable;

/**
 * سرویس اعلان — notifications.md (N-1..N-6).
 *
 * N-1: کد بیزینس فقط publish می‌کند (این سرویس → cpms_notifications).
 * N-2: در Request اصلی فقط INSERT queued — ارسال/وضعیت توسط Job notif.dispatch.
 * N-5: Dedupe با dedupe_key (الگوی SmsService — نسل جدید پس از cancel/failed).
 * N-6: تاریخ‌ها به‌صورت Jalali از فراخواننده می‌رسند (لایه Template فقط جایگزینی می‌کند).
 *
 * Channelها (§2):
 *  - Internal (در-app) → این کلاس (cpms_notifications).
 *  - SMS → پایپ‌لاین Provider-agnostic موجود (SmsService/cpms_sms_messages — ADR-0025)؛
 *    این سرویس همراهِ Internal، SMS را از طریق callback اختیاری SmsService می‌فرستد تا
 *    دو Queue موازی ساخته نشود.
 *  - Email/Push → V1 خالی (اختیاری در کاتالوگ؛ Adapter در معماری آماده).
 */
final class NotificationService
{
    /**
     * @param Closure(): int $currentClinicResolver Clinicِ مالکِ عملیاتِ جاری.
     *        به‌صورت Closure تزریق می‌شود تا ساختِ این سرویس به هیچ Clinic/Scope
     *        محیطی گره نخورد (الگوی SmsService): ثبتِ مسیرهای REST پیش از هر
     *        Scope‌ای انجام می‌شود و فراخوانیِ زودهنگامِ `App::settings()` در
     *        زمانِ ساخت، bootstrap را در نصبِ چند-Clinicه می‌انداخت.
     *        فراخوان‌هایی که Clinicِ صریح دارند (Job/Export) resolverِ ثابتِ
     *        همان Clinic را می‌گیرند — رفتار دقیقاً همان است.
     */
    public function __construct(
        private readonly CpmsDb $db,
        private readonly NotificationRepository $notifications,
        private readonly MembershipRepository $memberships,
        private readonly SettingsFactory $settingsFactory,
        private readonly Closure $currentClinicResolver,
        private readonly OpLogger $op,
        private readonly ?PatientSessionScope $session_scope = null
    ) {
    }

    private function session_scope(): PatientSessionScope {
        return $this->session_scope ?? App::patient_session_scope();
    }

    /**
     * پیکربندیِ Clinicِ مالکِ عملیات — در زمانِ عملیات حل می‌شود (نه در ساخت).
     */
    private function currentSettings(): Settings
    {
        return $this->settingsFactory->forClinic((int) ($this->currentClinicResolver)());
    }

    // =========================================================
    // N-1 — Publish (فراخوانی از Business Logic)
    // =========================================================

    /**
     * رویداد Staff-facing (QUEUE.called / QUEUE.ready_payment) → یک اعلان
     * برای هر دارنده Capability (به‌جز فراخواننده) — notifications.md §3.
     *
     * @param array<string, string> $vars
     * @return int تعداد اعلان‌های ثبت‌شده (جدید)
     */
    public function publishToStaff(
        int $clinic_id,
        string $event,
        array $vars,
        ?string $dedupeBase,
        string $capability,
        int $excludeUserId = 0
    ): int {
        $created = 0;
        foreach ($this->staffUsersWithCapability($clinic_id, $capability, $excludeUserId) as $userId) {
            if ($this->insertNotification(
                $clinic_id,
                ['recipient_wp_user_id' => $userId],
                $event,
                $vars,
                $dedupeBase !== null ? $dedupeBase . ':u' . $userId : null
            ) !== null) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * رویداد Patient-facing (APPT.* / FOLLOWUP.reminder) → Inbox بیمار (C7).
     *
     * @param array<string, string> $vars
     */
    public function publishToPatient(int $clinic_id, int $patientId, string $event, array $vars, ?string $dedupeKey): ?int
    {
        return $this->insertNotification(
            $clinic_id,
            ['recipient_patient_id' => $patientId],
            $event,
            $vars,
            $dedupeKey
        );
    }

    /**
     * رویداد User-facing تک‌گیرنده (مثلاً «Export آماده شد» — report.export).
     *
     * @param array<string, string> $vars
     */
    public function publishToUser(int $clinic_id, int $wpUserId, string $event, array $vars, ?string $dedupeKey): ?int
    {
        return $this->insertNotification(
            $clinic_id,
            ['recipient_wp_user_id' => $wpUserId],
            $event,
            $vars,
            $dedupeKey
        );
    }

    /**
     * §5 — انصراف خودکار: لغو نوبت → Cancel اعلان‌های queued مرتبط (`apt:{id}`).
     */
    public function cancelQueuedForAppointment(int $appointmentId): int
    {
        return $this->notifications->cancelQueuedForAppointment($appointmentId);
    }

    // =========================================================
    // G6/R2 — Inbox / Badge / Read
    // =========================================================

    /**
     * Inbox نقش‌خود (G6): منشی/پزشک → recipient_wp_user_id؛ بیمار → recipient_patient_id.
     *
     * @return array<string, mixed>
     */
    public function inbox(int $actorUserId, bool $unreadOnly, int $limit, int $sinceId = 0, ?string $template = null): array
    {
        if ( $this->is_patient_actor( $actorUserId ) ) {
            $clinic_id = $this->session_scope()->require_clinic_for_read( $actorUserId );
            if ( null === $clinic_id ) {
                return [
                    'notifications' => [],
                    'unread_count'  => 0,
                ];
            }
            $patient_ids = $this->active_linked_patient_ids_in_clinic( $actorUserId, $clinic_id );
            if ( [] === $patient_ids ) {
                return [
                    'notifications' => [],
                    'unread_count'  => 0,
                ];
            }
            $rows   = $this->patient_rows_in_clinic( $clinic_id, $patient_ids, $unreadOnly, $limit, $sinceId );
            $unread = $this->patient_unread_count_in_clinic( $clinic_id, $patient_ids );
        } else {
            $clinicId = App::scope()->clinicId;
            $rows     = $this->notifications->forUser($clinicId, $actorUserId, $unreadOnly, $limit, $sinceId, $template);
            $unread   = $this->notifications->unreadCountForUser($clinicId, $actorUserId);
        }

        return [
            'notifications' => array_map([$this, 'present'], $rows),
            'unread_count'  => $unread,
        ];
    }

    /**
     * R2 — Real-time Badge (ADR-0007): رویدادهای بعد از since + شمار خوانده‌نشده.
     *
     * @return array<string, mixed>
     */
    public function since(int $actorUserId, int $sinceId): array
    {
        if ( $this->is_patient_actor( $actorUserId ) ) {
            $clinic_id = $this->session_scope()->require_clinic_for_read( $actorUserId );
            if ( null === $clinic_id ) {
                return [
                    'notifications' => [],
                    'last_id'       => 0,
                    'unread_count'  => 0,
                ];
            }
            $patient_ids = $this->active_linked_patient_ids_in_clinic( $actorUserId, $clinic_id );
            if ( [] === $patient_ids ) {
                return [
                    'notifications' => [],
                    'last_id'       => 0,
                    'unread_count'  => 0,
                ];
            }
            $rows    = $this->patient_rows_in_clinic( $clinic_id, $patient_ids, false, 50, $sinceId );
            $last_id = $this->patient_last_id_in_clinic( $clinic_id, $patient_ids );
            $unread  = $this->patient_unread_count_in_clinic( $clinic_id, $patient_ids );

            $rows = array_reverse( $rows );

            return [
                'notifications' => array_map( [ $this, 'present' ], $rows ),
                'last_id'       => $last_id,
                'unread_count'  => $unread,
            ];
        }

        $clinicId = App::scope()->clinicId;
        $rows     = $this->notifications->forUser($clinicId, $actorUserId, false, 50, $sinceId);
        $lastId   = $this->notifications->lastIdForUser($clinicId, $actorUserId);
        $unread   = $this->notifications->unreadCountForUser($clinicId, $actorUserId);

        // قدیمی→جدید تا کلاینت آخرین id را راحت نگه دارد
        $rows = array_reverse($rows);

        return [
            'notifications' => array_map([$this, 'present'], $rows),
            'last_id'       => $lastId,
            'unread_count'  => $unread,
        ];
    }

    /**
     * ETag برای R2 — آخرین id کلینیک‌محدودِ گیرنده (تغییر نکرده → 304).
     */
    public function lastId(int $actorUserId): int
    {
        if ( $this->is_patient_actor( $actorUserId ) ) {
            $clinic_id = $this->session_scope()->require_clinic_for_read( $actorUserId );
            if ( null === $clinic_id ) {
                return 0;
            }
            $patient_ids = $this->active_linked_patient_ids_in_clinic( $actorUserId, $clinic_id );
            if ( [] === $patient_ids ) {
                return 0;
            }

            return $this->patient_last_id_in_clinic( $clinic_id, $patient_ids );
        }

        return $this->notifications->lastIdForUser(App::scope()->clinicId, $actorUserId);
    }

    /**
     * علامت‌گذاری خوانده‌شده (read/unread — §5) — فقط رکوردهای خود Actor.
     *
     * @param list<int> $ids
     */
    public function markRead(int $actorUserId, array $ids, bool $all): int
    {
        if ( $this->is_patient_actor( $actorUserId ) ) {
            $clinic_id   = $this->session_scope()->require_clinic_for_write( $actorUserId );
            $patient_ids = $this->active_linked_patient_ids_in_clinic( $actorUserId, $clinic_id );
            if ( [] === $patient_ids ) {
                return 0;
            }

            return $this->mark_patient_read_in_clinic( $clinic_id, $patient_ids, $ids, $all );
        }

        if ($all) {
            return $this->notifications->markAllRead($actorUserId, null);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return 0;
        }

        return $this->notifications->markRead($actorUserId, null, array_slice($ids, 0, 200));
    }

    // =========================================================
    // Job notif.dispatch (N-2/N-3 + Retention)
    // =========================================================

    /**
     * ارسال اعلان‌های queued کانال Internal + Retention (§5 — Archive 90d).
     * Idempotent (J-2) — فراخوانی از NotifDispatchHandler هر دقیقه.
     */
    public function dispatchQueued(): array
    {
        $sent = $this->notifications->dispatchQueued(500);
        $purged = $this->notifications->purgeArchived((int) $this->currentSettings()->get('notif.archive_days', 90));

        return ['sent' => $sent, 'purged' => $purged];
    }

    // =========================================================
    // Quiet Hours (§5) — فقط SMS غیرتعاملی (OTP مستثنا)
    // =========================================================

    /**
     * آیا الان داخل بازه مجاز ارسال SMS است؟ `$timezone` منبع زمانی عملیاتی
     * صریح caller است؛ مقدار null قرارداد قدیمی بر مبنای Clinic را حفظ می‌کند.
     * `$nowUtc` فقط seam محدود زمان برای job/test است و timezone فرایند را تغییر نمی‌دهد.
     */
    public function smsQuietHoursOpen(?string $timezone = null, ?\DateTimeImmutable $nowUtc = null): bool
    {
        $start = $this->parseHour((string) $this->currentSettings()->get('notif.quiet_hours_start', '08:00'));
        $end = $this->parseHour((string) $this->currentSettings()->get('notif.quiet_hours_end', '21:00'));
        if ($start === null || $end === null) {
            return true; // تنظیم نامعتبر → بازه باز (Fail-open عمدی: اعلان مهم‌تر از سکوت)
        }

        $nowHour = $this->quietHoursHour($timezone, $nowUtc);
        if ($start <= $end) {
            return $nowHour >= $start && $nowHour < $end;
        }

        // بازه شب‌گذرنده (مثلاً 20:00–06:00)
        return $nowHour >= $start || $nowHour < $end;
    }

    private function quietHoursHour(?string $timezone, ?\DateTimeImmutable $nowUtc): int
    {
        $now = $nowUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $zoneName = $timezone === null ? $this->currentSettings()->clinicTimezone() : trim($timezone);

        try {
            return (int) $now->setTimezone(new \DateTimeZone($zoneName))->format('G');
        } catch (\Exception) {
            // Preserve the old fail-safe: malformed timezone falls back to UTC hour.
            return (int) $now->setTimezone(new \DateTimeZone('UTC'))->format('G');
        }
    }

    // =========================================================
    // پیوست‌ها
    // =========================================================

    /**
     * @param array<string, string> $vars
     * @param array{recipient_wp_user_id?: int, recipient_patient_id?: int} $recipient
     */
    private function insertNotification(int $clinic_id, array $recipient, string $event, array $vars, ?string $dedupeKey): ?int
    {
        if (!NotificationEvents::isKnown($event)) {
            return null;
        }

        // N-5 Dedupe — الگوی SmsService: در-پرواز/فرستاده‌شده → skip (null)؛
        // cancelled/failed → نسل جدید (پیشگیری از برخورد UNIQUE)
        if ($dedupeKey !== null) {
            $existing = $this->notifications->findByDedupeKey($dedupeKey);
            if ($existing !== null) {
                $status = (string) $existing['status'];
                if ($status === 'queued' || $status === 'sent' || $status === 'delivered') {
                    return null;
                }
                $dedupeKey .= '-' . (int) $existing['id'];
            }
        }

        $rendered = NotificationEvents::render($event, $vars);
        $payload = [
            'title' => $rendered['title'],
            'body' => $rendered['body'],
            'vars' => $vars,
        ];

        $id = $this->notifications->insert($clinic_id, array_merge($recipient, [
            'channel' => 'internal',
            'template' => $event,
            'payload_json' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status' => 'queued', // N-2
            'attempts' => 0,
            'dedupe_key' => $dedupeKey,
            'scheduled_at' => $this->db->nowUtcSql(),
        ]));

        return $id;
    }

    /**
     * گیرندگان رویدادهای صف = منشی‌های دارای Capability (کاتالوگ §3:
     * QUEUE.called/ready_payment → «Internal منشی»؛ پزشک خودش فراخوانده است).
     *
     * @return list<int>
     */
    /**
     * گیرندگان broadcast staff — فقط اعضای فعالِ همان Clinic (C6:
     * broadcast نقشِ سراسری WP نیست؛ منبع recipient = عضویت C4).
     * بررسی capability با همان has_cap موجود انجام می‌شود (بدون API جدید).
     *
     * @return list<int>
     */
    private function staffUsersWithCapability(int $clinic_id, string $capability, int $excludeUserId): array
    {
        $ids = [];
        foreach ($this->memberships->active_member_user_ids_for_clinic($clinic_id) as $userId) {
            if ($userId === $excludeUserId || $userId === 0) {
                continue;
            }
            $user = get_userdata($userId);
            if ($user !== false && $user->has_cap($capability)) {
                $ids[] = $userId;
            }
        }

        return $ids;
    }

    /**
     * Determine whether `$actor_user_id` is acting as a patient rather than staff.
     */
    private function is_patient_actor( int $actor_user_id ): bool {
        if ( $actor_user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
            return false;
        }

        $user = get_userdata( $actor_user_id );
        if ( ! ( $user instanceof \WP_User ) || ! $user->exists() ) {
            return false;
        }

        $roles       = (array) $user->roles;
        $staff_roles = [
            RolesAndCapabilities::ROLE_DOCTOR,
            RolesAndCapabilities::ROLE_SECRETARY,
            RolesAndCapabilities::ROLE_MANAGER,
            RolesAndCapabilities::ROLE_ACCOUNTANT,
            'administrator',
        ];
        if ( array_intersect( $staff_roles, $roles ) !== [] ) {
            return false;
        }

        if ( in_array( RolesAndCapabilities::ROLE_PATIENT, $roles, true ) ) {
            return true;
        }

        if ( null !== ScopeContext::tryGet() ) {
            return false;
        }

        $has_link = $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->db->table( 'cpms_patient_user_links' ) . ' WHERE wp_user_id = %d LIMIT 1',
            [ $actor_user_id ]
        );

        return null !== $has_link;
    }

    /**
     * Active linked patient IDs for `$wp_user_id` strictly inside `$clinic_id`.
     *
     * @return list<int>
     */
    private function active_linked_patient_ids_in_clinic( int $wp_user_id, int $clinic_id ): array {
        if ( $wp_user_id <= 0 || $clinic_id <= 0 ) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT p.id'
            . ' FROM ' . $this->db->table( 'cpms_patient_user_links' ) . ' l'
            . ' INNER JOIN ' . $this->db->table( 'cpms_patients' ) . ' p'
            . ' ON p.id = l.patient_id AND p.clinic_id = l.clinic_id'
            . ' WHERE l.wp_user_id = %d AND l.clinic_id = %d AND p.status = %s'
            . ' ORDER BY l.is_primary DESC, l.id ASC',
            [ $wp_user_id, $clinic_id, 'active' ]
        );

        $ids = [];
        foreach ( $rows as $row ) {
            $pid = (int) ( $row['id'] ?? 0 );
            if ( $pid > 0 ) {
                $ids[] = $pid;
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $patient_ids
     * @return list<array<string, mixed>>
     */
    private function patient_rows_in_clinic(
        int $clinic_id,
        array $patient_ids,
        bool $unread_only,
        int $limit,
        int $since_id
    ): array {
        if ( 1 === count( $patient_ids ) ) {
            return $this->notifications->forPatient( $clinic_id, $patient_ids[0], $unread_only, $limit, $since_id );
        }

        $placeholders = implode( ',', array_fill( 0, count( $patient_ids ), '%d' ) );
        $sql          = 'SELECT * FROM ' . $this->db->table( 'cpms_notifications' )
            . ' WHERE clinic_id = %d AND recipient_patient_id IN (' . $placeholders . ') AND status != %s';
        $params       = array_merge( [ $clinic_id ], $patient_ids, [ 'cancelled' ] );

        if ( $since_id > 0 ) {
            $sql     .= ' AND id > %d';
            $params[] = $since_id;
        }
        if ( $unread_only ) {
            $sql .= ' AND read_at IS NULL';
        }

        $sql     .= ' ORDER BY id DESC LIMIT %d';
        $params[] = max( 1, min( 200, $limit ) );

        return $this->db->fetchAll( $sql, $params );
    }

    /**
     * @param list<int> $patient_ids
     */
    private function patient_unread_count_in_clinic( int $clinic_id, array $patient_ids ): int {
        if ( 1 === count( $patient_ids ) ) {
            return $this->notifications->unreadCountForPatient( $clinic_id, $patient_ids[0] );
        }

        $placeholders = implode( ',', array_fill( 0, count( $patient_ids ), '%d' ) );

        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_notifications' )
            . ' WHERE clinic_id = %d AND recipient_patient_id IN (' . $placeholders . ')'
            . ' AND status != %s AND read_at IS NULL',
            array_merge( [ $clinic_id ], $patient_ids, [ 'cancelled' ] )
        );
    }

    /**
     * @param list<int> $patient_ids
     */
    private function patient_last_id_in_clinic( int $clinic_id, array $patient_ids ): int {
        if ( 1 === count( $patient_ids ) ) {
            return $this->notifications->lastIdForPatient( $clinic_id, $patient_ids[0] );
        }

        $placeholders = implode( ',', array_fill( 0, count( $patient_ids ), '%d' ) );

        return (int) $this->db->fetchValue(
            'SELECT COALESCE(MAX(id), 0) FROM ' . $this->db->table( 'cpms_notifications' )
            . ' WHERE clinic_id = %d AND recipient_patient_id IN (' . $placeholders . ') AND status != %s',
            array_merge( [ $clinic_id ], $patient_ids, [ 'cancelled' ] )
        );
    }

    /**
     * @param list<int> $patient_ids
     * @param list<int> $ids
     */
    private function mark_patient_read_in_clinic( int $clinic_id, array $patient_ids, array $ids, bool $all ): int {
        if ( [] === $patient_ids ) {
            return 0;
        }

        $now                  = $this->db->nowUtcSql();
        $patient_placeholders = implode( ',', array_fill( 0, count( $patient_ids ), '%d' ) );

        if ( $all ) {
            return $this->db->execute(
                'UPDATE ' . $this->db->table( 'cpms_notifications' )
                . ' SET read_at = %s'
                . ' WHERE clinic_id = %d AND recipient_patient_id IN (' . $patient_placeholders . ') AND read_at IS NULL',
                array_merge( [ $now, $clinic_id ], $patient_ids )
            );
        }

        $clean_ids = array_values(
            array_unique(
                array_filter(
                    array_map( 'intval', $ids ),
                    static fn ( int $item_id ): bool => $item_id > 0
                )
            )
        );
        if ( [] === $clean_ids ) {
            return 0;
        }

        $bounded_ids     = array_slice( $clean_ids, 0, 200 );
        $id_placeholders = implode( ',', array_fill( 0, count( $bounded_ids ), '%d' ) );

        return $this->db->execute(
            'UPDATE ' . $this->db->table( 'cpms_notifications' )
            . ' SET read_at = %s'
            . ' WHERE clinic_id = %d AND recipient_patient_id IN (' . $patient_placeholders . ')'
            . ' AND read_at IS NULL AND id IN (' . $id_placeholders . ')',
            array_merge( [ $now, $clinic_id ], $patient_ids, $bounded_ids )
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $payload = json_decode((string) $row['payload_json'], true);

        return [
            'id' => (int) $row['id'],
            'event' => (string) $row['template'],
            'channel' => (string) $row['channel'],
            'status' => (string) $row['status'],
            'title' => (string) ($payload['title'] ?? ''),
            'body' => (string) ($payload['body'] ?? ''),
            'read_at' => $row['read_at'] !== null ? (string) $row['read_at'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function parseHour(string $hhmm): ?int
    {
        if (preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $hhmm) !== 1) {
            return null;
        }

        return (int) substr($hhmm, 0, 2);
    }

    /**
     * لاگ عملیاتی بدون PHI/Secret (FR-21.3) — فقط شناسه‌ها.
     */
    public function logDispatchFailure(Throwable $e): void
    {
        $this->op->error('notif.dispatch_failed', ['error' => $e->getMessage()]);
    }
}
