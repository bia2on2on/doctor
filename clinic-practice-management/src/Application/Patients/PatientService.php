<?php

declare(strict_types=1);

namespace ClinicCore\Application\Patients;

use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Validators\MobileValidator;
use ClinicCore\Domain\Validators\NationalIdValidator;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Settings\Settings;

/**
 * سرویس بیمار (F3) — C1/C2 (بیمار) + D2–D5 (منشی).
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

    public function __construct(
        private readonly CpmsDb $db,
        private readonly PatientRepository $patients,
        private readonly Settings $settings,
        private readonly LicenseGate $licenseGate,
        private readonly AuditLogger $audit,
        private readonly OpLogger $op
    ) {
    }

    // ================= C1 — Me =================

    /**
     * @return array<string, mixed>
     */
    public function me(int $wpUserId): array
    {
        $row = $this->db->fetchRow(
            'SELECT p.* FROM ' . $this->db->table('cpms_patient_user_links') . ' l
             JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = l.patient_id
             WHERE l.wp_user_id = %d AND p.status = %s
             ORDER BY l.is_primary DESC, l.id ASC LIMIT 1',
            [$wpUserId, 'active']
        );
        if ($row === null) {
            throw new BookingException('CLINIC_NOT_FOUND', 'بیماری به این حساب متصل نیست', 404);
        }

        return $this->publicView((array) $row);
    }

    // ================= C2 — Update Me =================

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function updateMe(int $wpUserId, array $fields): array
    {
        $current = $this->me($wpUserId);
        $data = $this->validateForUpdate($fields, self::ME_EDITABLE, (int) $current['id']);

        if ($data === []) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'فیلدی برای ویرایش ارسال نشده است');
        }

        $this->patients->update((int) $current['id'], $data + ['updated_at' => $this->db->nowUtcSql()]);
        $updated = (array) $this->patients->find((int) $current['id']);

        $this->audit->log(
            'PATIENT_PROFILE_UPDATED',
            ['wp_user_id' => $wpUserId, 'role' => 'patient'],
            'patient',
            (int) $current['id'],
            (int) $current['id'],
            $this->diffView($current, $data),
            $this->diffView($updated, $data),
            ['via' => 'self_service']
        );
        $this->op->info('patient.profile_updated', ['patient_id' => (int) $current['id'], 'fields' => array_keys($data)]);

        return $this->publicView($updated);
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
            $this->patients->search(1, $q, $limit)
        );
    }

    // ================= D3 — Get (Secretary) =================

    /**
     * @return array<string, mixed>
     */
    public function get(int $patientId): array
    {
        $row = $this->patients->find($patientId);
        if ($row === null) {
            throw new BookingException('CLINIC_NOT_FOUND', 'بیمار یافت نشد', 404);
        }

        return $this->staffView((array) $row);
    }

    // ================= D4 — Create (Secretary) =================

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function create(array $fields, int $actorUserId): array
    {
        $this->assertLicense(LicenseGate::OP_PATIENT_CREATE);

        $firstName = (string) ($fields['first_name'] ?? '');
        $lastName = (string) ($fields['last_name'] ?? '');
        $mobile = MobileValidator::normalize((string) ($fields['mobile'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'نام و نام خانوادگی الزامی است');
        }
        if ($mobile === null) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'شماره موبایل نامعتبر است');
        }
        if ($this->patients->findByMobile(1, $mobile) !== null) {
            throw new BookingException('CLINIC_VALIDATION_FAILED', 'بیماری با همین موبایل قبلاً ثبت شده است');
        }

        $data = $this->validateForUpdate($fields, self::CREATE_FIELDS, 0, true) + [
            'clinic_id' => 1,
            'first_name' => mb_substr($firstName, 0, 120),
            'last_name' => mb_substr($lastName, 0, 120),
            'mobile' => $mobile,
            'mrn' => $this->generateMrn(),
            'status' => 'active',
            'created_at' => $this->db->nowUtcSql(),
            'updated_at' => $this->db->nowUtcSql(),
        ];

        $id = $this->patients->create($data);
        $row = (array) $this->patients->find($id);

        $this->audit->log(
            'PATIENT_CREATED',
            ['wp_user_id' => $actorUserId, 'role' => 'staff'],
            'patient',
            $id,
            $id,
            null,
            $this->staffView($row),
            ['mobile' => MobileValidator::mask($mobile)]
        );
        $this->op->info('patient.created', ['patient_id' => $id, 'actor' => $actorUserId, 'mobile' => MobileValidator::mask($mobile)]);

        return $this->staffView($row);
    }

    // ================= D5 — Update (Secretary) =================

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function update(int $patientId, array $fields, int $actorUserId): array
    {
        $this->assertLicense(LicenseGate::OP_PATIENT_UPDATE);

        $current = (array) $this->patients->find($patientId);
        if ($current === []) {
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

    private function assertLicense(string $operation): void
    {
        $decision = $this->licenseGate->assert($operation);
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
                $other = $this->patients->findByMobile(1, $out['mobile']);
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
    private function generateMrn(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $mrn = 'MR-' . gmdate('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
            $exists = $this->db->fetchValue(
                'SELECT COUNT(*) FROM ' . $this->db->table('cpms_patients') . ' WHERE clinic_id = 1 AND mrn = %s',
                [$mrn]
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
        $view['status'] = (string) $row['status'];

        return $view;
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
