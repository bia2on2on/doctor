<?php

declare(strict_types=1);

namespace ClinicCore\Application\Location;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\LocationRepository;
use RuntimeException;

/**
 * Trusted Location master-data writes — CREATE + UPDATE name/timezone (Phase 4).
 *
 * Invariants:
 * - caller باید trustedClinicId را از TrustedClinicEstablisher/ScopeContext بیاورد؛
 *   clinic_id خام payload هرگز authority نیست و به این سرویس نمی‌رسد.
 * - هر write عضویت فعال + مجوز Clinic-scoped CONFIG را در همان trusted Clinic
 *   دوباره احراز می‌کند (fail-closed).
 * - timezone حقیقت عملیاتی Location است: فقط شناسهٔ معتبر IANA پذیرفته می‌شود،
 *   بدون fallback به Asia/Tehran و بدون هیچ sync با cpms_clinics.timezone.
 * - CREATE همیشه is_primary=0 و is_active=1 می‌سازد؛ payload نمی‌تواند
 *   is_primary/is_active/organization/مالک را انتخاب کند.
 * - UPDATE فقط name/timezone/updated_at را تغییر می‌دهد؛ clinic_id/slug/
 *   is_primary/is_active/created_at حفظ می‌شوند.
 * - Location خارجی و ناموجود denial یکسان می‌گیرند (enumeration parity).
 * - اعتبارسنجی کامل قبل از mutation؛ خطای query → fail-closed.
 * - Audit فقط روی تغییر واقعی و با trusted clinic_id صریح.
 * - timezone فقط forward-looking است: هیچ rewrite/translate روی slotها/
 *   appointmentها/visitها/مقادیر wall-clock تاریخی انجام نمی‌شود.
 */
final class LocationService
{
    public function __construct(
        private readonly CpmsDb $db,
        private readonly LocationRepository $locations,
        private readonly AuthorizationService $authz,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * CREATE — Location غیراصلیِ فعال داخل trusted Clinic.
     *
     * @param array<string,mixed> $input {name, slug, timezone}
     *
     * @return array{location_id:int, clinic_id:int, name:string, slug:string, timezone:string, is_primary:int, is_active:int, created_at:string, updated_at:string, noop:bool}
     *
     * @throws LocationException
     */
    public function createLocation(int $actorUserId, int $trustedClinicId, array $input): array
    {
        $this->guardActorAndScope($actorUserId, $trustedClinicId);

        // ---- اعتبارسنجی کامل قبل از هر mutation ----
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $slug = self::sanitizeSlug((string) ($input['slug'] ?? ''));
        $timezone = is_string($input['timezone'] ?? null) ? trim($input['timezone']) : '';

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'نام شعبه الزامی است';
        } elseif (mb_strlen($name) > 190) {
            $errors['name'] = 'نام شعبه حداکثر ۱۹۰ کاراکتر است';
        }

        if ($slug === '') {
            $errors['slug'] = 'شناسهٔ (slug) شعبه الزامی است';
        } elseif (mb_strlen($slug) > 190) {
            $errors['slug'] = 'شناسهٔ (slug) شعبه حداکثر ۱۹۰ کاراکتر است';
        }

        if ($timezone === '') {
            $errors['timezone'] = 'منطقهٔ زمانی شعبه الزامی است';
        } elseif (!in_array($timezone, timezone_identifiers_list(), true)) {
            // هیچ fallback ای وجود ندارد — مقدار نامعتبر رد می‌شود.
            $errors['timezone'] = 'منطقهٔ زمانی باید شناسهٔ معتبر IANA باشد';
        }

        if ($errors !== []) {
            throw LocationException::of(LocationException::VALIDATION, 'اعتبارسنجی محل انجام نشد', 422, ['fields' => $errors]);
        }

        // ---- یکتایی slug در محدودهٔ trusted Clinic (نه سراسری) ----
        try {
            if ($this->locations->findBySlug($trustedClinicId, $slug) !== null) {
                throw LocationException::of(
                    LocationException::CONFLICT,
                    'شناسهٔ (slug) شعبه در همین Clinic قبلاً استفاده شده است',
                    409,
                    ['field' => 'slug']
                );
            }
        } catch (LocationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw LocationException::of(LocationException::QUERY_FAILED, 'خطای پایگاه داده', 500, ['reason' => $e->getMessage()]);
        }

        // ---- mutation: فقط از trusted Clinic، همیشه غیراصلی و فعال ----
        $now = $this->db->nowUtcSql();

        try {
            $locationId = $this->db->transactional(
                fn (): int => $this->locations->create($trustedClinicId, $name, $slug, $timezone, $now)
            );
        } catch (RuntimeException $e) {
            // شامل رقابت روی u_location_slug هم هست — deterministic fail-closed.
            throw LocationException::of(LocationException::QUERY_FAILED, 'خطای پایگاه داده هنگام ساخت محل', 500, ['reason' => $e->getMessage()]);
        }

        $row = $this->readBack($locationId);

        // ---- readback: invariantهای قطعی CREATE باید دقیقاً برقرار باشند ----
        if ((int) ($row['clinic_id'] ?? 0) !== $trustedClinicId
            || (int) ($row['is_primary'] ?? 1) !== 0
            || (int) ($row['is_active'] ?? 0) !== 1
        ) {
            throw LocationException::of(LocationException::QUERY_FAILED, 'ایناریانت‌های ساخت محل نقض شد', 500);
        }

        $this->audit(
            'LOCATION_CREATED',
            $actorUserId,
            $locationId,
            $trustedClinicId,
            null,
            [
                'location.name' => (string) $row['name'],
                'location.slug' => (string) $row['slug'],
                'location.timezone' => (string) $row['timezone'],
                'location.clinic_id' => (int) $row['clinic_id'],
                'location.is_primary' => (int) $row['is_primary'],
                'location.is_active' => (int) $row['is_active'],
            ],
            ['op' => 'location_create']
        );

        return $this->resultRow($row, false);
    }

