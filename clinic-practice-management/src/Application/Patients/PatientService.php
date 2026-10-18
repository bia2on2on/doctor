<?php

declare(strict_types=1);

namespace ClinicCore\Application\Patients;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Patients\PatientIdentityException;
use ClinicCore\Domain\Validators\MobileValidator;
use ClinicCore\Domain\Validators\NationalIdValidator;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Repository\PatientIdentityRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;

/**
 * سرویس بیمار (F3) — C0–C2 (بیمار) + D2–D5 (منشی).
 *
 * Authorization: Capability Check در لایه REST (RestBase)؛ این Service فرض
 * می‌کند Call-site مجاز است و فقط **Data-Access و Validation** را اعمال می‌کند
 * (لایه ۳ از ۵ — docs/security/auth-authorization.md §2.1).
 *
 * PHI: Audit = قبل/بعد کامل (حافظه Audit — ADR-0008)؛ OpLog = فقط id + mask.
 */
final class PatientService
{
    /**
     * فیلدهای ویرایشی توسط خود بیمار (C2) — Mobile به‌عنوان Identity تغییر نمی‌کند.
     */
    public const ME_EDITABLE = [
        'first_name', 'last_name', 'birth_date', 'gender', 'address', 'phone',
        'national_id', 'emergency_contact_name', 'emergency_contact_phone',
    ];

    /**
     * فیلدهای ویرایشی منشی (D5) — شامل فیلدهای بالینی.
     */
    public const STAFF_EDITABLE = [
        'first_name', 'last_name', 'birth_date', 'gender', 'address', 'phone',
        'national_id', 'emergency_contact_name', 'emergency_contact_phone',
        'blood_group', 'medication_allergies', 'other_allergies', 'chronic_conditions',
        'medical_history', 'surgery_history', 'current_medications', 'status',
    ];

    /**
     * فیلدهای ساخت (D4).
     */
    public const CREATE_FIELDS = [
        'first_name', 'last_name', 'mobile', 'national_id', 'birth_date', 'gender',
        'address', 'phone', 'emergency_contact_name', 'emergency_contact_phone',
        'blood_group', 'medication_allergies', 'other_allergies', 'chronic_conditions',
        'medical_history', 'surgery_history', 'current_medications',
    ];

    private const JSON_FIELDS = [
        'medication_allergies', 'other_allergies', 'chronic_conditions', 'current_medications',
    ];

    /**
     * فعال‌سازی هویتِ سازمانی در مسیرِ ساختِ بیمار. اختیاری‌بودنِ پارامتر فقط
     * برای حفظِ سازگاریِ call-siteهای موجود است؛ در نبودِ آن یک نمونهٔ
     * پیش‌فرضِ scope-neutral از همان اتصالِ DB ساخته می‌شود (هرگز غیرفعال
     * نمی‌شود — فعال‌سازی هویت اختیاری نیست).
     */
    private readonly PatientIdentityActivator $identities;

    public function __construct(
        private readonly CpmsDb $db,
        private readonly PatientRepository $patients,
        private readonly LicenseGate $licenseGate,
        private readonly AuditLogger $audit,
        private readonly OpLogger $op,
        ?PatientIdentityActivator $identities = null
    ) {
        $this->identities = $identities ?? self::default_identity_activator( $db );
    }

    /**
     * سازندهٔ پیش‌فرضِ فعال‌سازِ هویت — بدون وابستگی به Scope/Settings.
     */
    public static function default_identity_activator( CpmsDb $db ): PatientIdentityActivator {
        $repository = new PatientIdentityRepository( $db );

        return new PatientIdentityActivator(
            $repository,
            new PatientIdentityService( $db, $repository, new AuditLogger( $db, new OpLogger( $db ) ) )
        );
    }

    // ================= C0/C1 — Me =================

