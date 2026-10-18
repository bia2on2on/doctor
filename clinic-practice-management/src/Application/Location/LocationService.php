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
 * Trusted Location master-data writes — CREATE, profile UPDATE and lifecycle with atomic audit.
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
 * - UPDATE فقط name/address/phone/timezone/updated_at را تغییر می‌دهد؛ clinic_id/slug/
 *   is_primary/is_active/created_at حفظ می‌شوند.
 * - Location خارجی و ناموجود denial یکسان می‌گیرند (enumeration parity).
 * - Authorization پیش از validation و write؛ کلینیک/Location برای write قفل می‌شوند.
 * - Audit هش‌زنجیره‌ای و mutation در یک تراکنش‌اند؛ شکست Audit fail-closed و rollback می‌کند.
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
     * @param array<string,mixed> $input {name, slug, address?, phone?, timezone}
     * @return array{location_id:int, clinic_id:int, name:string, slug:string, address:?string, phone:?string, timezone:string, is_primary:int, is_active:int, created_at:string, updated_at:string, noop:bool}
     *
     * @throws LocationException
     */
    public function createLocation( int $actor_user_id, int $trusted_clinic_id, array $input ): array // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy method name: renaming it is a cross-file API change to code outside this PR, so it stays as published.
    {
        // Authorize before examining caller-controlled fields.
        $this->guard_actor_and_scope( $actor_user_id, $trusted_clinic_id );

        try {
            return $this->db->transactional(
                function () use ( $actor_user_id, $trusted_clinic_id, $input ): array {
                    $this->lockClinicContext( $trusted_clinic_id );
                    $this->guard_actor_and_scope( $actor_user_id, $trusted_clinic_id );

                    $name     = is_string( $input['name'] ?? null ) ? trim( $input['name'] ) : '';
                    $slug_raw = is_string( $input['slug'] ?? null ) ? $input['slug'] : '';
                    $slug     = self::sanitize_slug( $slug_raw );
                    $timezone = is_string( $input['timezone'] ?? null ) ? trim( $input['timezone'] ) : '';
                    $raw_address = $input['address'] ?? null;
                    $raw_phone   = $input['phone'] ?? null;
                    $address = is_string( $raw_address ) ? trim( $raw_address ) : null;
                    $phone   = is_string( $raw_phone ) ? trim( $raw_phone ) : null;
                    if ( $address === '' ) {
                        $address = null;
                    }
                    if ( $phone === '' ) {
                        $phone = null;
                    }

                    $errors = [];
                    if ( $name === '' ) {
                        $errors['name'] = 'نام شعبه الزامی است';
                    } elseif ( mb_strlen( $name ) > 190 ) {
                        $errors['name'] = 'نام شعبه حداکثر ۱۹۰ کاراکتر است';
                    }
                    if ( $slug === '' ) {
                        $errors['slug'] = 'شناسهٔ (slug) شعبه الزامی است';
                    } elseif ( mb_strlen( $slug ) > 190 ) {
                        $errors['slug'] = 'شناسهٔ (slug) شعبه حداکثر ۱۹۰ کاراکتر است';
                    }
                    if ( $timezone === '' ) {
                        $errors['timezone'] = 'منطقهٔ زمانی شعبه الزامی است';
                    } elseif ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
                        $errors['timezone'] = 'منطقهٔ زمانی باید شناسهٔ معتبر IANA باشد';
                    }
                    if ( $raw_address !== null && ! is_string( $raw_address ) ) {
                        $errors['address'] = 'آدرس باید متن باشد';
                    } elseif ( $address !== null && mb_strlen( $address ) > 255 ) {
                        $errors['address'] = 'آدرس حداکثر ۲۵۵ کاراکتر است';
                    }
                    if ( $raw_phone !== null && ! is_string( $raw_phone ) ) {
                        $errors['phone'] = 'تلفن باید متن باشد';
                    } elseif ( $phone !== null && mb_strlen( $phone ) > 32 ) {
                        $errors['phone'] = 'تلفن حداکثر ۳۲ کاراکتر است';
                    }
                    if ( $errors !== [] ) {
                        throw LocationException::of( LocationException::VALIDATION, 'اعتبارسنجی محل انجام نشد', 422, ['fields' => $errors] );
                    }

                    if ( $this->locations->findBySlug( $trusted_clinic_id, $slug ) !== null ) {
                        throw LocationException::of(
                            LocationException::CONFLICT,
                            'شناسهٔ (slug) شعبه در همین Clinic قبلاً استفاده شده است',
                            409,
                            ['field' => 'slug']
                        );
                    }

                    $now = $this->db->nowUtcSql();
                    $location_id = $this->locations->create(
                        $trusted_clinic_id,
                        $name,
                        $slug,
                        $address,
                        $phone,
                        $timezone,
                        $now
                    );
                    $row = $this->read_back( $location_id );

                    if ( (int) ( $row['clinic_id'] ?? 0 ) !== $trusted_clinic_id
                        || (string) ( $row['name'] ?? '' ) !== $name
                        || (string) ( $row['slug'] ?? '' ) !== $slug
                        || self::nullableValue( $row['address'] ?? null ) !== $address
                        || self::nullableValue( $row['phone'] ?? null ) !== $phone
                        || (string) ( $row['timezone'] ?? '' ) !== $timezone
                        || (int) ( $row['is_primary'] ?? 1 ) !== 0
                        || (int) ( $row['is_active'] ?? 0 ) !== 1
                    ) {
                        throw LocationException::of( LocationException::QUERY_FAILED, 'ایناریانت‌های ساخت محل نقض شد', 500 );
                    }

                    // Audit is part of the same transaction. Failure throws and rolls back both rows.
                    $this->audit->log(
                        'LOCATION_CREATED',
                        ['wp_user_id' => $actor_user_id],
                        'location',
                        $location_id,
                        null,
                        null,
                        [
                            'name' => $name,
                            'slug' => $slug,
                            'address' => $address,
                            'phone' => $phone,
                            'timezone' => $timezone,
                            'clinic_id' => $trusted_clinic_id,
                            'is_primary' => 0,
                            'is_active' => 1,
                        ],
                        ['op' => 'location_create'],
                        $trusted_clinic_id
                    );

                    return $this->result_row( $row, false );
                }
            );
        } catch ( LocationException $e ) {
            throw $e;
        } catch ( \Throwable $e ) {
            // Includes SQL and audit persistence failures; transaction has rolled back.
            throw LocationException::of( LocationException::QUERY_FAILED, 'خطای پایگاه داده هنگام ساخت محل', 500, ['reason' => $e->getMessage()] );
        }
    }

    /**
     * UPDATE — تغییر name/address/phone/timezone فقط در Location همان Clinic.
     * Audit در همان تراکنش نوشته می‌شود و هر شکست، write را rollback می‌کند.
     *
     * @param array<string,mixed> $input {name, address, phone, timezone}
     * @return array{location_id:int, clinic_id:int, name:string, slug:string, address:?string, phone:?string, timezone:string, is_primary:int, is_active:int, created_at:string, updated_at:string, noop:bool}
     * @throws LocationException
     */
    public function updateLocation( int $actor_user_id, int $trusted_clinic_id, int $location_id, array $input ): array // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy method name: renaming it is a cross-file API change to code outside this PR, so it stays as published.
    {
        $this->guard_actor_and_scope( $actor_user_id, $trusted_clinic_id );

        try {
            return $this->db->transactional(
                function () use ( $actor_user_id, $trusted_clinic_id, $location_id, $input ): array {
                    $this->lockClinicContext( $trusted_clinic_id );
                    $this->guard_actor_and_scope( $actor_user_id, $trusted_clinic_id );

                    $current = $location_id > 0 ? $this->locations->find_for_update( $location_id, $trusted_clinic_id ) : null;
                    if ( $current === null ) {
                        // Missing and cross-Clinic identifiers have identical output.
                        throw LocationException::of( LocationException::NOT_FOUND, 'محل موردنظر یافت نشد', 404 );
                    }
                    if ( (int) ( $current['is_primary'] ?? 0 ) === 1 ) {
                        throw LocationException::of( LocationException::LIFECYCLE_CONFLICT, 'ویرایش محل اصلی از این صفحه مجاز نیست.', 409 );
                    }

                    $name     = is_string( $input['name'] ?? null ) ? trim( $input['name'] ) : '';
                    $timezone = is_string( $input['timezone'] ?? null ) ? trim( $input['timezone'] ) : '';
                    $raw_address = array_key_exists( 'address', $input ) ? $input['address'] : ( $current['address'] ?? null );
                    $raw_phone   = array_key_exists( 'phone', $input ) ? $input['phone'] : ( $current['phone'] ?? null );
                    $address = is_string( $raw_address ) ? trim( $raw_address ) : null;
                    $phone   = is_string( $raw_phone ) ? trim( $raw_phone ) : null;
                    if ( $address === '' ) {
                        $address = null;
                    }
                    if ( $phone === '' ) {
                        $phone = null;
                    }

                    $errors = [];
                    if ( $name === '' ) {
                        $errors['name'] = 'نام شعبه الزامی است';
                    } elseif ( mb_strlen( $name ) > 190 ) {
                        $errors['name'] = 'نام شعبه حداکثر ۱۹۰ کاراکتر است';
                    }
                    if ( $timezone === '' ) {
                        $errors['timezone'] = 'منطقهٔ زمانی شعبه الزامی است';
                    } elseif ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
                        $errors['timezone'] = 'منطقهٔ زمانی باید شناسهٔ معتبر IANA باشد';
                    }
                    if ( $raw_address !== null && ! is_string( $raw_address ) ) {
                        $errors['address'] = 'آدرس باید متن باشد';
                    } elseif ( $address !== null && mb_strlen( $address ) > 255 ) {
                        $errors['address'] = 'آدرس حداکثر ۲۵۵ کاراکتر است';
                    }
                    if ( $raw_phone !== null && ! is_string( $raw_phone ) ) {
                        $errors['phone'] = 'تلفن باید متن باشد';
                    } elseif ( $phone !== null && mb_strlen( $phone ) > 32 ) {
                        $errors['phone'] = 'تلفن حداکثر ۳۲ کاراکتر است';
                    }
                    if ( $errors !== [] ) {
                        throw LocationException::of( LocationException::VALIDATION, 'اعتبارسنجی محل انجام نشد', 422, ['fields' => $errors] );
                    }

                    $before = [
                        'name' => (string) ( $current['name'] ?? '' ),
                        'address' => self::nullableValue( $current['address'] ?? null ),
                        'phone' => self::nullableValue( $current['phone'] ?? null ),
                        'timezone' => (string) ( $current['timezone'] ?? '' ),
                    ];
                    $after = [
                        'name' => $name,
                        'address' => $address,
                        'phone' => $phone,
                        'timezone' => $timezone,
                    ];
                    if ( $before === $after ) {
                        return $this->result_row( $current, true );
                    }

                    $now = $this->db->nowUtcSql();
                    $changed = $this->locations->updateProfile(
                        $location_id,
                        $trusted_clinic_id,
                        $name,
                        $address,
                        $phone,
                        $timezone,
                        $now
                    );
                    if ( $changed !== 1 ) {
                        throw LocationException::of( LocationException::QUERY_FAILED, 'تغییرات محل ثبت نشد.', 409 );
                    }

                    $row = $this->read_back( $location_id );
                    if ( (int) ( $row['clinic_id'] ?? 0 ) !== (int) ( $current['clinic_id'] ?? 0 )
                        || (string) ( $row['slug'] ?? '' ) !== (string) ( $current['slug'] ?? '' )
                        || (int) ( $row['is_primary'] ?? 0 ) !== (int) ( $current['is_primary'] ?? 0 )
                        || (int) ( $row['is_active'] ?? 0 ) !== (int) ( $current['is_active'] ?? 0 )
                        || (string) ( $row['created_at'] ?? '' ) !== (string) ( $current['created_at'] ?? '' )
                        || (string) ( $row['name'] ?? '' ) !== $name
                        || self::nullableValue( $row['address'] ?? null ) !== $address
                        || self::nullableValue( $row['phone'] ?? null ) !== $phone
                        || (string) ( $row['timezone'] ?? '' ) !== $timezone
                    ) {
                        throw LocationException::of( LocationException::QUERY_FAILED, 'ستون‌های محل پس از ذخیره با انتظار سازگار نبودند.', 500 );
                    }

                    $this->audit->log(
                        'LOCATION_UPDATED',
                        ['wp_user_id' => $actor_user_id],
                        'location',
                        $location_id,
                        null,
                        $before,
                        $after,
                        ['op' => 'location_update'],
                        $trusted_clinic_id
                    );

                    return $this->result_row( $row, false );
                }
            );
        } catch ( LocationException $e ) {
            throw $e;
        } catch ( \Throwable $e ) {
            // Audit failure is intentionally not suppressed; the transaction rolls back.
            throw LocationException::of( LocationException::QUERY_FAILED, 'خطای پایگاه داده هنگام به‌روزرسانی محل', 500, ['reason' => $e->getMessage()] );
        }
    }

    /**
     * Change active state without deleting history. The Clinic lock serializes
     * lifecycle writes and protects the final-active-Location invariant.
     * The primary Location cannot be deactivated.
     *
     * @return array{location_id:int, clinic_id:int, name:string, slug:string, address:?string, phone:?string, timezone:string, is_primary:int, is_active:int, created_at:string, updated_at:string, noop:bool}
     * @throws LocationException
     */
    public function set_active( int $actor_user_id, int $trusted_clinic_id, int $location_id, bool $active ): array {

        $this->guard_actor_and_scope( $actor_user_id, $trusted_clinic_id );
        if ( $location_id <= 0 ) {
            throw LocationException::of( LocationException::NOT_FOUND, 'محل موردنظر یافت نشد', 404 );
        }

        try {
            return $this->db->transactional(
                function () use ( $actor_user_id, $trusted_clinic_id, $location_id, $active ): array {
                    $this->lockClinicContext( $trusted_clinic_id );
                    $this->guard_actor_and_scope( $actor_user_id, $trusted_clinic_id );

                    $current = $this->locations->find_for_update( $location_id, $trusted_clinic_id );
                    if ( $current === null ) {
                        throw LocationException::of( LocationException::NOT_FOUND, 'محل موردنظر یافت نشد', 404 );
                    }
                    $was_active = (int) $current['is_active'] === 1;
                    if ( $was_active === $active ) {
                        return $this->result_row( $current, true );
                    }
                    if ( ! $active && (int) $current['is_primary'] === 1 ) {
                        throw LocationException::of(
                            LocationException::LIFECYCLE_CONFLICT,
                            'محل اصلی قابل غیرفعال‌سازی نیست.',
                            409
                        );
                    }
                    if ( ! $active && $this->locations->active_count_for_clinic( $trusted_clinic_id ) <= 1 ) {
                        throw LocationException::of(
                            LocationException::LIFECYCLE_CONFLICT,
                            'حداقل یک محل فعال برای Clinic لازم است.',
                            409
                        );
                    }

                    $now = $this->db->nowUtcSql();
                    $changed = $this->locations->set_active(
                        $location_id,
                        $trusted_clinic_id,
                        $active,
                        $was_active ? 1 : 0,
                        $now
                    );
                    if ( $changed !== 1 ) {
                        throw LocationException::of( LocationException::QUERY_FAILED, 'تغییر وضعیت محل ثبت نشد.', 409 );
                    }

                    $updated = $this->locations->find_for_update( $location_id, $trusted_clinic_id );
                    if ( $updated === null || (int) $updated['is_active'] !== ( $active ? 1 : 0 ) ) {
                        throw LocationException::of( LocationException::QUERY_FAILED, 'وضعیت محل پس از ذخیره قابل تأیید نبود.', 500 );
                    }

                    // Hash-chained audit insertion shares the lifecycle transaction.
                    $this->audit->log(
                        $active ? 'LOCATION_REACTIVATED' : 'LOCATION_DEACTIVATED',
                        ['wp_user_id' => $actor_user_id],
                        'location',
                        $location_id,
                        null,
                        ['is_active' => $was_active ? 1 : 0],
                        ['is_active' => $active ? 1 : 0],
                        ['op' => $active ? 'location_reactivate' : 'location_deactivate'],
                        $trusted_clinic_id
                    );

                    return $this->result_row( $updated, false );
                }
            );
        } catch ( LocationException $e ) {
            throw $e;
        } catch ( \Throwable $e ) {
            throw LocationException::of( LocationException::QUERY_FAILED, 'خطای پایگاه داده هنگام تغییر وضعیت محل', 500, ['reason' => $e->getMessage()] );
        }
    }

    /**
     * Lock Location write context in the shared Organization → Clinic order.
     * The unlocked Organization id is only a candidate and is rechecked after
     * both durable rows have been locked.
     */
    private function lockClinicContext( int $clinic_id ): void
    {
        $candidate = $this->db->fetchRow(
            'SELECT organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [$clinic_id]
        );
        if ( '' !== $this->db->wpdb()->last_error ) {
            throw new RuntimeException( 'location Clinic candidate query failed' );
        }
        $organization_id = (int) ( $candidate['organization_id'] ?? 0 );
        if ( $candidate === null || $organization_id <= 0 ) {
            throw LocationException::of( LocationException::NOT_FOUND, 'Clinic یافت نشد', 404 );
        }

        $organization = $this->db->fetchRowForUpdate(
            'SELECT id, status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d LIMIT 1',
            [$organization_id]
        );
        if ( '' !== $this->db->wpdb()->last_error ) {
            throw new RuntimeException( 'location Organization lock query failed' );
        }
        if ( $organization === null || (int) ( $organization['id'] ?? 0 ) !== $organization_id || (string) ( $organization['status'] ?? '' ) !== 'active' ) {
            throw LocationException::of( LocationException::NOT_FOUND, 'Clinic یافت نشد', 404 );
        }

        $clinic = $this->db->fetchRowForUpdate(
            'SELECT id, organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [$clinic_id]
        );
        if ( '' !== $this->db->wpdb()->last_error ) {
            throw new RuntimeException( 'location Clinic lock query failed' );
        }
        if ( $clinic === null || (int) ( $clinic['id'] ?? 0 ) !== $clinic_id || (int) ( $clinic['organization_id'] ?? 0 ) !== $organization_id ) {
            throw LocationException::of( LocationException::NOT_FOUND, 'Clinic یافت نشد', 404 );
        }
    }

    private static function nullableValue( mixed $value ): ?string
    {
        if ( $value === null ) {
            return null;
        }
        $value = (string) $value;
        return $value === '' ? null : $value;
    }

    private function guard_actor_and_scope( int $actor_user_id, int $trusted_clinic_id ): void
    {
        if ( $actor_user_id <= 0 ) {
            throw LocationException::of( LocationException::AUTH_REQUIRED, 'احراز هویت لازم است', 401 );
        }
        if ( $trusted_clinic_id <= 0 ) {
            throw LocationException::of( LocationException::SCOPE_REQUIRED, 'زمینه کلینیک معتبر لازم است', 400 );
        }

        try {
            $this->authz->authorize( $actor_user_id, $trusted_clinic_id, RolesAndCapabilities::CONFIG );
        } catch ( AuthorizationException $e ) {
            $code = $e->getErrorCode();
            $http = $e->getCode() > 0 ? (int) $e->getCode() : 403;
            if ( $code === 'AUTH_UNAUTHENTICATED' ) {
                throw LocationException::of( LocationException::AUTH_REQUIRED, 'احراز هویت لازم است', 401, ['reason' => $code] );
            }
            throw LocationException::of(
                LocationException::PERMISSION_DENIED,
                'دسترسی لازم را ندارید',
                $http,
                ['reason' => $code, 'clinic_id' => $trusted_clinic_id]
            );
        }
    }

    /**
     * @return array<string,mixed>
     *
     * @throws LocationException
     */
    private function read_back( int $location_id ): array
    {
        try {
            $row = $this->locations->find( $location_id );
        } catch ( RuntimeException $e ) {
            throw LocationException::of( LocationException::QUERY_FAILED, 'خطای پایگاه داده', 500, ['reason' => $e->getMessage()] );
        }

        if ( $row === null ) {
            throw LocationException::of( LocationException::QUERY_FAILED, 'محل پس از ذخیره قابل خواندن نبود', 500 );
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array{location_id:int, clinic_id:int, name:string, slug:string, address:?string, phone:?string, timezone:string, is_primary:int, is_active:int, created_at:string, updated_at:string, noop:bool}
     */
    private function result_row( array $row, bool $noop ): array
    {
        return [
            'location_id' => (int) ($row['id'] ?? 0),
            'clinic_id' => (int) ($row['clinic_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'address' => self::nullableValue( $row['address'] ?? null ),
            'phone' => self::nullableValue( $row['phone'] ?? null ),
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
    private static function sanitize_slug( string $raw ): string
    {
        return sanitize_key( str_replace( ' ', '-', trim( $raw ) ) );
    }
}