    /**
     * UPDATE — فقط name/timezone یک Location موجود در trusted Clinic.
     *
     * @param array<string,mixed> $input {name, timezone}
     *
     * @return array{location_id:int, clinic_id:int, name:string, slug:string, timezone:string, is_primary:int, is_active:int, created_at:string, updated_at:string, noop:bool}
     *
     * @throws LocationException
     */
    public function updateLocation(int $actorUserId, int $trustedClinicId, int $locationId, array $input): array
    {
        $this->guardActorAndScope($actorUserId, $trustedClinicId);

        // ---- خواندن ردیف durable اول؛ مالکیت باید با trusted Clinic یکی باشد ----
        // Location ناموجود و Location خارجی دقیقاً denial یکسان می‌گیرند.
        try {
            $current = $locationId > 0 ? $this->locations->find($locationId) : null;
        } catch (RuntimeException $e) {
            throw LocationException::of(LocationException::QUERY_FAILED, 'خطای پایگاه داده', 500, ['reason' => $e->getMessage()]);
        }

        if ($current === null || (int) ($current['clinic_id'] ?? 0) !== $trustedClinicId) {
            throw LocationException::of(LocationException::NOT_FOUND, 'محل موردنظر یافت نشد', 404);
        }

        // ---- اعتبارسنجی کامل قبل از mutation ----
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $timezone = is_string($input['timezone'] ?? null) ? trim($input['timezone']) : '';

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'نام شعبه الزامی است';
        } elseif (mb_strlen($name) > 190) {
            $errors['name'] = 'نام شعبه حداکثر ۱۹۰ کاراکتر است';
        }

        if ($timezone === '') {
            $errors['timezone'] = 'منطقهٔ زمانی شعبه الزامی است';
        } elseif (!in_array($timezone, timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'منطقهٔ زمانی باید شناسهٔ معتبر IANA باشد';
        }

        if ($errors !== []) {
            throw LocationException::of(LocationException::VALIDATION, 'اعتبارسنجی محل انجام نشد', 422, ['fields' => $errors]);
        }

        // ---- mutation: فقط name/timezone/updated_at ----
        $beforeName = (string) ($current['name'] ?? '');
        $beforeTimezone = (string) ($current['timezone'] ?? '');

        $now = $this->db->nowUtcSql();

        try {
            $this->db->transactional(
                fn (): int => $this->locations->updateProfile($locationId, $name, $timezone, $now)
            );
        } catch (RuntimeException $e) {
            throw LocationException::of(LocationException::QUERY_FAILED, 'خطای پایگاه داده هنگام به‌روزرسانی محل', 500, ['reason' => $e->getMessage()]);
        }

        $row = $this->readBack($locationId);

        // ---- ستون‌های محافظت‌شده باید دست‌نخورده باشند ----
        if ((int) ($row['clinic_id'] ?? 0) !== (int) ($current['clinic_id'] ?? 0)
            || (string) ($row['slug'] ?? '') !== (string) ($current['slug'] ?? '')
            || (int) ($row['is_primary'] ?? 0) !== (int) ($current['is_primary'] ?? 0)
            || (int) ($row['is_active'] ?? 0) !== (int) ($current['is_active'] ?? 0)
            || (string) ($row['created_at'] ?? '') !== (string) ($current['created_at'] ?? '')
        ) {
            throw LocationException::of(LocationException::QUERY_FAILED, 'ستون‌های محافظت‌شدهٔ محل تغییر کرد', 500);
        }

        $this->audit(
            'LOCATION_UPDATED',
            $actorUserId,
            $locationId,
            $trustedClinicId,
            [
                'location.name' => $beforeName,
                'location.timezone' => $beforeTimezone,
            ],
            [
                'location.name' => $name,
                'location.timezone' => $timezone,
                'location.updated_at' => (string) ($row['updated_at'] ?? $now),
            ],
            ['op' => 'location_update']
        );

        return $this->resultRow($row, false);
    }

