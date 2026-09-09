<?php

declare(strict_types=1);

namespace ClinicCore\Application\Notifications;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Notifications\NotificationEvents;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\NotificationRepository;
use ClinicCore\Settings\Settings;
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
    public function __construct(
        private readonly CpmsDb $db,
        private readonly NotificationRepository $notifications,
        private readonly MembershipRepository $memberships,
        private readonly Settings $settings,
        private readonly OpLogger $op
    ) {
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
        $link = $this->linkedPatient($actorUserId);
        if ($link !== null) {
            $clinicId = (int) $link['clinic_id'];
            $rows = $this->notifications->forPatient($clinicId, (int) $link['patient_id'], $unreadOnly, $limit, $sinceId);
            $unread = $this->notifications->unreadCountForPatient($clinicId, (int) $link['patient_id']);
        } else {
            $clinicId = App::scope()->clinicId;
            $rows = $this->notifications->forUser($clinicId, $actorUserId, $unreadOnly, $limit, $sinceId, $template);
            $unread = $this->notifications->unreadCountForUser($clinicId, $actorUserId);
        }

        return [
            'notifications' => array_map([$this, 'present'], $rows),
            'unread_count' => $unread,
        ];
    }

    /**
     * R2 — Real-time Badge (ADR-0007): رویدادهای بعد از since + شمار خوانده‌نشده.
     *
     * @return array<string, mixed>
     */
    public function since(int $actorUserId, int $sinceId): array
    {
        $link = $this->linkedPatient($actorUserId);
        if ($link !== null) {
            $clinicId = (int) $link['clinic_id'];
            $patientId = (int) $link['patient_id'];
            $rows = $this->notifications->forPatient($clinicId, $patientId, false, 50, $sinceId);
            $lastId = $this->notifications->lastIdForPatient($clinicId, $patientId);
            $unread = $this->notifications->unreadCountForPatient($clinicId, $patientId);
        } else {
            $clinicId = App::scope()->clinicId;
            $rows = $this->notifications->forUser($clinicId, $actorUserId, false, 50, $sinceId);
            $lastId = $this->notifications->lastIdForUser($clinicId, $actorUserId);
            $unread = $this->notifications->unreadCountForUser($clinicId, $actorUserId);
        }

        // قدیمی→جدید تا کلاینت آخرین id را راحت نگه دارد
        $rows = array_reverse($rows);

        return [
            'notifications' => array_map([$this, 'present'], $rows),
            'last_id' => $lastId,
            'unread_count' => $unread,
        ];
    }

    /**
     * ETag برای R2 — آخرین id کلینیک‌محدودِ گیرنده (تغییر نکرده → 304).
     */
    public function lastId(int $actorUserId): int
    {
        $link = $this->linkedPatient($actorUserId);

        return $link !== null
            ? $this->notifications->lastIdForPatient((int) $link['clinic_id'], (int) $link['patient_id'])
            : $this->notifications->lastIdForUser(App::scope()->clinicId, $actorUserId);
    }

    /**
     * علامت‌گذاری خوانده‌شده (read/unread — §5) — فقط رکوردهای خود Actor.
     *
     * @param list<int> $ids
     */
    public function markRead(int $actorUserId, array $ids, bool $all): int
    {
        $link = $this->linkedPatient($actorUserId);
        $patientId = $link !== null ? (int) $link['patient_id'] : null;
        if ($all) {
            return $this->notifications->markAllRead($actorUserId, $patientId);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return 0;
        }

        return $this->notifications->markRead($actorUserId, $patientId, array_slice($ids, 0, 200));
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
        $purged = $this->notifications->purgeArchived((int) $this->settings->get('notif.archive_days', 90));

        return ['sent' => $sent, 'purged' => $purged];
    }

    // =========================================================
    // Quiet Hours (§5) — فقط SMS غیرتعاملی (OTP مستثنا)
    // =========================================================

    /**
     * آیا الان داخل بازه مجاز ارسال SMS است؟ (یادآوری‌ها فقط در این بازه).
     * OTP مسیر inline خودش را دارد و هرگز از این گارد رد نمی‌شود.
     */
    public function smsQuietHoursOpen(): bool
    {
        $start = $this->parseHour((string) $this->settings->get('notif.quiet_hours_start', '08:00'));
        $end = $this->parseHour((string) $this->settings->get('notif.quiet_hours_end', '21:00'));
        if ($start === null || $end === null) {
            return true; // تنظیم نامعتبر → بازه باز (Fail-open عمدی: اعلان مهم‌تر از سکوت)
        }

        // N-6: ساعت مفهومی مطب — UTC→Timezone کلینیک (IANA از cpms_clinics)
        try {
            $local = new \DateTimeImmutable('now', new \DateTimeZone($this->settings->clinicTimezone()));
            $nowHour = (int) $local->format('G');
        } catch (\Exception) {
            $nowHour = (int) gmdate('G');
        }

        if ($start <= $end) {
            return $nowHour >= $start && $nowHour < $end;
        }

        // بازه شب‌گذرنده (مثلاً 20:00–06:00)
        return $nowHour >= $start || $nowHour < $end;
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
     * بیمارِ متصل به حساب WP (الگوی PatientService::me) — null برای Staff.
     * لینک primary + کلینیکِ آن رکورد — clinic Inbox بیمار از خودِ رابطهٔ
     * domain مشتق می‌شود (C6).
     *
     * @return array{patient_id: int, clinic_id: int}|null
     */
    private function linkedPatient(int $wpUserId): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT l.patient_id, p.clinic_id FROM ' . $this->db->table('cpms_patient_user_links') . ' l
             JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = l.patient_id
             WHERE l.wp_user_id = %d AND p.status = %s
             ORDER BY l.is_primary DESC, l.id ASC LIMIT 1',
            [$wpUserId, 'active']
        );

        return $row === null ? null : ['patient_id' => (int) $row['patient_id'], 'clinic_id' => (int) $row['clinic_id']];
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
