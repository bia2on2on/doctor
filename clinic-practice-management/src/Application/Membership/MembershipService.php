<?php

declare(strict_types=1);

namespace ClinicCore\Application\Membership;

use ClinicCore\Domain\Membership\MembershipException;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * سرویس عضویت — Phase 2 primitives (C4 / P2-D1 / ADR-0031).
 *
 * مدل مصوب:
 *   WP User → حداکثر یک Clinician Profile (u_clinician_user)
 *   User ↔ چند Clinic از طریق Membership (رابطهٔ واقعی — SoT)
 *   Doctor ↔ چند Location از طریق تخصیص صریح (مبنای اعتبارسنجی: عضویتِ فعال)
 *
 * این کلاس فقط «primitive» است؛ Policy Engine / Role Builder فاز ۳ است و
 * current_user_can('cpms_*') جدیدی معرفی نمی‌شود.
 *
 * قواعد fail-closed:
 *  - همهٔ scopeها صریح؛ هیچ fallback/فرضی وجود ندارد.
 *  - عضویتِ suspend شده = بدون دسترسی (activeMembershipFor → null).
 *  - تخصیص Location فقط به Clinicِ همان عضویت/کلینیکِ عضویتِ فعال پزشک.
 */
final class MembershipService
{
    private const STATUS_ACTIVE = 'active';
    private const STATUS_SUSPENDED = 'suspended';
    private const SCOPE_CLINIC = 'clinic';
    private const SCOPE_LOCATION = 'location';

    public function __construct(
        private readonly CpmsDb $db,
        private readonly MembershipRepository $memberships
    ) {
    }

    // ================= Membership =================

    /**
     * ساخت عضویت — تکراری بودن (clinic,user) با خطای صریح رد می‌شود (نه silent update).
     *
     * @param int|null $invitedBywpUserId
     */
    public function createMembership(
        int $clinicId,
        int $wpUserId,
        string $roleKey,
        string $scopeMode = self::SCOPE_CLINIC,
        ?int $invitedBywpUserId = null
    ): int {
        if ($wpUserId <= 0 || get_userdata($wpUserId) === false) {
            throw MembershipException::notFound(__('کاربر وردپرس', 'cpms'), $wpUserId);
        }
        if ($this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        ) === null) {
            throw MembershipException::notFound(__('کلینیک', 'cpms'), $clinicId);
        }
        $this->assertRoleKey($roleKey);
        if (!in_array($scopeMode, [self::SCOPE_CLINIC, self::SCOPE_LOCATION], true)) {
            throw MembershipException::invalidValue('scope_mode', __('باید clinic یا location باشد.', 'cpms'));
        }
        if ($scopeMode === self::SCOPE_LOCATION) {
            // عضویت location-scoped باید با فهرست Location ساخته شود؛ آن مسیر
            // setScopeMode است. ساخت مستقیم (بدون Location = دسترسی به هیچ‌جا)
            // صریحاً رد می‌شود — fail-closed.
            throw MembershipException::invalidValue(
                'scope_mode',
                __('عضویت location-scoped از طریق setScopeMode همراه با فهرست Location ساخته می‌شود.', 'cpms')
            );
        }
        if ($this->memberships->find($clinicId, $wpUserId) !== null) {
            throw MembershipException::duplicate($clinicId, $wpUserId);
        }

        $now = $this->db->nowUtcSql();
        $ok = $this->memberships->insert([
            'clinic_id' => $clinicId,
            'wp_user_id' => $wpUserId,
            'role_key' => $roleKey,
            'scope_mode' => $scopeMode,
            'status' => self::STATUS_ACTIVE,
            'is_primary' => 0,
            'invited_by_wp_user_id' => $invitedBywpUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$ok) {
            // UNIQUE backstop (نظری race) — رفتار همان duplicate است.
            throw MembershipException::duplicate($clinicId, $wpUserId);
        }

        return (int) $this->db->wpdb_last_insert_id();
    }

    /**
     * عضویت (هر وضعیت) — برای نمایش/ادمین.
     *
     * @return array<string, mixed>|null
     */
    public function membershipFor(int $clinicId, int $wpUserId): ?array
    {
        return $this->memberships->find($clinicId, $wpUserId);
    }

    /**
     * عضویت فعال — مبنای دسترسی. suspend ⇒ null (fail-closed).
     *
     * @return array<string, mixed>|null
     */
    public function activeMembershipFor(int $clinicId, int $wpUserId): ?array
    {
        return $this->memberships->findActive($clinicId, $wpUserId);
    }

    /**
     * همهٔ Clinicهای دارای عضویت فعال برای کاربر.
     *
     * @return list<array<string, mixed>>
     */
    public function activeMembershipsForUser(int $wpUserId): array
    {
        return $this->memberships->activeForUser($wpUserId);
    }

    /**
     * @return list<int>
     */
    public function activeClinicIdsForUser(int $wpUserId): array
    {
        return $this->memberships->activeClinicIdsForUser($wpUserId);
    }