    /**
     * @return list<array<string, mixed>>
     */
    public function linked_records( int $wp_user_id ): array {
        return array_map(
            static fn ( array $row ): array => [
                'link_id'              => (int) $row['link_id'],
                'clinic_id'            => (int) $row['clinic_id'],
                'clinic_name'          => (string) $row['clinic_name'],
                'patient_id'           => (int) $row['patient_id'],
                'patient_display_name' => trim( (string) $row['first_name'] . ' ' . (string) $row['last_name'] ),
                'mrn'                  => (string) $row['mrn'],
                'is_primary'           => (bool) $row['is_primary'],
            ],
            $this->patients->active_linked_records_for_user( $wp_user_id )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function me( int $wp_user_id, ?int $link_id = null ): array {
        return $this->publicView( $this->require_selected_patient( $wp_user_id, $link_id ) );
    }

    // ================= C2 — Update Me =================

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Established public service API; renaming would break callers.
    public function updateMe( int $wp_user_id, array $fields, ?int $link_id = null ): array {
        $current = $this->require_selected_patient( $wp_user_id, $link_id );
        $data    = $this->validateForUpdate( $fields, self::ME_EDITABLE, (int) $current['id'] );

        if ( $data === [] ) {
            throw new BookingException( 'CLINIC_VALIDATION_FAILED', 'فیلدی برای ویرایش ارسال نشده است' );
        }

        if ( isset( $data['national_id'] ) ) {
            $other = $this->patients->find_by_national_id( (int) $current['clinic_id'], (string) $data['national_id'] );
            if ( $other !== null && (int) $other['id'] !== (int) $current['id'] ) {
                throw new BookingException( 'CLINIC_VALIDATION_FAILED', 'این کد ملی متعلق به بیمار دیگری است' );
            }
        }

        $this->patients->update( (int) $current['id'], $data + [ 'updated_at' => $this->db->nowUtcSql() ] );
        $this->assert_profile_write_succeeded( $current, $data );
        $updated = (array) $this->patients->find( (int) $current['id'] );

        $this->audit->log(
            'PATIENT_PROFILE_UPDATED',
            [ 'wp_user_id' => $wp_user_id, 'role' => 'patient' ],
            'patient',
            (int) $current['id'],
            (int) $current['id'],
            $this->diffView( $current, $data ),
            $this->diffView( $updated, $data ),
            [ 'via' => 'self_service' ]
        );
        $this->op->info( 'patient.profile_updated', [ 'patient_id' => (int) $current['id'], 'fields' => array_keys( $data ) ] );

        return $this->publicView( $updated );
    }

    /**
     * CpmsDb::update() normalizes wpdb's false failure and 0-row no-op to 0.
     * The immediate wpdb error state is therefore the bounded failure signal:
     * zero affected rows with no SQL error remains a valid idempotent save.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $data
     */
    private function assert_profile_write_succeeded( array $current, array $data ): void {
        if ( $this->db->wpdb()->last_error === '' ) {
            return;
        }

        if ( isset( $data['national_id'] ) ) {
            $other = $this->patients->find_by_national_id(
                (int) $current['clinic_id'],
                (string) $data['national_id']
            );
            if ( $other !== null && (int) $other['id'] !== (int) $current['id'] ) {
                throw new BookingException(
                    'CLINIC_VALIDATION_FAILED',
                    'این کد ملی متعلق به بیمار دیگری است'
                );
            }
        }

        throw new BookingException(
            'CLINIC_INTERNAL_ERROR',
            'ذخیره تغییرات پروفایل انجام نشد',
            500
        );
    }

    /**
     * Resolves exactly one active Patient from the authenticated user's durable
     * matching link and persisted Clinic relationship.
     *
     * @return array<string, mixed>
     */
    public function require_selected_patient( int $wp_user_id, ?int $link_id = null ): array {
        if ( $link_id !== null ) {
            $selected = $this->patients->find_active_linked_patient_by_link( $wp_user_id, $link_id );
            if ( $selected === null ) {
                throw $this->linked_patient_not_found();
            }

            return $selected;
        }

        $candidates = $this->patients->active_linked_patients_for_user( $wp_user_id );
        if ( $candidates === [] ) {
            throw $this->linked_patient_not_found();
        }
        if ( count( $candidates ) > 1 ) {
            throw new BookingException( 'CLINIC_SELECTION_REQUIRED', 'انتخاب پرونده بیمار الزامی است', 422 );
        }

        return $candidates[0];
    }

    // ================= D2 — Search (Secretary) =================

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $q, int $limit = 25): array
    {
        $q = trim($q);
        if ($q === '' || mb_strlen($q) < 2) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'جستجو باید حداقل ۲ کاراکتر باشد');
        }
        $limit = max(1, min(50, $limit));

