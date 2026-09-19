<?php

declare(strict_types=1);

namespace ClinicCore\Application\Clinical;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\MedicalFileRepository;
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use ClinicCore\Settings\Settings;
use ClinicCore\Settings\SettingsFactory;
use Closure;

/**
 * سرویس فایل‌های پزشکی (F5) — E16/E17 (کارکنان) + C3/C4 (بیمار).
 *
 * Security Baseline (file-storage.md / FR-13.x):
 *  - **F-3:** اعتبارسنجی MIME واقعی با finfo (نه Extension) + تطابق
 *    Extension↔MIME از Whitelist + سقف حجم (Setting `files.max_upload_bytes`،
 *    پیش‌فرض 20) → عدم انطباق = `CLINIC_FILE_INVALID` بدون ذخیره.
 *  - **F-1:** خروجی فقط از Stream مجوزیافته؛ نام ذخیره تصادفی (F-2).
 *  - **F-4:** هر خواندن doctor_private/lab_result → Audit FILE_READ.
 *  - **F-5:** حذف = Soft Delete (سرویسی؛ Endpoint در قرارداد نیست).
 *  - **P-8:** بیمار فقط فایل‌های خودش (Ownership)؛ منشی فقط patient_visible.
 *  - **C6-F (Tenant):** خواندن/فهرست/نوشتن/حذف توسط کارکنان فقط داخل
 *    Clinic مورد اجازه (context موثق). مسیرهای stream/list در trusted-boundary
 *    skip list هستند (چون به بیمار هم سرویس می‌دهند)، پس predicate پایین
 *    **زیر مرز REST** اعمال می‌شود؛ بودن context غیرمشخص = رد (Fail‑Closed).
 *    رد = همان 404 امن (بدون افشای وجود و بدون هیچ بایت محتوا).
 */
final class MedicalFileService
{
    /** Whitelist MIME ↔ Extension (FR-13.1 — PDF/تصویر/اسکن/سند). */
    private const ALLOWED_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const CATEGORIES = ['lab_result', 'image', 'scan', 'document', 'other'];