    /**
     * تعلیق عضویت — دسترسی فوراً قطع می‌شود؛ Locationها/capabilityها حفظ می‌شوند.
     */
    public function suspendMembership(int $membershipId): void
    {
        $this->transitionStatus($membershipId, self::STATUS_SUSPENDED);
    }

    public function reactivateMembership(int $membershipId): void
    {
        $this->transitionStatus($membershipId, self::STATUS_ACTIVE);
    }

    /**
     * علامت‌گذاری «کلینیک اصلی» کاربر (ترجیح، نه مفهوم امنیتی — P2-D1).
     * فقط یکی از عضویت‌های همان کاربر primary می‌ماند.
     */
    public function setPrimaryMembership(int $clinicId, int $wpUserId): void
    {
        $membership = $this->requireMembership($clinicId, $wpUserId);
        $now = $this->db->nowUtcSql();
        $this->db->transactional(function () use ($membership, $wpUserId, $now): void {
            $this->memberships->update(
                ['is_primary' => 0, 'updated_at' => $now],
                ['wp_user_id' => $wpUserId]
            );
            $this->memberships->update(
                ['is_primary' => 1, 'updated_at' => $now],
                ['id' => (int) $membership['id']]
            );
        });
    }

    /**
     * تغییر role_key (primitive رشته‌ای — سیاست نقش‌ها فاز ۳).
     */
    public function setRoleKey(int $membershipId, string $roleKey): void
    {
        $this->assertRoleKey($roleKey);
        $membership = $this->memberships->findById($membershipId);
        if ($membership === null) {
            throw MembershipException::notFound(__('عضویت', 'cpms'), $membershipId);
        }
        $this->memberships->update(
            ['role_key' => $roleKey, 'updated_at' => $this->db->nowUtcSql()],
            ['id' => $membershipId]
        );
    }

    // ================= Membership ↔ Location =================

    /**
     * تخصیص Locationها به عضویت — فقط Locationهای همان Clinic (fail-closed).
     * عضویت باید location-scoped باشد (scope_mode='clinic' = همهٔ Clinic).
     *
     * @param list<int> $locationIds
     */
    public function syncMembershipLocations(int $membershipId, array $locationIds): void
    {
        $membership = $this->requireMembershipById($membershipId);
        if ((string) $membership['scope_mode'] !== self::SCOPE_LOCATION) {
            throw MembershipException::scopeMode(self::SCOPE_LOCATION);
        }
        if ($locationIds === []) {
            // خالی‌کردن یعنی عضویتی فعال بدون هیچ Location — رد (fail-closed).
            throw MembershipException::locationMismatch($membershipId, []);
        }

        $this->assertLocationsBelongToClinic($membershipId, $locationIds, (int) $membership['clinic_id']);

        $this->db->transactional(function () use ($membershipId, $locationIds): void {
            $this->memberships->replaceLocations($membershipId, $locationIds);
        });
    }

    /**
     * تغییر حالت دسترسی: به 'clinic' فقط با خالی‌کردن Locationها؛
     * به 'location' فقط همراه با فهرست Location.
     *
     * @param list<int> $locationIds
     */
    public function setScopeMode(int $membershipId, string $mode, array $locationIds = []): void
    {
        if (!in_array($mode, [self::SCOPE_CLINIC, self::SCOPE_LOCATION], true)) {
            throw MembershipException::invalidValue('scope_mode', __('باید clinic یا location باشد.', 'cpms'));
        }
        $membership = $this->requireMembershipById($membershipId);

        if ($mode === self::SCOPE_CLINIC) {
            // Clinic-wide یعنی جدول Location برای این عضویت خالی است (مدل 0012).
            $this->db->transactional(function () use ($membershipId): void {
                $this->memberships->replaceLocations($membershipId, []);
                $this->memberships->update(
                    ['scope_mode' => self::SCOPE_CLINIC, 'updated_at' => $this->db->nowUtcSql()],
                    ['id' => $membershipId]
                );
            });

            return;
        }

        if ($locationIds === []) {
            throw MembershipException::locationMismatch($membershipId, []);
        }
        $this->assertLocationsBelongToClinic($membershipId, $locationIds, (int) $membership['clinic_id']);
        $this->db->transactional(function () use ($membershipId, $locationIds): void {
            $this->memberships->replaceLocations($membershipId, $locationIds);
            $this->memberships->update(
                ['scope_mode' => self::SCOPE_LOCATION, 'updated_at' => $this->db->nowUtcSql()],
                ['id' => $membershipId]
            );
        });
    }

    /**
     * @return list<int>
     */
    public function membershipLocationIds(int $membershipId): array
    {
        return $this->memberships->locationIdsFor($membershipId);
    }

    // ================= Capability primitives (metadata فاز ۳) =================

    /**
     * @return list<array<string, mixed>>
     */
    public function capabilitiesFor(int $membershipId): array
    {
        return $this->memberships->capabilitiesFor($membershipId);
    }

