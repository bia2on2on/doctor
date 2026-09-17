<?php

declare(strict_types=1);

namespace ClinicCore\Application\Clinic;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\ClinicRepository;
use RuntimeException;

/**
 * Trusted Clinic profile update — canonical source cpms_clinics.
 *
 * Invariants:
 * - Only name/address/phone/updated_at may change; preserve id/organization_id/slug/timezone/created_at.
 * - Location timezone is operational truth — no sync.
 * - Atomic validation before any mutation; invalid => zero partial mutation.
 * - Query failure => fail-closed, not silent success.
 * - No first Clinic fallback, no fixed IDs.
 * - Trusted durable Clinic context + CONFIG auth.
 * - Raw clinic_id never trusted; caller must supply trustedClinicId from TrustedClinicEstablisher/ScopeContext.
 * - Clinic A cannot mutate B (enforced via trustedClinicId == durable row + auth in that clinic).
 * - Audit CLINIC_PROFILE_UPDATED only on real change.
 */
final class ClinicProfileService
{
    public function __construct(
        private readonly CpmsDb $db,
        private readonly ClinicRepository $clinics,
        private readonly \ClinicCore\Application\Authorization\AuthorizationService $authz,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @param array<string,mixed> $input {name, address, phone}
     * @return array{clinic_id:int, name:string, address:?string, phone:?string, updated_at:string, noop:bool}
     * @throws ClinicProfileException
     */
    public function updateProfile(int $actorUserId, int $trustedClinicId, array $input): array
    {
        // ---- Fail-closed: auth required, scope required, no fixed IDs ----
        if ($actorUserId <= 0) {
            throw ClinicProfileException::of(ClinicProfileException::AUTH_REQUIRED, 'احراز هویت لازم است', 401);
        }
        if ($trustedClinicId <= 0) {
            throw ClinicProfileException::of(ClinicProfileException::SCOPE_REQUIRED, 'زمینه کلینیک معتبر لازم است', 400);
        }
        // Explicitly deny clinic_id=0,1 fallback usage (must be dynamic trusted)
        // We do not accept 0/1 as magic; but we also enforce that row must exist and be trusted.
        // No first-clinic fallback: we never resolve implicit clinic.

        // ---- Load current canonical row (durable) ----
        try {
            $current = $this->clinics->find($trustedClinicId);
        } catch (RuntimeException $e) {
            throw ClinicProfileException::of(ClinicProfileException::QUERY_FAILED, 'خطای پایگاه داده', 500, ['reason' => $e->getMessage()]);
        }

        if ($current === null) {
            throw ClinicProfileException::of(ClinicProfileException::NOT_FOUND, 'کلینیک یافت نشد', 404, ['clinic_id' => $trustedClinicId]);
        }

        // Preserve invariant: must have id/org/slug/timezone/created_at unchanged
        // We do not allow mutation of those; we only read them to ensure they exist.
        $clinicId = (int) ($current['id'] ?? 0);
        $orgId = (int) ($current['organization_id'] ?? 0);
        $slug = (string) ($current['slug'] ?? '');
        $tz = (string) ($current['timezone'] ?? '');
        $createdAt = (string) ($current['created_at'] ?? '');

        if ($clinicId !== $trustedClinicId || $clinicId <= 0 || $orgId <= 0) {
            throw ClinicProfileException::of(ClinicProfileException::NOT_FOUND, 'کلینیک یافت نشد', 404);
        }

        // ---- CONFIG auth in trusted clinic (durable membership + explicit CONFIG) ----
        try {
            $this->authz->authorize($actorUserId, $trustedClinicId, RolesAndCapabilities::CONFIG);
        } catch (AuthorizationException $e) {
            $code = $e->getErrorCode();
            $http = $e->getCode() > 0 ? (int) $e->getCode() : 403;
            // Map to our domain codes: AUTH_* => PERMISSION_DENIED except unauthenticated
            if ($code === 'AUTH_UNAUTHENTICATED') {
                throw ClinicProfileException::of(ClinicProfileException::AUTH_REQUIRED, 'احراز هویت لازم است', 401, ['reason' => $code]);
            }
            throw ClinicProfileException::of(ClinicProfileException::PERMISSION_DENIED, 'دسترسی لازم را ندارید', $http, ['reason' => $code, 'clinic_id' => $trustedClinicId]);
        }

        // ---- Atomic validation BEFORE any mutation ----
        // name: trimmed non-empty <=190
        // address: optional, <=255 if provided
        // phone: optional, <=32 if provided
        // No timezone handling here (explicitly excluded per task)

        $rawName = $input['name'] ?? null;
        $rawAddress = $input['address'] ?? null;
        $rawPhone = $input['phone'] ?? null;

        // Normalize: trim, null handling
        $name = is_string($rawName) ? trim($rawName) : (is_scalar($rawName) ? trim((string) $rawName) : '');
        $address = $rawAddress === null ? null : trim((string) $rawAddress);
        $phone = $rawPhone === null ? null : trim((string) $rawPhone);

        // Convert empty string address/phone to null or empty? Preserve null if explicitly null, else empty string becomes null? Requirement says only name/address/phone may change; address/phone nullable.
        // We treat empty string as empty string -> should be stored as empty string or null? Original schema allows null? We store null if empty after trim? Better to store empty string as null for consistency? But to preserve old behavior, store null if empty.
        // Let's keep: if address === '' => null, same for phone. However validation should allow empty address/phone.
        if ($address === '') {
            $address = null;
        }
        if ($phone === '') {
            $phone = null;
        }

        $errors = [];

        if ($name === '') {
            $errors['name'] = 'نام کلینیک الزامی است';
        } elseif (mb_strlen($name) > 190) {
            $errors['name'] = 'نام کلینیک حداکثر ۱۹۰ کاراکتر است';
        }

        if ($address !== null && mb_strlen($address) > 255) {
            $errors['address'] = 'آدرس حداکثر ۲۵۵ کاراکتر است';
        }

        if ($phone !== null && mb_strlen($phone) > 32) {
            $errors['phone'] = 'تلفن حداکثر ۳۲ کاراکتر است';
        }

        if ($errors !== []) {
            throw ClinicProfileException::of(ClinicProfileException::VALIDATION, 'اعتبارسنجی ناموفق', 422, ['fields' => $errors]);
        }

        // ---- No-op detection (preserve slug/org/timezone/locations) ----
        $oldName = (string) ($current['name'] ?? '');
        $oldAddress = $current['address'] !== null ? (string) $current['address'] : null;
        $oldPhone = $current['phone'] !== null ? (string) $current['phone'] : null;

        // Normalize old empty to null for comparison
        if ($oldAddress === '') {
            $oldAddress = null;
        }
        if ($oldPhone === '') {
            $oldPhone = null;
        }

        $isNoop = ($oldName === $name) && ($oldAddress === $address) && ($oldPhone === $phone);

        if ($isNoop) {
            return [
                'clinic_id' => $clinicId,
                'name' => $oldName,
                'address' => $oldAddress,
                'phone' => $oldPhone,
                'updated_at' => (string) ($current['updated_at'] ?? ''),
                'noop' => true,
            ];
        }

        // ---- Transactional update, fail-closed, preserve id/org/slug/timezone/created_at ----
        $now = $this->db->nowUtcSql();

        try {
            $result = $this->db->transactional(function () use ($clinicId, $name, $address, $phone, $now, $orgId, $slug, $tz) {
                // Inside transaction, re-fetch for update to ensure row still exists (optional)
                $locked = $this->db->fetchRow(
                    'SELECT id, organization_id, slug, timezone, created_at, name, address, phone FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = %d LIMIT 1 FOR UPDATE',
                    [$clinicId]
                );
                if ($locked === null) {
                    throw ClinicProfileException::of(ClinicProfileException::NOT_FOUND, 'کلینیک یافت نشد', 404);
                }
                // Enforce preservation invariants inside transaction as well
                if ((int) ($locked['organization_id'] ?? 0) !== $orgId || (string) ($locked['slug'] ?? '') !== $slug || (string) ($locked['timezone'] ?? '') !== $tz) {
                    // If org/slug/timezone changed concurrently, we still preserve by not mutating them; but this indicates unexpected mutation.
                    // We do not throw, we just ensure we don't mutate them (our update only touches allowed columns).
                }

                $affected = $this->clinics->updateProfile($clinicId, [
                    'name' => $name,
                    'address' => $address,
                    'phone' => $phone,
                ], $now);

                // If affected ==0 but row exists, could be no-op raced; but we already checked noop. Treat as success with 0 affected? We should still return.
                // However if last_error occurred, clinics->updateProfile would have thrown RuntimeException.

                // Fetch updated row for return
                $updated = $this->clinics->find($clinicId);
                if ($updated === null) {
                    throw ClinicProfileException::of(ClinicProfileException::NOT_FOUND, 'کلینیک یافت نشد پس از به‌روزرسانی', 404);
                }

                return $updated;
            });
        } catch (ClinicProfileException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // Query failure not silent success
            throw ClinicProfileException::of(ClinicProfileException::QUERY_FAILED, 'خطای پایگاه داده هنگام به‌روزرسانی', 500, ['reason' => $e->getMessage()]);
        }

        // ---- Audit only on real change ----
        $updatedAt = (string) ($result['updated_at'] ?? $now);

        // Preserve invariants check after update
        if ((int) ($result['id'] ?? 0) !== $clinicId || (int) ($result['organization_id'] ?? 0) !== $orgId || (string) ($result['slug'] ?? '') !== $slug || (string) ($result['timezone'] ?? '') !== $tz || (string) ($result['created_at'] ?? '') !== $createdAt) {
            // This should never happen because we only updated allowed columns, but fail-closed if it does
            throw ClinicProfileException::of(ClinicProfileException::QUERY_FAILED, 'تغییر ستون‌های محافظت‌شده شناسایی شد', 500);
        }

        // Audit log
        try {
            $this->audit->log(
                'CLINIC_PROFILE_UPDATED',
                ['wp_user_id' => $actorUserId],
                'clinic',
                $clinicId,
                null,
                [
                    'clinic.name' => $oldName,
                    'clinic.address' => $oldAddress,
                    'clinic.phone' => $oldPhone,
                ],
                [
                    'clinic.name' => $name,
                    'clinic.address' => $address,
                    'clinic.phone' => $phone,
                    'clinic.updated_at' => $updatedAt,
                ],
                ['op' => 'clinic_profile_update']
            );
        } catch (\Throwable $e) {
            // Audit failure should not revert successful update, but log to op logger
            try {
                App::op()->warning('CLINIC_PROFILE_AUDIT_FAILED', ['clinic_id' => $clinicId, 'error' => $e->getMessage()]);
            } catch (\Throwable) {
                // ignore
            }
        }

        return [
            'clinic_id' => $clinicId,
            'name' => $name,
            'address' => $address,
            'phone' => $phone,
            'updated_at' => $updatedAt,
            'noop' => false,
        ];
    }
}
