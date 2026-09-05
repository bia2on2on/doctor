<?php

declare(strict_types=1);

namespace ClinicCore\Application\Handwriting;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\HandwritingRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use ClinicCore\Infrastructure\Security\Idempotency;
use ClinicCore\Infrastructure\Security\Settings;
use Throwable;

/**
 * سرویس دست‌خط پزشک (F7 — FR-9.1..9.3 / ADR-0009 / ADR-0014).
 *
 * مدل ذخیره‌سازی (ADR-0009): یک صفحه = یک Row؛ Strokeها به‌صورت
 * gzip(JSON) + base64 در `cpms_handwriting_pages.stroke_data` — نوشتن =
 * یک UPDATE (NFR-PERF-4). نسخه‌ها append-only در `_page_versions` (K-6).
 *
 * پروتکل Revision (ADR-0014):
 *  - سرور `page.client_revision = R` را نگه می‌دارد؛ کلاینت پس از load پایه R.
 *  - Save با `C`: **apply فقط اگر C == R+1** → R=C، version++، INSERT نسخه.
 *  - C ≤ R یا C > R+1 → 409 CLINIC_CONFLICT + وضعیت سرور (برای دیالوگ
 *    «نسخه من/سرور») — ادغام خودکار وجود ندارد (ADR-0014 §تصمیم‌ها).
 *  - رترای همان Save (Idempotency-Key، context=pageId) → پاسخ ذخیره‌شده
 *    بدون version bump (کلاس عمومی Idempotency — Contract §0).
 *  - مسیر Force = load-then-save: کلاینت سرور را load می‌کند، سپس با
 *    C=R+1 و `conflict_reason` در Audit ذخیره می‌کند (Endpoint جدید لازم نیست).
 *
 * مجوزها (ماتریس §4.3): نوشتن = `cpms_note_create`، خواندن = `cpms_medical_read`
 * + مالکیت: فقط ویزیتِ خودِ پزشک (clinician متصل به wp_user).
 *
 *.stroke_data ورودی: base64(gzip(JSON)) یا base64(JSON) — تشخیص با magic
 * \x1f\x8b (سازگاری با Safari قدیمی بدون CompressionStream)؛ سرور همیشه
 * gzip استاندارد ذخیره می‌کند.
 */
final class HandwritingService
{
    /** سقف Payload خام stroke_data (بازشده) — ~4MB (SRS NFR). */
    private const MAX_STROKE_BYTES = 4_194_304;
    private const MAX_STROKES = 5000;
    private const MAX_POINTS_PER_STROKE = 4096;
    private const TOOLS = ['pen', 'highlighter'];
    private const TEMPLATES = ['blank', 'lined', 'graph', 'form'];
    private const SAVE_SOURCES = ['autosave', 'manual', 'sync_recovery'];

    public function __construct(
        private readonly CpmsDb $db,
        private readonly HandwritingRepository $handwriting,
        private readonly VisitRepository $visits,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
        private readonly Idempotency $idem
    ) {
    }

    // ================= F1 — ایجاد سند =================

