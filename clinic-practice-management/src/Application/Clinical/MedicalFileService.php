<?php

declare(strict_types=1);

namespace ClinicCore\Application\Clinical;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Repository\MedicalFileRepository;
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use ClinicCore\Settings\Settings;

/**
 * سرویس فایل‌های پزشکی (F5) — E16/E17 (کارکنان) + C3/C4 (بیمار).
 *
 * Security Baseline (file-storage.md / FR-13.x):
 *  - **F-3:** اعتبارسنجی MIME واقعی با finfo (نه Extension) + تطابق
 *    Extension↔MIME از Whitelist + سقف حجم (Setting `files.max_size_mb`،
 *    پیش‌فرض 20) → عدم انطباق = `CLINIC_FILE_INVALID` بدون ذخیره.
 *  - **F-1:** خروجی فقط از Stream مجوزیافته؛ نام ذخیره تصادفی (F-2).
 *  - **F-4:** هر خواندن doctor_private/lab_result → Audit FILE_READ.
 *  - **F-5:** حذف = Soft Delete (سرویسی؛ Endpoint در قرارداد نیست).
 *  - **P-8:** بیمار فقط فایل‌های خودش (Ownership)؛ منشی فقط patient_visible.
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

    public function __construct(
        private readonly MedicalFileRepository $files,
        private readonly LocalFileStorage $storage,
        private readonly Settings $settings,
        private readonly AuditLogger $audit
    ) {
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
            // P-8: فقط فایل خودش + patient_visible
            $owned = $this->ownedPatientId($actorUserId);
            if ($owned !== (int) $row['patient_id'] || (string) $row['visibility'] !== 'patient_visible') {
                $this->auditAndThrow($actorUserId, 'file', $fileId, 'دسترسی به این فایل مجاز نیست');
            }
        } elseif ($isDoctor) {
            $this->requireCap($actorUserId, RolesAndCapabilities::FILE_READ);
            // پزشک: هر Visibility (ماتریس 4.3)
        } elseif ($isSecretary) {
            $this->requireCap($actorUserId, RolesAndCapabilities::FILE_READ);
            // منشی: فقط patient_visible (ماتریس 4.2 — Doctor Private ❌)
            if ((string) $row['visibility'] !== 'patient_visible') {
                $this->auditAndThrow($actorUserId, 'file', $fileId, 'دسترسی به این فایل مجاز نیست');
            }
        } else {
            $this->auditAndThrow($actorUserId, 'file', $fileId, 'دسترسی به این فایل مجاز نیست');
        }

        $content = $this->storage->read((string) $row['storage_path']);
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

        // F-3: حجم (سقف از Setting)
        $maxBytes = max(1, (int) $this->settings->get('files.max_size_mb', 20)) * 1024 * 1024;
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

        $storagePath = $this->storage->store($content, 1, $extension);

        $fileId = $this->files->insert([
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
     * IDOR → Audit + 404.
     */
    private function auditAndThrow(int $wpUserId, string $resourceType, int $resourceId, string $message): void
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
        throw ClinicalException::of('CLINIC_NOT_FOUND', $message, 404);
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