    public function setCapability(int $membershipId, string $capability, string $effect): void
    {
        $this->requireMembershipById($membershipId);
        if (!preg_match('/^[a-z][a-z0-9_.]{1,63}$/', $capability)) {
            throw MembershipException::invalidValue('capability', __('قالب مجاز: حروف کوچک/عدد/نقطه/زیرخط.', 'cpms'));
        }
        if (!in_array($effect, ['grant', 'deny'], true)) {
            throw MembershipException::invalidValue('effect', __('باید grant یا deny باشد.', 'cpms'));
        }

        $this->memberships->setCapability($membershipId, $capability, $effect);
    }

    public function removeCapability(int $membershipId, string $capability): void
    {
        $this->memberships->removeCapability($membershipId, $capability);
    }

    // ================= Clinician ↔ Location (P2-D1) =================

    /**
     * تخصیص Locationهای کاری به پزشک — اعتبارسنجی از روی «عضویت فعال» کاربرِ
     * متصل (نه clinicians.clinic_id که legacy است).
     *
     * @param list<int> $locationIds
     */
    public function assignClinicianLocations(int $clinicianId, array $locationIds, ?int $primaryLocationId = null): void
    {
        if ($locationIds === []) {
            throw MembershipException::clinicianLocationMismatch($clinicianId, []);
        }

        $wpUserId = $this->memberships->clinicianWpUserId($clinicianId);
        if ($wpUserId === null || $wpUserId <= 0) {
            throw MembershipException::notFound(__('پزشک', 'cpms'), $clinicianId);
        }

        $allowedClinicIds = $this->memberships->activeClinicIdsForClinician($clinicianId);
        if ($allowedClinicIds === []) {
            throw MembershipException::clinicianLocationMismatch($clinicianId, $locationIds);
        }

        $map = $this->memberships->locationClinicMap($locationIds);
        foreach (array_map('intval', $locationIds) as $locationId) {
            $clinicId = $map[$locationId] ?? null;
            if ($clinicId === null || !in_array($clinicId, $allowedClinicIds, true)) {
                throw MembershipException::clinicianLocationMismatch($clinicianId, [$locationId]);
            }
        }

        if ($primaryLocationId !== null && !in_array((int) $primaryLocationId, array_map('intval', $locationIds), true)) {
            throw MembershipException::invalidValue(
                'primary_location_id',
                __('باید یکی از Locationهای تخصیص‌یافته باشد.', 'cpms')
            );
        }

        $this->db->transactional(
            function () use ($clinicianId, $locationIds, $primaryLocationId): void {
                $this->memberships->replaceClinicianLocations($clinicianId, $locationIds, $primaryLocationId);
            }
        );
    }

    /**
     * @return list<int>
     */
    public function clinicianLocationIds(int $clinicianId): array
    {
        return $this->memberships->clinicianLocationIds($clinicianId);
    }

    public function primaryClinicianLocationId(int $clinicianId): ?int
    {
        return $this->memberships->primaryClinicianLocationId($clinicianId);
    }

    // ================= helpers =================

    private function transitionStatus(int $membershipId, string $to): void
    {
        $membership = $this->requireMembershipById($membershipId);
        $from = (string) $membership['status'];
        if ($from === $to) {
            throw MembershipException::invalidTransition($from, $to);
        }
        if (!in_array([$from, $to], [[self::STATUS_ACTIVE, self::STATUS_SUSPENDED], [self::STATUS_SUSPENDED, self::STATUS_ACTIVE]], true)) {
            throw MembershipException::invalidTransition($from, $to);
        }

        $this->memberships->update(['status' => $to, 'updated_at' => $this->db->nowUtcSql()], ['id' => $membershipId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMembership(int $clinicId, int $wpUserId): array
    {
        $membership = $this->memberships->find($clinicId, $wpUserId);
        if ($membership === null) {
            throw MembershipException::notFound(
                sprintf(
                    /* translators: 1: clinic id, 2: user id */
                    __('عضویت (کلینیک %1$s، کاربر %2$s)', 'cpms'),
                    $clinicId,
                    $wpUserId
                ),
                $clinicId
            );
        }

        return $membership;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMembershipById(int $membershipId): array
    {
        $membership = $this->memberships->findById($membershipId);
        if ($membership === null) {
            throw MembershipException::notFound(__('عضویت', 'cpms'), $membershipId);
        }

        return $membership;
    }

    /**
     * @param list<int> $locationIds
     */
    private function assertLocationsBelongToClinic(int $membershipId, array $locationIds, int $clinicId): void
    {
        $map = $this->memberships->locationClinicMap($locationIds);
        $bad = [];
        foreach (array_map('intval', $locationIds) as $locationId) {
            if (($map[$locationId] ?? null) !== $clinicId) {
                $bad[] = $locationId;
            }
        }
        if ($bad !== []) {
            throw MembershipException::locationMismatch($membershipId, $bad);
        }
    }

    private function assertRoleKey(string $roleKey): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $roleKey)) {
            throw MembershipException::invalidValue(
                'role_key',
                __('قالب مجاز: حروف کوچک/عدد/زیرخط، حداقل ۲ نویسه.', 'cpms')
            );
        }
    }
}