    /**
     * ایجاد Document + صفحات اولیه برای ویزیت.
     *
     * @param list<array<string, mixed>> $pages
     * @return array<string, mixed>
     */
    public function createDocument(int $actorUserId, int $visitId, ?string $title, array $pages): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::NOTE_CREATE);
        $visit = $this->requireOwnVisit($actorUserId, $visitId, 'create_document');

        if ($pages === []) {
            $pages = [['width' => 1240, 'height' => 1754]]; // A4 @150dpi — پیش‌فرض
        }

        $doc = $this->db->transactional(function () use ($actorUserId, $visitId, $visit, $title, $pages): array {
            $documentId = $this->handwriting->insertDocument([
                'clinic_id' => (int) ($visit['clinic_id'] ?? 1),
                'visit_id' => $visitId,
                'patient_id' => (int) $visit['patient_id'],
                'clinician_id' => (int) $visit['clinician_id'],
                'title' => $title !== null ? mb_substr(trim($title), 0, 190) : null,
                'page_count' => 0,
            ]);

            $created = [];
            foreach (array_values($pages) as $i => $p) {
                $created[] = $this->handwriting->findPage($this->handwriting->insertPage($this->pageRow((array) $p, $i)));
            }
            $this->handwriting->updateDocument($documentId, ['page_count' => count($created)]);

            $doc = $this->handwriting->findDocument($documentId);
            $doc['pages'] = $created;

            return $doc;
        });

        $this->audit->log(
            'HW_DOC_CREATE',
            $this->actor($actorUserId),
            'handwriting_document',
            (int) $doc['id'],
            (int) $doc['patient_id'],
            null,
            ['visit_id' => $visitId, 'page_count' => count($pages), 'title' => $doc['title']]
        );

        return $doc;
    }

    // ================= F1b — لیست اسناد ویزیت =================

    /**
     * @return array<string, mixed>
     */
    public function listDocuments(int $actorUserId, int $visitId): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::MEDICAL_READ);
        $this->requireOwnVisit($actorUserId, $visitId, 'list_documents');

        $doc = $this->handwriting->latestDocumentForVisit($visitId);
        if ($doc === null) {
            return ['visit_id' => $visitId, 'document' => null];
        }

        return ['visit_id' => $visitId, 'document' => $this->documentWithPages((int) $doc['id'])];
    }

    // ================= F1c — افزودن صفحه =================

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    public function addPage(int $actorUserId, int $documentId, array $page): array
    {
        $doc = $this->requireDocument($documentId);
        $this->requireCap($actorUserId, RolesAndCapabilities::NOTE_CREATE);
        $this->requireOwnVisit($actorUserId, (int) $doc['visit_id'], 'add_page');

        $existing = $this->handwriting->pagesForDocument($documentId);
        $index = count($existing); // append در انتها (U(document_id, page_index))

        $row = $this->db->transactional(function () use ($documentId, $page, $index): array {
            $rowId = $this->handwriting->insertPage($this->pageRow($page, $index));
            $this->handwriting->updateDocument($documentId, ['page_count' => $index + 1]);

            return $this->handwriting->findPage($rowId);
        });

        $this->audit->log(
            'HW_PAGE_ADD',
            $this->actor($actorUserId),
            'handwriting_page',
            (int) $row['id'],
            (int) $doc['patient_id'],
            null,
            ['document_id' => $documentId, 'page_index' => $index]
        );

        return $row;
    }

    // ================= F3 — خواندن صفحه =================

    /**
     * @return array<string, mixed>
     */
    public function getPage(int $actorUserId, int $pageId): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::MEDICAL_READ);
        $page = $this->requirePage($pageId);
        $doc = $this->requireDocument((int) $page['document_id']);
        $this->requireOwnVisit($actorUserId, (int) $doc['visit_id'], 'get_page');

        return [
            'id' => (int) $page['id'],
            'document_id' => (int) $page['document_id'],
            'page_index' => (int) $page['page_index'],
            'width' => (int) $page['width'],
            'height' => (int) $page['height'],
            'background_template' => (string) $page['background_template'],
            'background_attachment_id' => $page['background_attachment_id'] !== null ? (int) $page['background_attachment_id'] : null,
            'client_revision' => (int) $page['client_revision'],
            'version' => (int) $page['version'],
            'stroke_count' => (int) $page['stroke_count'],
            'last_saved_at' => $page['last_saved_at'],
            'strokes' => $this->decodeStored((string) $page['stroke_data']),
        ];
    }

    // ================= F2 — ذخیره صفحه (پروتکل Revision) =================

    /**
     * @param array<string, mixed> $body {stroke_data, width, height, client_revision, saved_by?, conflict_reason?}
     * @return array{response: array<string, mixed>, status: int}
     */
    public function savePage(int $actorUserId, int $pageId, array $body, ?string $idemKey): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::NOTE_CREATE);
        $page = $this->requirePage($pageId);
        $doc = $this->requireDocument((int) $page['document_id']);
        $this->requireOwnVisit($actorUserId, (int) $doc['visit_id'], 'save_page');

        // --- Idempotency (Contract §0): رترای همان Save = پاسخ قبلی بدون bump ---
        if ($idemKey !== null) {
            $check = $this->idem->check($idemKey, 'handwriting/page', $actorUserId, $pageId);
            if ($check['is_replay']) {
                if ($check['response'] !== null) {
                    return ['response' => $check['response'], 'status' => (int) $check['response_code']];
                }

                // در حال پردازش (Request موازی) — Idempotency عمومی 409 می‌دهد.
                throw HandwritingException::of(
                    'CLINIC_DUPLICATE_IN_FLIGHT',
                    'ذخیره دیگری در حال پردازش است',
                    409
                );
            }
        }

        try {
            $result = $this->applySave($actorUserId, $page, $doc, $body);
        } catch (Throwable $e) {
            if ($idemKey !== null) {
                $this->idem->release($idemKey, 'handwriting/page', $actorUserId, $pageId);
            }
            throw $e;
        }

        if ($idemKey !== null) {
            $this->idem->complete($idemKey, 'handwriting/page', $actorUserId, $pageId, $result['status'], $result['response']);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $body
     * @return array{response: array<string, mixed>, status: int}
     */
    private function applySave(int $actorUserId, array $page, array $doc, array $body): array
    {
        $clientRevision = (int) ($body['client_revision'] ?? -1);
        $serverRevision = (int) $page['client_revision'];
        $pageId = (int) $page['id'];

        // --- اعتبارسنجی Payload (پیش از بررسی Conflict تا خطای ورودی بر 409 مقدم باشد) ---
        $width = (int) ($body['width'] ?? (int) $page['width']);
        $height = (int) ($body['height'] ?? (int) $page['height']);
        if ($width < 100 || $width > 8192 || $height < 100 || $height > 8192) {
            throw HandwritingException::of('CLINIC_VALIDATION', 'ابعاد صفحه نامعتبر است (100..8192)', 422, ['field' => 'width/height']);
        }

        $source = (string) ($body['saved_by'] ?? 'autosave');
        if (!in_array($source, self::SAVE_SOURCES, true)) {
            throw HandwritingException::of('CLINIC_VALIDATION', 'saved_by نامعتبر است', 422, ['field' => 'saved_by']);
        }

        // پس‌زمینه قابل تغییر در همان Save است (Annotation روی تصویر — FR-9.2).
        $update = [
            'stroke_data' => '',
            'stroke_count' => 0,
            'width' => $width,
            'height' => $height,
        ];
        if (isset($body['background_template'])) {
            $template = (string) $body['background_template'];
            if (!in_array($template, self::TEMPLATES, true)) {
                throw HandwritingException::of('CLINIC_VALIDATION', 'قالب پس‌زمینه نامعتبر است', 422, ['field' => 'background_template']);
            }
            $update['background_template'] = $template;
        }
        if (array_key_exists('background_attachment_id', $body)) {
            $attachmentId = $body['background_attachment_id'] === null ? null : (int) $body['background_attachment_id'];
            if ($attachmentId !== null) {
                $exists = $this->db->fetchValue(
                    'SELECT id FROM ' . $this->db->table('cpms_medical_attachments') . ' WHERE id = %d LIMIT 1',
                    [$attachmentId]
                );
                if ($exists === null) {
                    throw HandwritingException::of('CLINIC_VALIDATION', 'پیوست پس‌زمینه یافت نشد', 422, ['field' => 'background_attachment_id']);
                }
            }
            $update['background_attachment_id'] = $attachmentId;
        }

        $strokes = $this->validateStrokeData(isset($body['stroke_data']) ? (string) $body['stroke_data'] : '');

        // --- پروتکل Revision (ADR-0014): apply فقط اگر C == R+1 ---
        if ($clientRevision !== $serverRevision + 1) {
            $this->audit->log(
                'HW_PAGE_SAVE',
                $this->actor($actorUserId),
                'handwriting_page',
                $pageId,
                (int) $doc['patient_id'],
                ['client_revision' => $serverRevision, 'version' => (int) $page['version']],
                null,
                [
                    'conflict' => true,
                    'client_revision' => $clientRevision,
                    'server_revision' => $serverRevision,
                    'reason' => 'revision_mismatch',
                ]
            );

            // وضعیت سرور برای دیالوگ «نسخه من/سرور» — بدون ادغام خودکار.
            throw HandwritingException::of(
                'CLINIC_CONFLICT',
                'این صفحه روی سرور تغییر کرده است — نسخه سرور را باز کنید یا نسخه خود را بازنویسی کنید',
                409,
                [
                    'server' => [
                        'client_revision' => $serverRevision,
                        'version' => (int) $page['version'],
                        'last_saved_at' => $page['last_saved_at'],
                        'strokes' => $this->decodeStored((string) $page['stroke_data']),
                    ],
                ]
            );
        }

        $encoded = $this->encodeStored($strokes);
        $newVersion = (int) $page['version'] + 1;

        $this->db->transactional(function () use ($pageId, $page, $doc, $encoded, $strokes, $update, $source, $newVersion, $clientRevision, $actorUserId): void {
            $update['stroke_data'] = $encoded;
            $update['stroke_count'] = count($strokes);
            $update['client_revision'] = $clientRevision;
            $update['version'] = $newVersion;
            $update['last_saved_at'] = $this->db->nowUtcSql();
            $this->handwriting->updatePage($pageId, $update);

            // K-6 — نسخه‌ها append-only: هر Save موفق یک Snapshot.
            $this->handwriting->insertVersion([
                'page_id' => $pageId,
                'version' => $newVersion,
                'stroke_data' => $encoded,
                'saved_by' => $source,
                'created_at' => $this->db->nowUtcSql(),
            ]);
        });

        $this->audit->log(
            'HW_PAGE_SAVE',
            $this->actor($actorUserId),
            'handwriting_page',
            $pageId,
            (int) $doc['patient_id'],
            ['client_revision' => (int) $page['client_revision'], 'version' => (int) $page['version']],
            ['client_revision' => $clientRevision, 'version' => $newVersion],
            [
                'saved_by' => $source,
                'stroke_count' => count($strokes),
                'conflict_reason' => isset($body['conflict_reason']) ? mb_substr((string) $body['conflict_reason'], 0, 190) : null,
            ]
        );

        return [
            'response' => [
                'page_id' => $pageId,
                'client_revision' => $clientRevision,
                'version' => $newVersion,
                'stroke_count' => count($strokes),
                'saved_by' => $source,
                'last_saved_at' => $this->db->nowUtcSql(),
            ],
            'status' => 200,
        ];
    }

    // ================= GC — پاک‌سازی نسخه‌ها (handwriting.gc) =================

    /**
     * سیاست نگهداری ADR-0009: حذف نسخه‌های قدیمی‌تر از `hw.version_max_age_days`
     * که خارج از `hw.version_keep` نسخه آخر صفحه هستند.
     */
    public function purgeVersions(): int
    {
        $keep = max(1, (int) $this->settings->get('hw.version_keep', 10));
        $maxAgeDays = max(1, (int) $this->settings->get('hw.version_max_age_days', 30));

        return $this->handwriting->purgeOldVersions($keep, $maxAgeDays);
    }

    // ================= Helpers — داده =================

    /**
     * @param array<string, mixed> $page
     * @param list<array<string, mixed>>|null $strokes
     */
    private function pageRow(array $page, int $index): array
    {
        $template = (string) ($page['background_template'] ?? 'lined');
        if (!in_array($template, self::TEMPLATES, true)) {
            throw HandwritingException::of('CLINIC_VALIDATION', 'قالب پس‌زمینه نامعتبر است', 422, ['field' => 'background_template']);
        }
        $width = (int) ($page['width'] ?? 1240);
        $height = (int) ($page['height'] ?? 1754);
        if ($width < 100 || $width > 8192 || $height < 100 || $height > 8192) {
            throw HandwritingException::of('CLINIC_VALIDATION', 'ابعاد صفحه نامعتبر است (100..8192)', 422, ['field' => 'width/height']);
        }

        return [
            'page_index' => $index,
            'width' => $width,
            'height' => $height,
            'stroke_data' => '',
            'stroke_count' => 0,
            'background_template' => $template,
            'background_attachment_id' => isset($page['background_attachment_id']) && $page['background_attachment_id'] !== null
                ? (int) $page['background_attachment_id']
                : null,
        ];
    }

    /**
     * اعتبارسنجی + نرمال‌سازی stroke_data ورودی (base64 gzip یا base64 JSON).
     *
     * @return list<array<string, mixed>>
     */
    private function validateStrokeData(string $b64): array
    {
        if ($b64 === '') {
            return []; // صفحه خالی — Save معتبر است (پاک‌کردن همه Strokeها)
        }

        $raw = base64_decode($b64, true);
        if ($raw === false || $raw === '') {
            throw HandwritingException::of('CLINIC_VALIDATION', 'stroke_data باید base64 معتبر باشد', 422, ['field' => 'stroke_data']);
        }
        if (strlen($raw) > self::MAX_STROKE_BYTES) {
            throw HandwritingException::of('CLINIC_PAYLOAD_TOO_LARGE', 'حجم stroke_data بیش از حد مجاز است (~4MB)', 413, ['field' => 'stroke_data']);
        }

        // سازگاری Safari قدیمی: کلاینت ممکن است JSON خام بفرستد — magic gzip را چک می‌کنیم.
        $json = str_starts_with($raw, "\x1f\x8b") ? @gzdecode($raw) : $raw;
        if ($json === false || $json === '') {
            throw HandwritingException::of('CLINIC_VALIDATION', 'gzip/stroke_data قابل خواندن نیست', 422, ['field' => 'stroke_data']);
        }

        $strokes = json_decode($json, true);
        if (!is_array($strokes)) {
            throw HandwritingException::of('CLINIC_VALIDATION', 'stroke_data باید آرایه JSON باشد', 422, ['field' => 'stroke_data']);
        }
        if (count($strokes) > self::MAX_STROKES) {
            throw HandwritingException::of('CLINIC_VALIDATION', 'تعداد Strokeها بیش از حد مجاز است', 422, ['field' => 'stroke_data']);
        }

        $normalized = [];
        foreach (array_values($strokes) as $stroke) {
            if (!is_array($stroke) || !isset($stroke['points']) || !is_array($stroke['points']) || $stroke['points'] === []) {
                throw HandwritingException::of('CLINIC_VALIDATION', 'ساختار Stroke نامعتبر است (points الزامی)', 422, ['field' => 'stroke_data']);
            }
            if (count($stroke['points']) > self::MAX_POINTS_PER_STROKE) {
                throw HandwritingException::of('CLINIC_VALIDATION', 'تعداد نقاط یک Stroke بیش از حد مجاز است', 422, ['field' => 'stroke_data']);
            }
            foreach ($stroke['points'] as $point) {
                if (!is_array($point) || count($point) < 2 || count($point) > 4
                    || !is_numeric($point[0]) || !is_numeric($point[1])) {
                    throw HandwritingException::of('CLINIC_VALIDATION', 'نقطه باید [x, y(, pressure, ts)] باشد', 422, ['field' => 'stroke_data']);
                }
            }
            $normalized[] = [
                'id' => isset($stroke['id']) ? (string) $stroke['id'] : '',
                'tool' => in_array($stroke['tool'] ?? 'pen', self::TOOLS, true) ? (string) ($stroke['tool'] ?? 'pen') : 'pen',
                'color' => isset($stroke['color']) ? (string) $stroke['color'] : '#1a1a2e',
                'size' => isset($stroke['size']) && is_numeric($stroke['size']) ? (float) $stroke['size'] : 3.0,
                'points' => array_map(
                    static fn ($p): array => [
                        (float) $p[0],
                        (float) $p[1],
                        isset($p[2]) && is_numeric($p[2]) ? (float) $p[2] : 0.5,
                        isset($p[3]) && is_numeric($p[3]) ? (int) $p[3] : 0,
                    ],
                    $stroke['points']
                ),
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $strokes
     */
    private function encodeStored(array $strokes): string
    {
        $json = json_encode($strokes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';

        return base64_encode(gzencode($json, 6) ?: $json);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeStored(string $b64): array
    {
        if ($b64 === '') {
            return [];
        }
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            return [];
        }
        $json = str_starts_with($raw, "\x1f\x8b") ? @gzdecode($raw) : $raw;
        $decoded = $json === false ? null : json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    // ================= Helpers — مجوز =================

    /**
     * @return array<string, mixed>
     */
    private function documentWithPages(int $documentId): array
    {
        $doc = $this->requireDocument($documentId);

        return [
            'id' => (int) $doc['id'],
            'visit_id' => (int) $doc['visit_id'],
            'patient_id' => (int) $doc['patient_id'],
            'title' => $doc['title'],
            'page_count' => (int) $doc['page_count'],
            'updated_at' => $doc['updated_at'],
            'pages' => array_map(static fn (array $p): array => [
                'id' => (int) $p['id'],
                'page_index' => (int) $p['page_index'],
                'width' => (int) $p['width'],
                'height' => (int) $p['height'],
                'background_template' => (string) $p['background_template'],
                'client_revision' => (int) $p['client_revision'],
                'version' => (int) $p['version'],
                'stroke_count' => (int) $p['stroke_count'],
            ], $this->handwriting->pagesForDocument($documentId)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireDocument(int $id): array
    {
        $doc = $this->handwriting->findDocument($id);
        if ($doc === null) {
            throw HandwritingException::of('CLINIC_NOT_FOUND', 'سند دست‌خط یافت نشد', 404);
        }

        return $doc;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePage(int $id): array
    {
        $page = $this->handwriting->findPage($id);
        if ($page === null) {
            throw HandwritingException::of('CLINIC_NOT_FOUND', 'صفحه دست‌خط یافت نشد', 404);
        }

        return $page;
    }

    private function requireCap(int $wpUserId, string $cap): void
    {
        if (!user_can($wpUserId, $cap)) {
            throw HandwritingException::of('CLINIC_PERMISSION_DENIED', 'دسترسی لازم را ندارید', 403);
        }
    }

    /**
     * ماتریس §4.3 — دست‌خط فقط روی «ویزیت خودِ پزشک» (الگوی ClinicalService).
     *
     * @return array<string, mixed>
     */
    private function requireOwnVisit(int $actorUserId, int $visitId, string $scope): array
    {
        $visit = $this->visits->find($visitId);
        if ($visit === null) {
            throw HandwritingException::of('CLINIC_NOT_FOUND', 'ویزیت یافت نشد', 404);
        }

        $clinicianId = $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table('cpms_clinicians') . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
            [$actorUserId]
        );
        if ($clinicianId === null || (int) $clinicianId !== (int) $visit['clinician_id']) {
            $user = get_userdata($actorUserId);
            $this->audit->log(
                'FORBIDDEN_ACCESS_ATTEMPT',
                ['wp_user_id' => $actorUserId, 'role' => ($user->roles[0] ?? 'unknown')],
                'handwriting',
                $visitId,
                (int) $visit['patient_id'],
                null,
                null,
                ['reason' => 'دست‌خط فقط برای ویزیت خودِ پزشک مجاز است (ماتریس 4.3)', 'scope' => $scope]
            );

            throw HandwritingException::of('CLINIC_NOT_FOUND', 'این ویزیت به حساب شما متصل نیست', 404);
        }

        return $visit;
    }

    /**
     * @return array{wp_user_id: int, role: string}
     */
    private function actor(int $wpUserId): array
    {
        $user = get_userdata($wpUserId);

        return ['wp_user_id' => $wpUserId, 'role' => $user->roles[0] ?? 'unknown'];
    }
}