    /**
     * @param Closure(int): LocalFileStorage $storageResolver مسیرِ ذخیره از Settingِ
     *        per-Clinic `files.storage_path` می‌آید؛ برای Clinicِ **صریحِ** هر
     *        عملیات صدا زده می‌شود، نه در زمانِ ساخت (الگوی SmsService). ساختِ این
     *        سرویس در `rest_api_init` انجام می‌شود — پیش از برقراری هر Scope‌ای.
     */
    public function __construct(
        private readonly MedicalFileRepository $files,
        private readonly Closure $storageResolver,
        private readonly SettingsFactory $settingsFactory,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * ذخیره‌سازِ یک Clinicِ **صریح و معتبر** — در زمانِ عملیات حل می‌شود.
     *
     * مسیرِ ذخیره از Settingِ per-Clinic `files.storage_path` می‌آید، پس باید از
     * Clinicِ **مالکِ همان عملیات** خوانده شود (ردیفِ پایدارِ فایل / بیمار که
     * چند خط بالاتر در برابر Clinicِ معتبر سنجیده شده) — نه از یک Clinicِ محیطی.
     */
    private function storageFor(int $clinicId): LocalFileStorage
    {
        return ($this->storageResolver)($clinicId);
    }

    /**
     * پیکربندیِ یک Clinicِ **صریح و معتبر** (همان منبعِ storageFor).
     */
    private function settingsFor(int $clinicId): Settings
    {
        return $this->settingsFactory->forClinic($clinicId);
    }

    // ================= E16 — آپلود کارکنان =================

    /**
     * آپلود کارکنان (منشی/پزشک — cap `cpms_file_upload`).
     *
     * @param array{name?: string, tmp_name?: string, size?: int, error?: int} $file از $_FILES
     * @return array<string, mixed>
     */
    public function upload(
        int $actorUserId,
        array $file,
        int $patientId,
        ?int $visitId = null,
        string $category = 'other',
        string $visibility = 'patient_visible'
    ): array {
        $this->requireCap($actorUserId, RolesAndCapabilities::FILE_UPLOAD);

        return $this->store($actorUserId, 'staff', $file, $patientId, $visitId, $category, $visibility);
    }

    // ================= C3 — آپلود بیمار (Ownership) =================

    /**
     * @param array{name?: string, tmp_name?: string, size?: int, error?: int} $file
     * @return array<string, mixed>
     */
    public function patientUpload(int $wpUserId, array $file, int $patientId, string $category = 'other'): array
    {
        // P-8: بیمار فقط برای پرونده خودش — بیمار دیگر → 404 + Audit
        $owned = $this->ownedPatientId($wpUserId);
        if ($owned !== $patientId) {
            $this->auditAndThrow($wpUserId, 'patient', $patientId, 'آپلود فقط برای پرونده خود بیمار مجاز است');
        }

        // آپلود بیمار همیشه patient_visible (بازبینی پزشک بعدی)
        return $this->store($wpUserId, 'patient', $file, $patientId, null, $category, 'patient_visible');
    }

    // ================= C4/E7 — فهرست =================

    /**
     * فهرست برای بیمار (C4 — Ownership + فقط patient_visible).
     *
     * @return list<array<string, mixed>>
     */
    public function patientFiles(int $wpUserId, int $patientId): array
    {
        $owned = $this->ownedPatientId($wpUserId);
        if ($owned !== $patientId) {
            $this->auditAndThrow($wpUserId, 'patient', $patientId, 'مشاهده فایل فقط برای پرونده خود بیمار مجاز است');
        }

        return array_map([$this, 'presentFile'], $this->files->forPatient($patientId, true));
    }

    /**
     * فهرست برای کارکنان (E7/داشبورد) — منشی: patient_visible؛ پزشک: همه.
     *
     * @return list<array<string, mixed>>
     */
    public function staffFiles(int $actorUserId, int $patientId): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::FILE_READ);
        // C6-F: فهرست هم Tenant‑aware است (بیمار Clinic دیگر فهرست نمی‌شود)
        $durableClinicId = $this->patientClinicId($patientId);
        $this->assertStaffClinic($actorUserId, $durableClinicId, 'patient', $patientId);
        // Phase 3 Slice 5 — فهرست هم با مجوز Clinic-scoped روی Clinicِ پایدار
        $this->authorizeScoped(
            $actorUserId,
            $durableClinicId,
            RolesAndCapabilities::FILE_READ,
            'patient',
            $patientId
        );
        $onlyVisible = !$this->canSeePrivate($actorUserId);