    /**
     * گارد مشترک: actor معتبر + trusted Clinic صریح + CONFIG scoped.
     *
     * @throws LocationException
     */
    private function guardActorAndScope(int $actorUserId, int $trustedClinicId): void
    {
        if ($actorUserId <= 0) {
            throw LocationException::of(LocationException::AUTH_REQUIRED, 'احراز هویت لازم است', 401);
        }
        if ($trustedClinicId <= 0) {
            throw LocationException::of(LocationException::SCOPE_REQUIRED, 'زمینه کلینیک معتبر لازم است', 400);
        }

        try {
            $this->authz->authorize($actorUserId, $trustedClinicId, RolesAndCapabilities::CONFIG);
        } catch (AuthorizationException $e) {
            $code = $e->getErrorCode();
            $http = $e->getCode() > 0 ? (int) $e->getCode() : 403;
            if ($code === 'AUTH_UNAUTHENTICATED') {
                throw LocationException::of(LocationException::AUTH_REQUIRED, 'احراز هویت لازم است', 401, ['reason' => $code]);
            }
            throw LocationException::of(
                LocationException::PERMISSION_DENIED,
                'دسترسی لازم را ندارید',
                $http,
                ['reason' => $code, 'clinic_id' => $trustedClinicId]
            );
        }
    }

    /**
     * @return array<string,mixed>
     *
     * @throws LocationException
     */
    private function readBack(int $locationId): array
    {
        try {
            $row = $this->locations->find($locationId);
        } catch (RuntimeException $e) {
            throw LocationException::of(LocationException::QUERY_FAILED, 'خطای پایگاه داده', 500, ['reason' => $e->getMessage()]);
        }

        if ($row === null) {
            throw LocationException::of(LocationException::QUERY_FAILED, 'محل پس از ذخیره قابل خواندن نبود', 500);
        }

        return $row;
    }

    /**
     * ثبت Audit با trusted clinic_id صریح؛ شکست Audit موفقیت write را برنمی‌گرداند
     * اما بی‌صدا هم نمی‌ماند (Operational Log).
     *
     * @param array<string,mixed>|null $before
     * @param array<string,mixed> $after
     */
    private function audit(
        string $action,
        int $actorUserId,
        int $locationId,
        int $trustedClinicId,
        ?array $before,
        array $after,
        array $meta
    ): void {
        try {
            $this->audit->log(
                $action,
                ['wp_user_id' => $actorUserId],
                'location',
                $locationId,
                null,
                $before,
                $after,
                $meta,
                $trustedClinicId
            );
        } catch (\Throwable $e) {
            try {
                \ClinicCore\Bootstrap\App::op()->warning(
                    'LOCATION_AUDIT_FAILED',
                    ['clinic_id' => $trustedClinicId, 'location_id' => $locationId, 'error' => $e->getMessage()]
                );
            } catch (\Throwable) {
                // ignore — شکست دوبارهٔ logger نباید مسیر موفق را شکست بدهد
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array{location_id:int, clinic_id:int, name:string, slug:string, timezone:string, is_primary:int, is_active:int, created_at:string, updated_at:string, noop:bool}
     */
    private function resultRow(array $row, bool $noop): array
    {
        return [
            'location_id' => (int) ($row['id'] ?? 0),
            'clinic_id' => (int) ($row['clinic_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'timezone' => (string) ($row['timezone'] ?? ''),
            'is_primary' => (int) ($row['is_primary'] ?? 0),
            'is_active' => (int) ($row['is_active'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'noop' => $noop,
        ];
    }

    /**
     * slug با کانوانسیون WordPress: trim → فاصله‌ها به خط تیره → sanitize_key.
     */
    private static function sanitizeSlug(string $raw): string
    {
        return sanitize_key(str_replace(' ', '-', trim($raw)));
    }
}
