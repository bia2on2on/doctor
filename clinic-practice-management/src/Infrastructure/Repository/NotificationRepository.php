<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;
use RuntimeException;

/**
 * Repository اعلان‌ها — cpms_notifications (data-dictionary §32، notifications.md N-1..N-6).
 *
 * همه متدها Clinic-Scope (clinic_id = 1 — ADR-0026 تا V2) هستند.
 */
final class NotificationRepository
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * INSERT با گارد Fail-Loud (الگوی F5+ — wpdb->insert سکتیو false برمی‌گرداند).
     *
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $row['clinic_id'] = 1;
        $row['created_at'] = $row['created_at'] ?? $this->db->nowUtcSql();

        $ok = $this->db->insert('cpms_notifications', $row);
        if (!$ok) {
            throw new RuntimeException('CLINIC_NOTIFICATION_INSERT_FAILED');
        }

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByDedupeKey(string $dedupeKey): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_notifications') . ' WHERE dedupe_key = %s LIMIT 1',
            [$dedupeKey]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_notifications') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * Inbox کاربر Staff (جدیدترین اول).
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $wpUserId, bool $unreadOnly, int $limit, int $sinceId = 0, ?string $template = null): array
    {
        $sql = 'SELECT * FROM ' . $this->db->table('cpms_notifications') .
            ' WHERE clinic_id = 1 AND recipient_wp_user_id = %d AND status != %s';
        $params = [$wpUserId, 'cancelled'];

        if ($sinceId > 0) {
            $sql .= ' AND id > %d';
            $params[] = $sinceId;
        }
        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }
        if ($template !== null) {
            $sql .= ' AND template = %s';
            $params[] = $template;
        }

        $sql .= ' ORDER BY id DESC LIMIT %d';
        $params[] = max(1, min(200, $limit));

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Inbox بیمار (recipient_patient_id) — C7/بیمار App.
     *
     * @return list<array<string, mixed>>
     */
    public function forPatient(int $patientId, bool $unreadOnly, int $limit, int $sinceId = 0): array
    {
        $sql = 'SELECT * FROM ' . $this->db->table('cpms_notifications') .
            ' WHERE clinic_id = 1 AND recipient_patient_id = %d AND status != %s';
        $params = [$patientId, 'cancelled'];

        if ($sinceId > 0) {
            $sql .= ' AND id > %d';
            $params[] = $sinceId;
        }
        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }

        $sql .= ' ORDER BY id DESC LIMIT %d';
        $params[] = max(1, min(200, $limit));

        return $this->db->fetchAll($sql, $params);
    }

    public function lastIdForUser(int $wpUserId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COALESCE(MAX(id), 0) FROM ' . $this->db->table('cpms_notifications') .
            ' WHERE clinic_id = 1 AND recipient_wp_user_id = %d AND status != %s',
            [$wpUserId, 'cancelled']
        );
    }

    public function lastIdForPatient(int $patientId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COALESCE(MAX(id), 0) FROM ' . $this->db->table('cpms_notifications') .
            ' WHERE clinic_id = 1 AND recipient_patient_id = %d AND status != %s',
            [$patientId, 'cancelled']
        );
    }

    public function unreadCountForUser(int $wpUserId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_notifications') .
            ' WHERE clinic_id = 1 AND recipient_wp_user_id = %d AND status != %s AND read_at IS NULL',
            [$wpUserId, 'cancelled']
        );
    }

    public function unreadCountForPatient(int $patientId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_notifications') .
            ' WHERE clinic_id = 1 AND recipient_patient_id = %d AND status != %s AND read_at IS NULL',
            [$patientId, 'cancelled']
        );
    }

    /**
     * علامت‌گذاری خوانده‌شده — فقط رکوردهای خود گیرنده (IDOR-safe با where).
     *
     * @param list<int> $ids
     */
    public function markRead(int $wpUserId, ?int $patientId, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $now = $this->db->nowUtcSql();
        if ($patientId !== null) {
            return $this->db->execute(
                'UPDATE ' . $this->db->table('cpms_notifications') .
                ' SET read_at = %s WHERE recipient_patient_id = %d AND read_at IS NULL AND id IN (' .
                implode(',', array_fill(0, count($ids), '%d')) . ')',
                array_merge([$now, $patientId], array_map('intval', $ids))
            );
        }

        return $this->db->execute(
            'UPDATE ' . $this->db->table('cpms_notifications') .
            ' SET read_at = %s WHERE recipient_wp_user_id = %d AND read_at IS NULL AND id IN (' .
            implode(',', array_fill(0, count($ids), '%d')) . ')',
            array_merge([$now, $wpUserId], array_map('intval', $ids))
        );
    }

    public function markAllRead(int $wpUserId, ?int $patientId): int
    {
        $now = $this->db->nowUtcSql();
        if ($patientId !== null) {
            return $this->db->execute(
                'UPDATE ' . $this->db->table('cpms_notifications') .
                ' SET read_at = %s WHERE recipient_patient_id = %d AND read_at IS NULL',
                [$now, $patientId]
            );
        }

        return $this->db->execute(
            'UPDATE ' . $this->db->table('cpms_notifications') .
            ' SET read_at = %s WHERE recipient_wp_user_id = %d AND read_at IS NULL',
            [$now, $wpUserId]
        );
    }

    /**
     * N-2/N-3 — ارسال اعلان‌های queued کانال Internal (توسط Job notif.dispatch).
     * Idempotent: فقط status=queued → sent (اجرای تکراری بی‌اثر).
     */
    public function dispatchQueued(int $limit): int
    {
        $now = $this->db->nowUtcSql();

        return $this->db->execute(
            'UPDATE ' . $this->db->table('cpms_notifications') .
            ' SET status = %s, sent_at = %s WHERE clinic_id = 1 AND channel = %s AND status = %s' .
            ' ORDER BY id ASC LIMIT %d',
            ['sent', $now, 'internal', 'queued', max(1, min(1000, $limit))]
        );
    }

    /**
     * §5 — انصراف خودکار: لغو نوبت → Cancel اعلان‌های queued مرتبط (dedupe/`apt:{id}`).
     */
    public function cancelQueuedForAppointment(int $appointmentId): int
    {
        return $this->db->execute(
            'UPDATE ' . $this->db->table('cpms_notifications') .
            ' SET status = %s WHERE clinic_id = 1 AND status = %s AND channel = %s' .
            ' AND dedupe_key LIKE %s',
            ['cancelled', 'queued', 'internal', 'apt:' . (int) $appointmentId . ':%']
        );
    }

    /**
     * §5 — Retention: حذف اعلان‌های Internal فرستاده‌شده قدیمی‌تر از N روز.
     */
    public function purgeArchived(int $days, int $limit = 500): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - (max(1, $days) * 86400));

        return $this->db->execute(
            'DELETE FROM ' . $this->db->table('cpms_notifications') .
            ' WHERE clinic_id = 1 AND channel = %s AND status IN (%s, %s) AND created_at < %s' .
            ' ORDER BY id ASC LIMIT %d',
            ['internal', 'sent', 'delivered', $cutoff . '.000', $limit]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public function updateById(int $id, array $row): void
    {
        $this->db->update('cpms_notifications', $row, ['id' => $id]);
    }
}