        return array_map(
            fn (array $r): array => $this->searchView($r),
            $this->patients->search(App::scope()->clinicId, $q, $limit)
        );
    }

    // ================= D3 — Get (Secretary) =================

    /**
     * @return array<string, mixed>
     */
    public function get(int $patientId): array
    {
        $row = $this->patients->find($patientId);
        if ($row === null || (int) $row['clinic_id'] !== App::scope()->clinicId) {
            // C6: بیمار کلینیک دیگر = مثل نبودن (anti-enum)
            throw new BookingException('CLINIC_NOT_FOUND', 'بیمار یافت نشد', 404);
        }

        return $this->staffView((array) $row);
    }

    /**
     * Patient-self enrollment in a destination Clinic, including its first local
     * record. Session subject + explicit Organization link are the authority;
     * supplied identity/user IDs and demographics are never identity selectors.
     *
     * @param array<string, mixed> $fields New local data, never copied from another Clinic.
     * @return array<string, mixed>
     */
    public function enroll_current_patient( int $clinic_id, array $fields ): array {
        $user = wp_get_current_user();
        if ( ! $user->exists() || ! in_array( \ClinicCore\Auth\RolesAndCapabilities::ROLE_PATIENT, (array) $user->roles, true ) ) {
            throw new BookingException( 'CLINIC_PERMISSION_DENIED', 'دسترسی ندارید', 403 );
        }
        $previous = \ClinicCore\Application\Scope\ScopeContext::tryGet();
        try {
            return $this->db->transactional(
                function () use ( $clinic_id, $fields, $user ): array {
                    $clinic = $this->db->fetchRowForUpdate(
                        // Clinic lifecycle policy: a suspended Clinic accepts no NEW
                        // self-enrollment. Checked on the same Clinic row lock that
                        // serializes the enrollment, so a suspension racing with this
                        // request is ordered by InnoDB and the final state is revalidated.
                        // 404 parity with an unlinked Clinic (no existence oracle).
                        'SELECT clinic.id, clinic.organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' clinic' .
                        ' INNER JOIN ' . $this->db->table( 'cpms_organizations' ) . ' organization ON organization.id = clinic.organization_id' .
                        ' WHERE clinic.id = %d AND organization.status = %s AND clinic.status = %s',
                        [ $clinic_id, 'active', 'active' ]
                    );
                    if ( $clinic === null ) {
                        throw $this->linked_patient_not_found();
                    }
                    // Serialize verification and enrollment with changes to this
                    // user's Organization links; ambiguity never becomes a fallback grant.
                    $this->db->fetchAll(
                        'SELECT id FROM ' . $this->db->table( 'cpms_patient_identity_links' ) .
                        ' WHERE organization_id = %d AND wp_user_id = %d FOR UPDATE',
                        [ (int) $clinic['organization_id'], (int) $user->ID ]
                    );
                    if ( $this->db->wpdb()->last_error !== '' ) {
                        throw new BookingException( 'CLINIC_INTERNAL_ERROR', 'ثبت بیمار انجام نشد', 500 );
                    }
                    $identity = $this->identities->verified_identity_for_clinic( $clinic_id, (int) $user->ID );
                    if ( $identity === null ) {
                        throw $this->linked_patient_not_found();
                    }
                    $existing = $this->db->fetchValue(
                        'SELECT id FROM ' . $this->db->table( 'cpms_patients' ) . ' WHERE clinic_id = %d AND identity_id = %d LIMIT 1',
                        [ $clinic_id, $identity ]
                    );
                    if ( $this->db->wpdb()->last_error !== '' ) {
                        throw new BookingException( 'CLINIC_INTERNAL_ERROR', 'ثبت بیمار انجام نشد', 500 );
                    }
                    if ( $existing !== null ) {
                        throw new BookingException( 'CLINIC_VALIDATION_FAILED', 'پرونده در این کلینیک موجود است', 400 );
                    }
                    App::replaceExplicitScope( \ClinicCore\Application\Scope\ClinicScope::forClinic( $clinic_id )->withOrganization( (int) $clinic['organization_id'] ) );
                    // Only new, self-supplied local demographics; no import of
                    // clinical history, no caller-selected subject or identity.
                    $local_fields = array_intersect_key( $fields, array_flip( [ 'first_name', 'last_name', 'mobile' ] ) );
                    $created      = $this->create_record( $local_fields, (int) $user->ID, (int) $user->ID, $identity );
                    return [ 'patient_id' => (int) $created['id'], 'clinic_id' => $clinic_id, 'mrn' => (string) $created['mrn'] ];
                }
            );
        } finally {
            App::replaceExplicitScope( $previous );
        }
    }

    // ================= D4 — Create (Secretary) =================

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function create(array $fields, int $actorUserId): array
    {
        return $this->create_record( $fields, $actorUserId ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Existing public API.
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function create_record( array $fields, int $actor_user_id, ?int $verified_user = null, ?int $expected_identity = null ): array {
        $reception = $verified_user === null && user_can( $actor_user_id, \ClinicCore\Auth\RolesAndCapabilities::PATIENT_CREATE );
        if ( $verified_user === null ) {
            $this->assert_license( LicenseGate::OP_PATIENT_CREATE, [ 'clinic_id' => App::scope()->clinicId, 'reception' => $reception ] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Existing scope value object.
        }

        $firstName = (string) ($fields['first_name'] ?? '');
        $lastName = (string) ($fields['last_name'] ?? '');
        $mobile = MobileValidator::normalize((string) ($fields['mobile'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'نام و نام خانوادگی الزامی است');
        }
        if ($mobile === null) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'شماره موبایل نامعتبر است');
        }
        $clinicId = App::scope()->clinicId;
        if ($this->patients->findByMobile($clinicId, $mobile) !== null) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'بیماری با همین موبایل قبلاً ثبت شده است');
        }

        $data = $this->validateForUpdate($fields, self::CREATE_FIELDS, 0, true) + [
            'clinic_id' => $clinicId,
            'first_name' => mb_substr($firstName, 0, 120),
            'last_name' => mb_substr($lastName, 0, 120),
            'mobile' => $mobile,
            'mrn' => $this->generateMrn($clinicId),
            'status' => 'active',
            'created_at' => $this->db->nowUtcSql(),
            'updated_at' => $this->db->nowUtcSql(),
        ];

        $this->assert_unique_national_id( (int) $data['clinic_id'], isset( $data['national_id'] ) ? (string) $data['national_id'] : null );

        // Organization Patient Identity activation: the clinical row and its
        // organization identity are one atomic unit. A failed activation rolls
        // the patient insert back — no clinical record is ever left without an
        // organization identity on this path (fail-closed).
        $clinic_id = $clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
        $id        = $this->db->transactional(
            function () use ( $data, $clinic_id, $mobile, $verified_user, $expected_identity, $reception ): int {
                $id = $this->patients->create( $data );
                if ( $this->db->wpdb()->last_error !== '' || $id <= 0 ) {
                    // Map the known same-Clinic national-ID uniqueness collision to a
                    // bounded product error. Unrelated SQL failures stay internal.
                    $this->assert_unique_national_id( (int) $data['clinic_id'], isset( $data['national_id'] ) ? (string) $data['national_id'] : null );
                    throw new BookingException( 'CLINIC_INTERNAL_ERROR', 'ثبت بیمار انجام نشد', 500 );
                }

                // Clinic comes from the server-side trusted scope, never from the
                // request body; `identity_id` is not a writable create field, so no
                // caller can choose or inject the identity.
                $identity = $this->activate_identity( $clinic_id, $id, $mobile, $verified_user );
                if ( $verified_user !== null && $identity !== $expected_identity ) {
                    throw $this->linked_patient_not_found();
                }
                // The new destination row now carries the verified Organization
                // identity. Reuse the boolean-only cross-Clinic evidence policy;
                // denial rolls back row, identity edge, and hash-chained audit.
                $this->assert_license(
                    LicenseGate::OP_PATIENT_CREATE,
                    [ 'patient_id' => $id, 'clinic_id' => $clinic_id, 'reception' => $reception ]
                );
                if ( $verified_user !== null ) {
                    $linked = $this->db->insert(
                        'cpms_patient_user_links',
                        [ 'clinic_id' => $clinic_id, 'patient_id' => $id, 'wp_user_id' => $verified_user, 'mobile_at_link' => $mobile, 'is_primary' => 0, 'linked_at' => $this->db->nowUtcSql() ]
                    );
                    if ( ! $linked || $this->db->wpdb()->last_error !== '' ) {
                        throw new BookingException( 'CLINIC_INTERNAL_ERROR', 'ثبت بیمار انجام نشد', 500 );
                    }
                }

                return $id;
            }
        );
        $row       = (array) $this->patients->find( $id );

        $this->audit->log(
            'PATIENT_CREATED',
            [ 'wp_user_id' => $actor_user_id, 'role' => $verified_user === null ? 'staff' : 'patient' ],
            'patient',
            $id,
            $id,
            null,
            $this->staffView($row),
            ['mobile' => MobileValidator::mask($mobile)]
        );
        $this->op->info( 'patient.created', [ 'patient_id' => $id, 'actor' => $actor_user_id, 'mobile' => MobileValidator::mask( $mobile ) ] );

        return $this->staffView($row);
    }

    // ================= D5 — Update (Secretary) =================

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function update(int $patientId, array $fields, int $actorUserId): array
    {
        $this->assert_license( LicenseGate::OP_PATIENT_UPDATE );

        $current = (array) $this->patients->find($patientId);
        if ($current === [] || (int) $current['clinic_id'] !== App::scope()->clinicId) {
            // C6: بیمار کلینیک دیگر = مثل نبودن (anti-enum)
            throw new BookingException('CLINIC_NOT_FOUND', 'بیمار یافت نشد', 404);
        }

        $data = $this->validateForUpdate($fields, self::STAFF_EDITABLE, $patientId);
        if ($data === []) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'فیلدی برای ویرایش ارسال نشده است');
        }

        $this->patients->update($patientId, $data + ['updated_at' => $this->db->nowUtcSql()]);
        $updated = (array) $this->patients->find($patientId);

        $this->audit->log(
            'PATIENT_UPDATED',
            ['wp_user_id' => $actorUserId, 'role' => 'staff'],
            'patient',
            $patientId,
            $patientId,
            $this->diffView($current, $data),
            $this->diffView($updated, $data),
            []
        );
        $this->op->info('patient.updated', ['patient_id' => $patientId, 'actor' => $actorUserId, 'fields' => array_keys($data)]);

        return $this->staffView($updated);
    }

    // ================= Internal =================

    private function linked_patient_not_found(): BookingException {
        return new BookingException( 'CLINIC_NOT_FOUND', 'بیماری به این حساب متصل نیست', 404 );
    }

    /**
     * فعال‌سازی هویتِ سازمانی برای رکوردِ تازه — هیچ استنتاجی از موبایل/کد
     * ملی/نام انجام نمی‌شود؛ مسیرِ کارکنی هیچ «کاربرِ تأییدشده»ای ندارد، پس
     * همیشه هویتِ تازه ساخته می‌شود.
     *
     * خطاهای حوزهٔ هویت به یک خطای محصولِ کراندار نگاشت می‌شوند تا جزئیات
     * داخلی افشا نشود؛ خودِ throw باعثِ rollbackِ تراکنشِ ساخت می‌شود.
     */
    private function activate_identity( int $clinic_id, int $patient_id, string $mobile, ?int $verified_user = null ): int {
        try {
            return $this->identities->activate_for_new_patient( $clinic_id, $patient_id, $mobile, $verified_user );
        } catch ( PatientIdentityException $e ) {
            $this->op->error(
                'patient.identity_activation_failed',
                [
                    'patient_id' => $patient_id,
                    'clinic_id'  => $clinic_id,
                    'error_code' => $e->error_code,
                ]
            );

            throw new BookingException( 'CLINIC_INTERNAL_ERROR', 'ثبت بیمار انجام نشد', 500 );
        }
    }

    private function assert_license( string $operation, array $context = [] ): void
    {
        $decision = $this->licenseGate->assert( $operation, $context ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Existing injected property.
        if (!$decision->allowed) {
            throw new BookingException('CLINIC_LICENSE_BLOCKED', 'سیستم در حالت Read-Only است (مجازت)', 503);
        }
    }

    /**
     * Validate + White-list فیلدها (Mass Assignment Protection در لایه Service + Repository).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function validateForUpdate(array $fields, array $allowed, int $patientId, bool $isCreate = false): array
    {
        $out = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $fields) || $fields[$field] === null || $fields[$field] === '') {
                continue;
            }
            $value = $fields[$field];
            $out[$field] = match ($field) {
                'first_name', 'last_name' => $this->cleanName((string) $value),
                'mobile' => MobileValidator::normalize((string) $value) ?? throw new BookingException('CLINIC_VALIDATION_FAILED', 'شماره موبایل نامعتبر است'),
                'national_id' => $this->cleanNationalId((string) $value),
                'birth_date' => $this->cleanDate((string) $value, true),
                'gender' => $this->cleanEnum((string) $value, ['male', 'female', 'other', 'unknown'], 'gender'),
                'address' => $this->cleanText((string) $value, 255),
                'phone' => $this->cleanPhone((string) $value),
                'emergency_contact_name' => $this->cleanText((string) $value, 190),
                'emergency_contact_phone' => $this->cleanPhone((string) $value),
                'blood_group' => $this->cleanText((string) $value, 8),
                'medical_history', 'surgery_history' => $this->cleanText((string) $value, 10000),
                'medication_allergies', 'other_allergies', 'chronic_conditions', 'current_medications' => $this->cleanJson($value),
                'status' => $this->cleanEnum((string) $value, ['active', 'archived'], 'status'),
                default => null,
            };
            if ($out[$field] === null) {
                unset($out[$field]);
            }
        }

        // یکتایی Mobile/NationalId در Update (غیر از خود)
        if (!$isCreate) {
            if (isset($out['mobile'])) {
                $other = $this->patients->findByMobile(App::scope()->clinicId, $out['mobile']);
                if ($other !== null && (int) $other['id'] !== $patientId) {
                    throw new BookingException('CLINIC_VALIDATION_FAILED', 'این موبایل متعلق به بیمار دیگری است');
                }
            }
        }

        return $out;
    }

    private function cleanName(string $v): string
    {
        $v = trim(preg_replace('/[\x00-\x1F]/u', '', $v) ?? '');
        if ($v === '' || mb_strlen($v) > 120) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'نام نامعتبر است');
        }

        return mb_substr($v, 0, 120);
    }

    private function cleanNationalId(string $v): string
    {
        $v = trim($v);
        if (!NationalIdValidator::isValid($v)) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'کد ملی نامعتبر است');
        }

        return $v;
    }

    private function cleanDate(string $v, bool $mustBePast = false): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'تاریخ نامعتبر است (فرمت: YYYY-MM-DD)');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $v, new \DateTimeZone('UTC'));
        if ($dt === false || $dt->format('Y-m-d') !== $v) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'تاریخ نامعتبر است');
        }
        if ((int) $m[1] < 1900) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'تاریخ نامعتبر است');
        }
        if ($mustBePast && $dt > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'تاریخ تولد نمی‌تواند در آینده باشد');
        }

        return $v;
    }

    private function cleanEnum(string $v, array $allowed, string $label): string
    {
        if (!in_array($v, $allowed, true)) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', $label . ' نامعتبر است');
        }

        return $v;
    }

    private function cleanText(string $v, int $max): string
    {
        $v = trim(preg_replace('/[\x00-\x1F]/u', '', $v) ?? '');

        return mb_substr($v, 0, $max);
    }

    private function cleanPhone(string $v): string
    {
        $v = trim($v);
        if ($v !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $v)) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'تلفن نامعتبر است');
        }

        return $v;
    }

    /**
     * @param mixed $v
     */
    private function cleanJson($v): string
    {
        if (is_array($v)) {
            $json = json_encode($v, JSON_UNESCAPED_UNICODE);
        } elseif (is_string($v)) {
            $decoded = json_decode($v, true);
            if ($decoded === null && strtolower(trim($v)) !== 'null') {
                throw new BookingException('CLINIC_VALIDATION_FAILED', 'JSON نامعتبر است');
            }
            $json = is_string($v) && $v !== '' ? $v : 'null';
        } else {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'مقدار نامعتبر است');
        }

        return $json === false ? 'null' : $json;
    }

    /**
     * N-6: `MR-{YYMMDD}-{5char}` — Retry روی Uniqueness.
     */
    private function generateMrn(int $clinic_id): string
    {
        for ($i = 0; $i < 5; $i++) {
            $mrn = 'MR-' . gmdate('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
            $exists = $this->db->fetchValue(
                'SELECT COUNT(*) FROM ' . $this->db->table('cpms_patients') . ' WHERE clinic_id = %d AND mrn = %s',
                [$clinic_id, $mrn]
            );
            if ((int) $exists === 0) {
                return $mrn;
            }
        }
        throw new BookingException('CLINIC_INTERNAL_ERROR', 'خطا در ساخت MRN', 500);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicView(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'mrn' => (string) $row['mrn'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'mobile' => (string) $row['mobile'],
            'national_id' => $row['national_id'] !== null ? (string) $row['national_id'] : null,
            'birth_date' => $row['birth_date'] !== null ? (string) $row['birth_date'] : null,
            'gender' => (string) $row['gender'],
            'address' => $row['address'] !== null ? (string) $row['address'] : null,
            'phone' => $row['phone'] !== null ? (string) $row['phone'] : null,
            'emergency_contact_name' => $row['emergency_contact_name'] !== null ? (string) $row['emergency_contact_name'] : null,
            'emergency_contact_phone' => $row['emergency_contact_phone'] !== null ? (string) $row['emergency_contact_phone'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function staffView(array $row): array
    {
        $view = $this->publicView($row);
        $view['blood_group'] = ($row['blood_group'] ?? null) !== null ? (string) $row['blood_group'] : null;
        $view['medication_allergies'] = $this->jsonField($row['medication_allergies'] ?? null);
        $view['other_allergies'] = $this->jsonField($row['other_allergies'] ?? null);
        $view['chronic_conditions'] = $this->jsonField($row['chronic_conditions'] ?? null);
        $view['current_medications'] = $this->jsonField($row['current_medications'] ?? null);
        $view['medical_history'] = ($row['medical_history'] ?? null) !== null ? (string) $row['medical_history'] : null;
        $view['surgery_history'] = ($row['surgery_history'] ?? null) !== null ? (string) $row['surgery_history'] : null;
        $view['status'] = (string) ($row['status'] ?? '');

        return $view;
    }

    /**
     * Bounded Clinic-search / Reception-selection presentation (masked national ID).
     * Reused by Reception create so the UI never receives staffView clinical fields.
     *
     * @param array<string, mixed> $row Patient row or staff view of the same keys.
     * @return array<string, mixed>
     */
    public function to_search_view( array $row ): array {
        return $this->searchView( $row );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function searchView(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'mrn' => (string) $row['mrn'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'mobile' => (string) $row['mobile'],
            'national_id' => $row['national_id'] !== null ? NationalIdValidator::mask((string) $row['national_id']) : null,
            'birth_date' => $row['birth_date'] !== null ? (string) $row['birth_date'] : null,
            'gender' => (string) $row['gender'],
            'status' => (string) $row['status'],
        ];
    }

    /**
     * Same-Clinic national-ID uniqueness: bounded product error, never a raw
     * SQL/index leak. Empty/null national ID is not a uniqueness key.
     */
    private function assert_unique_national_id( int $clinic_id, ?string $national_id ): void {
        if ( null === $national_id || '' === $national_id ) {
            return;
        }
        if ( null !== $this->patients->find_by_national_id( $clinic_id, $national_id ) ) {
            throw new BookingException( 'CLINIC_VALIDATION_FAILED', 'این کد ملی متعلق به بیمار دیگری است' );
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $changed
     * @return array<string, mixed>
     */
    private function diffView(array $row, array $changed): array
    {
        $full = $this->staffView($row);
        $out = [];
        foreach ($changed as $field) {
            if (array_key_exists($field, $full)) {
                $out[$field] = $full[$field];
            }
        }

        return $out;
    }

    /**
     * @param mixed $v
     * @return mixed
     */
    private function jsonField($v)
    {
        if ($v === null) {
            return null;
        }
        $decoded = json_decode((string) $v, true);

        return $decoded === null ? null : $decoded;
    }
}