        return array_map([$this, 'presentFile'], $this->files->forPatient($patientId, $onlyVisible));
    }

    // ================= E17 — Stream مجوزیافته =================

    /**
     * خواندن مجاز فایل — کنترل سه‌لایه: Capability + Data-Access + Visibility.
     *
     * @return array{content: string, mime_type: string, size: int, original_filename: string}
     */
    public function stream(int $actorUserId, int $fileId): array
    {
        $row = $this->files->find($fileId);
        if ($row === null) {
            throw ClinicalException::of('CLINIC_NOT_FOUND', 'فایل یافت نشد', 404);
        }

        // نقش و Ownership
        $user = get_userdata($actorUserId);
        $roles = $user === false ? [] : (array) $user->roles;
        $isDoctor = in_array(RolesAndCapabilities::ROLE_DOCTOR, $roles, true);
        $isSecretary = in_array(RolesAndCapabilities::ROLE_SECRETARY, $roles, true);
        $isPatient = in_array(RolesAndCapabilities::ROLE_PATIENT, $roles, true);

        if ($isPatient) {
            // P-8: فقط فایل خودش + patient_visible + همان Clinical Patient Record
            $owned = $this->ownedPatientId($actorUserId);
            if ($owned !== (int) $row['patient_id']
                || (string) $row['visibility'] !== 'patient_visible'
                || (int) $row['clinic_id'] !== $this->patientClinicId($owned)) {
                $this->auditAndThrow($actorUserId, 'file', $fileId, 'دسترسی به این فایل مجاز نیست', 'فایل یافت نشد');
            }
        } elseif ($isDoctor) {
            // لایهٔ دفاعی (سراسری) — تصمیم‌گیرندهٔ نهایی assertStaffFileReadable است.
            $this->requireCap($actorUserId, RolesAndCapabilities::FILE_READ);
            // پزشک: هر Visibility (ماتریس 4.3) — اما فقط داخل Clinic context
            $this->assertStaffFileReadable($actorUserId, $row, $fileId);
        } elseif ($isSecretary) {
            $this->requireCap($actorUserId, RolesAndCapabilities::FILE_READ);
            // منشی: فقط patient_visible (ماتریس 4.2 — Doctor Private ❌). این قاعدهٔ
            // Visibility **مستقل از مجوز** است؛ grant صریحِ FILE_READ هرگز
            // doctor_private را باز نمی‌کند (Phase 3 Slice 5 — لازم، نه کافی).
            if ((string) $row['visibility'] !== 'patient_visible') {
                $this->auditAndThrow($actorUserId, 'file', $fileId, 'دسترسی به این فایل مجاز نیست', 'فایل یافت نشد');
            }
            $this->assertStaffFileReadable($actorUserId, $row, $fileId);
        } else {
            $this->auditAndThrow($actorUserId, 'file', $fileId, 'دسترسی به این فایل مجاز نیست', 'فایل یافت نشد');
        }

        // Clinicِ فایل (ردیفِ پایدار) — که در بالا در برابر Clinicِ معتبر سنجیده شد.
        $content = $this->storageFor((int) $row['clinic_id'])->read((string) $row['storage_path']);
        if ($content === null) {
            // Metadata هست ولی فایل فیزیکی گم شده — نباید URL خطا را فاش کند
            error_log('[CPMS][MedicalFileService] physical file missing for attachment ' . $fileId);
            throw ClinicalException::of('CLINIC_NOT_FOUND', 'فایل یافت نشد', 404);
        }

        // F-4: Audit هر خواندن حساس (doctor_private یا lab_result)
        if ((string) $row['visibility'] === 'doctor_private' || (string) $row['category'] === 'lab_result') {
            $this->audit->log(
                'FILE_READ',
                $this->actor($actorUserId),
                'file',
                $fileId,
                (int) $row['patient_id'],
                null,
                null,
                ['visibility' => (string) $row['visibility'], 'category' => (string) $row['category']]
            );
        }

        return [
            'content' => $content,
            'mime_type' => (string) $row['mime_type'],
            'size' => (int) $row['file_size'],
            'original_filename' => (string) $row['original_filename'],
        ];
    }

    // ================= Soft Delete (سرویسی — F-5) =================

    public function softDelete(int $actorUserId, int $fileId): void
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::FILE_UPLOAD);
        $row = $this->files->find($fileId);
        if ($row === null) {
            throw ClinicalException::of('CLINIC_NOT_FOUND', 'فایل یافت نشد', 404);
        }
        // C6-F: حذف (موتیشن) هم فقط داخل Clinic context موثق
        $durableClinicId = (int) $row['clinic_id'];
        $this->assertStaffClinic($actorUserId, $durableClinicId, 'file', $fileId);
        // Phase 3 Slice 5 — مجوز Clinic-scoped پیش از هر نوشتن روی ردیف
        $this->authorizeScoped(
            $actorUserId,
            $durableClinicId,
            RolesAndCapabilities::FILE_UPLOAD,
            'file',
            $fileId
        );
        $this->files->softDelete($fileId);
        $this->audit->log(
            'FILE_SOFT_DELETED',
            $this->actor($actorUserId),
            'file',
            $fileId,
            (int) $row['patient_id'],
            ['deleted_at' => null],
            ['deleted_at' => 'now'],
            ['original_filename' => (string) $row['original_filename']]
        );
    }

    // ================= Helpers — Validation و ذخیره =================

    /**
     * @param array{name?: string, tmp_name?: string, size?: int, error?: int} $file
     *
     * @return array<string, mixed>
     */
    private function store(
        int $actorUserId,
        string $via,
        array $file,
        int $patientId,
        ?int $visitId,
        string $category,
        string $visibility
    ): array {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'دسته‌بندی فایل نامعتبر است', 422, ['category' => $category]);
        }
        if (!in_array($visibility, ['patient_visible', 'doctor_private'], true)) {
            throw ClinicalException::of('CLINIC_VALIDATION_FAILED', 'سطح دسترسی فایل نامعتبر است', 422, ['visibility' => $visibility]);
        }
        // doctor_private فقط پزشک (P-6 — ماتریس Private)
        if ($visibility === 'doctor_private') {
            $user = get_userdata($actorUserId);
            $isDoctor = $user !== false && in_array(RolesAndCapabilities::ROLE_DOCTOR, (array) $user->roles, true);
            if (!$isDoctor) {
                throw ClinicalException::of('CLINIC_PERMISSION_DENIED', 'فایل خصوصی پزشک فقط توسط پزشک قابل ثبت است', 403);
            }
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp) && !is_file($tmp)) {
            throw ClinicalException::of('CLINIC_FILE_INVALID', 'فایلی برای ذخیره دریافت نشد', 400);
        }
        if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            throw ClinicalException::of('CLINIC_FILE_INVALID', 'آپلود فایل با خطا مواجه شد', 400);
        }

        // Clinicِ فایل = Clinicِ بیمار (relation). این lookup اینجا لازم است چون
        // سقفِ حجم یک Settingِ per-Clinic است و باید از Clinicِ مالکِ همین عملیات
        // خوانده شود؛ در نبودِ بیمار، درخواست چند خط پایین‌تر با 404 رد می‌شود
        // (پیش از هر نوشتن روی دیسک).
        $patientClinicId = $this->patientClinicId($patientId);
        if ($patientClinicId === 0) {
            throw ClinicalException::of('CLINIC_NOT_FOUND', 'بیمار یافت نشد', 404);
        }

        // F-3: حجم (سقف از Settingِ Clinicِ مالک)
        $maxBytes = max(1, (int) $this->settingsFor($patientClinicId)->get('files.max_upload_bytes', 10485760));
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw ClinicalException::of('CLINIC_FILE_INVALID', 'حجم فایل خارج از محدوده مجاز است', 400, ['max_mb' => (int) ($maxBytes / 1048576)]);
        }

        $content = file_get_contents($tmp);
        if ($content === false) {
            throw ClinicalException::of('CLINIC_FILE_INVALID', 'خواندن فایل ممکن نشد', 400);
        }

        // F-3: MIME واقعی با finfo + تطابق Extension
        $mime = $this->sniffMime($content);
        if ($mime === null || !isset(self::ALLOWED_TYPES[$mime])) {
            throw ClinicalException::of('CLINIC_FILE_INVALID', 'نوع فایل مجاز نیست (PDF/JPG/PNG/WEBP)', 400, ['detected_mime' => $mime]);
        }
        $extension = self::ALLOWED_TYPES[$mime];
        $declaredName = (string) ($file['name'] ?? 'file');
        if (!preg_match('/\.' . $extension . '$/i', $declaredName)) {
            throw ClinicalException::of(
                'CLINIC_FILE_INVALID',
                'پسوند فایل با محتوای آن نمی‌خواند',
                400,
                ['expected_extension' => $extension, 'filename' => mb_substr($declaredName, 0, 100)]
            );
        }

        // C6: کلینیک فایل = کلینیک بیمار (relation) — بیمار در بالا verify شد.
        // C6-F: نوشتن هم Relation‑based — بیمار باید داخل Clinic مورد اجازه
        // باشد؛ این بررسی پیش از هر نوشتن روی دیسک انجام می‌شود.
        if ($via === 'staff') {
            $this->assertStaffClinic($actorUserId, $patientClinicId, 'patient', $patientId);
            // Phase 3 Slice 5 — نوشتن هم با مجوز Clinic-scoped روی Clinicِ
            // پایدارِ بیمار، **پیش از** storage->store() (رد ⇒ هیچ بایتی روی
            // دیسک و هیچ ردیفی در cpms_medical_attachments نمی‌نشیند).
            $this->authorizeScoped(
                $actorUserId,
                $patientClinicId,
                RolesAndCapabilities::FILE_UPLOAD,
                'patient',
                $patientId
            );
        } else {
            $this->assertPatientRecord($actorUserId, $patientClinicId, $patientId);
        }
        $storagePath = $this->storageFor($patientClinicId)->store($content, $patientClinicId, $extension);

        $fileId = $this->files->insert($patientClinicId, [
            'patient_id' => $patientId,
            'visit_id' => $visitId,
            'category' => $category,
            'original_filename' => mb_substr($declaredName, 0, 255),
            'stored_filename' => basename($storagePath),
            'mime_type' => $mime,
            'file_size' => $size,
            'storage_path' => $storagePath,
            'visibility' => $visibility,
            'uploaded_by_wp_user_id' => $actorUserId,
        ]);

        $this->audit->log(
            'FILE_UPLOADED',
            $this->actor($actorUserId),
            'file',
            $fileId,
            $patientId,
            null,
            ['category' => $category, 'visibility' => $visibility, 'size' => $size, 'mime' => $mime],
            ['via' => $via, 'visit_id' => $visitId]
        );

        return $this->presentFile($this->files->find($fileId) ?? ['id' => $fileId]);
    }

    /**
     * MIME واقعی از محتوا (finfo) — نه Header/Extension قابل جعل.
     */
    private function sniffMime(string $content): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_buffer($finfo, $content);
        finfo_close($finfo);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    private function canSeePrivate(int $actorUserId): bool
    {
        $user = get_userdata($actorUserId);

        return $user !== false
            && in_array(RolesAndCapabilities::ROLE_DOCTOR, (array) $user->roles, true)
            && user_can($actorUserId, RolesAndCapabilities::FILE_READ);
    }

    /**
     * بیمار متصل به کاربر (P-5) — برای C3/C4.
     */
    private function ownedPatientId(int $wpUserId): int
    {
        global $wpdb;
        $patientId = $wpdb->get_var($wpdb->prepare(
            'SELECT l.patient_id FROM ' . $wpdb->prefix . 'cpms_patient_user_links l' .
            ' JOIN ' . $wpdb->prefix . 'cpms_patients p ON p.id = l.patient_id' .
            ' WHERE l.wp_user_id = %d AND p.status = %s ORDER BY l.is_primary DESC, l.id ASC LIMIT 1',
            $wpUserId,
            'active'
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($patientId === null) {
            throw ClinicalException::of('CLINIC_NOT_FOUND', 'بیماری به این حساب متصل نیست', 404);
        }

        return (int) $patientId;
    }

    /**
     * Clinic مورد اجازه برای مسیرهای کارکنان — Phase 2 (C6-F):
     *  ۱) Scope صریحِ درخواست (مرز Trusted Clinic آن را از «هدر/پارامتر +
     *     Membership فعال» تأیید و bind می‌کند)؛
     *  ۲) Resolution سیستمی «تنها Clinic» (SystemClinicResolver) برای نصب
     *     تک‌Clinic؛
     *  ۳) مسیرهای skip-listed (`/files/{id}/stream`، `/patients/{id}/files`) که
     *     به بیمار هم سرویس می‌دهند Scope bind نمی‌کنند ⇒ استقرار از Membership
     *     فعالِ **یکتای** کاربر (TrustedClinicEstablisher — بدون SystemResolver).
     * هیچ fallback «اولین Clinic» وجود ندارد؛ مبهم ⇒ `CLINIC_SCOPE_REQUIRED` (400).
     * مسیر بیمار هرگز از اینجا نمی‌گذرد (Ownership‑محور است).
     */
    private function trustedClinicId(int $actorUserId): int
    {
        $explicit = ScopeContext::tryGet();
        if ($explicit !== null) {
            return $explicit->clinicId;
        }

        try {
            return App::scope()->clinicId;
        } catch (ScopeRequiredException $first) {
            // مسیر skip-listed (stream/list) هیچ Scope‌ای bind نمی‌کند؛ تنها
            // جایگزینِ امن، استقرار از Membership فعالِ یکتاست (نه اولین Clinic).
            try {
                return (new TrustedClinicEstablisher(App::db(), new MembershipRepository(App::db())))
                    ->establish($actorUserId, null)
                    ->clinicId;
            } catch (ScopeRequiredException) {
                throw ClinicalException::of(
                    $first->errorCode,
                    $first->getMessage(),
                    $first->httpStatus(),
                    $first->getData()
                );
            }
        }
    }

    /**
     * فایل/بیمار هدف باید متعلق به همان Clinic مورد اجازه باشد. خلاف ⇒ رفتار
     * دقیقاً مانند «یافت نشد» (عدم افشای وجود) + Audit — بدون دسترسی به
     * محتوای دیسک، چون این guard پیش از storage->read()/store() است.
     */
    private function assertStaffClinic(int $actorUserId, int $targetClinicId, string $resourceType, int $resourceId): void
    {
        if ($targetClinicId !== $this->trustedClinicId($actorUserId)) {
            $this->auditAndThrow(
                $actorUserId,
                $resourceType,
                $resourceId,
                'دسترسی به این فایل مجاز نیست',
                $resourceType === 'file' ? 'فایل یافت نشد' : 'بیمار یافت نشد'
            );
        }
    }

    /** بیمار: فقط فایل همان Clinical Patient Record متصل به حساب. */
    private function assertPatientRecord(int $wpUserId, int $targetClinicId, int $patientId): void
    {
        if ($targetClinicId !== $this->patientClinicId($this->ownedPatientId($wpUserId))) {
            $this->auditAndThrow($wpUserId, 'patient', $patientId, 'دسترسی به این فایل مجاز نیست');
        }
    }

    /** @return int Clinic بیمار (۰ = ناموجود) */
    private function patientClinicId(int $patientId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT clinic_id FROM ' . $wpdb->prefix . 'cpms_patients WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $patientId
        ));
    }

    private function requireCap(int $wpUserId, string $cap): void
    {
        if (!user_can($wpUserId, $cap)) {
            throw ClinicalException::of('CLINIC_PERMISSION_DENIED', 'دسترسی لازم را ندارید', 403);
        }
    }

    /**
     * @return array{wp_user_id: int, role: string}
     */
    private function actor(int $wpUserId): array
    {
        $user = get_userdata($wpUserId);

        return ['wp_user_id' => $wpUserId, 'role' => $user->roles[0] ?? 'unknown'];
    }

    /**
     * **Phase 3 Slice 5 — مجوز Clinic-scoped برای مسیرهای کارکنان (فایل).**
     *
     * تصمیم‌گیرندهٔ نهایی سه شرطِ هم‌زمان است: بازیگر احرازهویت‌شده + عضویت
     * **فعالِ پایدار** در **همان** Clinicِ شیء + دقیقاً همان مجوزِ معنای عملیات
     * (`cpms_file_read` برای خواندن/فهرست، `cpms_file_upload` برای نوشتن/حذف) —
     * deny صریحِ عضویت بر grant و preset غالب است. نقش/ Capability سراسریِ
     * وردپرس (`requireCap`) تنها به‌عنوان لایهٔ دفاعی جلوی این می‌ماند و هرگز
     * Authority تولید نمی‌کند؛ مدیر نصب بدون عضویت، دسترسی خودکار ندارد.
     *
     * Clinicِ مرجع، `clinic_id`ِ **ردیفِ پایدار** است (نه payload و نه «Clinic
     * اول»). رد = همان «یافت نشد» امن (قرارداد C6‑F: فایلِ غیرمجاز و فایلِ
     * ناموجود تفکیک‌ناپذیرند) + Audit — و همیشه **پیش از** `storage->read()` یا
     * `storage->store()`.
     *
     * @throws ClinicalException
     */
    private function authorizeScoped(
        int $actorUserId,
        int $durableClinicId,
        string $permission,
        string $resourceType,
        int $resourceId
    ): void {
        if ($actorUserId <= 0 || $durableClinicId <= 0) {
            // بازیگر یا Clinicِ پایدارِ نامعتبر ⇒ Fail-Closed (بدون حدس).
            $this->auditAndThrow(
                $actorUserId,
                $resourceType,
                $resourceId,
                'بازیگر یا Clinic پایدارِ نامعتبر است',
                $resourceType === 'file' ? 'فایل یافت نشد' : 'بیمار یافت نشد'
            );
        }

        if (App::authorization_service()->can($actorUserId, $durableClinicId, $permission)) {
            return;
        }

        $this->auditAndThrow(
            $actorUserId,
            $resourceType,
            $resourceId,
            'نبودِ مجوز Clinic-scoped «' . $permission . '» در Clinic ' . $durableClinicId,
            $resourceType === 'file' ? 'فایل یافت نشد' : 'بیمار یافت نشد'
        );
    }

    /**
     * Clinic موثق برای stream کارکنان — با **کاندیدِ** Clinicِ پایدارِ فایل.
     *
     * `/files/{id}/stream` در skip-list مرز Trusted Clinic است (به بیمار هم
     * سرویس می‌دهد) ⇒ هیچ Scope‌ای bind نمی‌شود. سه مرحلهٔ قبلی دست‌نخورده است
     * (Scope صریح → «تنها Clinic» نصب → Membership فعالِ یکتا) و فقط در حالتِ
     * مبهم یک گامِ **افزوده** دارد: تأییدِ کاندیدِ پایدار توسط
     * `TrustedClinicEstablisher` (عضویت فعال + Clinic/Organization فعال).
     * «اولین Clinic» هرگز انتخاب نمی‌شود؛ اگر کاندید تأیید نشود، خطای اصلیِ
     * Scope بی‌تغییر باقی می‌ماند. تأییدِ کاندید به‌خودی‌خود اجازهٔ خواندن
     * نیست — `authorizeScoped` پس از آن تصمیم می‌گیرد.
     */
    private function trustedClinicIdForFile(int $actorUserId, int $candidateClinicId): int
    {
        $explicit = ScopeContext::tryGet();
        if ($explicit !== null) {
            return $explicit->clinicId;
        }

        try {
            return App::scope()->clinicId;
        } catch (ScopeRequiredException $first) {
            $establisher = new TrustedClinicEstablisher(App::db(), new MembershipRepository(App::db()));
            try {
                return $establisher->establish($actorUserId, null)->clinicId;
            } catch (ScopeRequiredException) {
                // کاربر چند عضویت فعال دارد ⇒ کاندیدِ ردیفِ پایدار سنجیده می‌شود.
            }

            if ($candidateClinicId > 0) {
                try {
                    return $establisher->establish($actorUserId, $candidateClinicId)->clinicId;
                } catch (ScopeRequiredException) {
                    // کاندید رد شد ⇒ خطای اصلی Scope (بدون افشای علتِ رد).
                }
            }

            throw ClinicalException::of(
                $first->errorCode,
                $first->getMessage(),
                $first->httpStatus(),
                $first->getData()
            );
        }
    }

    /**
     * خواندن فایل توسط کارکنان: Clinicِ پایدارِ فایل باید با context موثق
     * بخواند **و** مجوز Clinic-scopedِ `cpms_file_read` برای همان Clinic اثبات
     * شود — همه پیش از `storage->read()`.
     *
     * @param array<string, mixed> $row ردیفِ پایدارِ ضمیمه
     */
    private function assertStaffFileReadable(int $actorUserId, array $row, int $fileId): void
    {
        $durableClinicId = (int) $row['clinic_id'];
        if ($durableClinicId !== $this->trustedClinicIdForFile($actorUserId, $durableClinicId)) {
            $this->auditAndThrow($actorUserId, 'file', $fileId, 'دسترسی به این فایل مجاز نیست', 'فایل یافت نشد');
        }

        $this->authorizeScoped(
            $actorUserId,
            $durableClinicId,
            RolesAndCapabilities::FILE_READ,
            'file',
            $fileId
        );
    }

    /**
     * IDOR → Audit + 404.
     */
    private function auditAndThrow(
        int $wpUserId,
        string $resourceType,
        int $resourceId,
        string $message,
        ?string $publicMessage = null
    ): void
    {
        $user = get_userdata($wpUserId);
        $this->audit->log(
            'FORBIDDEN_ACCESS_ATTEMPT',
            ['wp_user_id' => $wpUserId, 'role' => ($user->roles[0] ?? 'unknown')],
            $resourceType,
            $resourceId,
            null,
            null,
            null,
            ['reason' => $message]
        );
        // Envelope بیرونی باید **عیناً** مثل «یافت نشد» باشد تا وجودِ ردیفِ
        // Clinic/Organization دیگر قابل تمایز نباشد؛ دلیلِ رد فقط در Audit می‌ماند.
        throw ClinicalException::of('CLINIC_NOT_FOUND', $publicMessage ?? $message, 404);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function presentFile(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'patient_id' => (int) $row['patient_id'],
            'visit_id' => $row['visit_id'] !== null ? (int) $row['visit_id'] : null,
            'category' => (string) $row['category'],
            'original_filename' => (string) $row['original_filename'],
            'mime_type' => (string) $row['mime_type'],
            'file_size' => (int) $row['file_size'],
            'visibility' => (string) $row['visibility'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
