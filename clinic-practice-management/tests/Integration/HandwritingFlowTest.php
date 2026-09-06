<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Handwriting\HandwritingException;
use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * تست‌های دست‌خط F7 — FR-9.1..9.3 + ADR-0009 + ADR-0014 + TP-12 (بخش Integration).
 *
 * پوشش:
 *  - F1 ایجاد سند + صفحه پیش‌فرض + Audit HW_DOC_CREATE + مالکیت (ماتریس 4.3)
 *  - F1b لیست سند ویزیت؛ F1c افزودن صفحه (page_index ترتیبی، page_count)
 *  - F2 پروتکل Revision: apply فقط C==R+1؛ C≤R یا C>R+1 → 409 CLINIC_CONFLICT
 *    با وضعیت سرور؛ مسیر Force = load-then-save با conflict_reason
 *  - Idempotency (Contract §0): رترای همان کلید = پاسخ ذخیره‌شده بدون version bump
 *  - stroke_data: gzip+base64 و JSON خام (سازگاری Safari) + اعتبارسنجی 422
 *  - F3 خواندن صفحه (Strokes decode)
 *  - GC (handwriting.gc): سیاست keep/max_age + زمان‌بندی RECURRING
 */
final class HandwritingFlowTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private int $clinicianId;
    private int $patientId;
    private int $doctorUserId;
    private int $otherDoctorUserId;
    private int $secretaryUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        $this->secretaryUserId = $this->makeUser('hw_secretary', 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('hw_doctor', 'cpms_doctor');
        $this->otherDoctorUserId = $this->makeUser('hw_other', 'cpms_doctor');

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Hand',
                $this->doctorUserId,
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        $seq = random_int(1000, 999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-HW-' . $seq,
                'Hand',
                'P' . $seq,
                '0912' . sprintf('%07d', $seq),
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;
    }

    // ================= F1 — ایجاد سند =================

    public function testCreateDocumentMakesDefaultPageAndAudits(): void
    {
        $visitId = $this->makeConsultation();
        $visit = $this->visitRow($visitId);

        $doc = App::handwritingService()->createDocument($this->doctorUserId, $visitId, 'نسخه دست‌خط', []);

        $this->assertSame($visitId, (int) $doc['visit_id']);
        $this->assertSame((int) $visit['patient_id'], (int) $doc['patient_id']);
        $this->assertSame($this->clinicianId, (int) $doc['clinician_id']);
        $this->assertSame(1, (int) $doc['page_count']);
        $this->assertCount(1, $doc['pages']);
        $this->assertSame(1240, (int) $doc['pages'][0]['width']);
        $this->assertSame(1754, (int) $doc['pages'][0]['height']);
        $this->assertSame('lined', (string) $doc['pages'][0]['background_template']);

        $this->assertAudit('HW_DOC_CREATE', 'handwriting_document', (int) $doc['id']);
    }

    public function testCreateDocumentRejectsDoctorWithoutVisitOwnership(): void
    {
        $visitId = $this->makeConsultation();

        try {
            App::handwritingService()->createDocument($this->otherDoctorUserId, $visitId, null, []);
            $this->fail('expected 404 for non-owner doctor');
        } catch (HandwritingException $e) {
            $this->assertSame(404, $e->httpStatus);
        }
        $this->assertAudit('FORBIDDEN_ACCESS_ATTEMPT', 'handwriting', $visitId);
    }

    public function testSecretaryCannotCreateDocument(): void
    {
        $visitId = $this->makeConsultation();

        try {
            App::handwritingService()->createDocument($this->secretaryUserId, $visitId, null, []);
            $this->fail('expected 403 for secretary');
        } catch (HandwritingException $e) {
            $this->assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
            $this->assertSame(403, $e->httpStatus);
        }
    }

    // ================= F1b / F1c — لیست و افزودن صفحه =================

    public function testListDocumentsReturnsLatestForVisit(): void
    {
        $visitId = $this->makeConsultation();
        $svc = App::handwritingService();
        $svc->createDocument($this->doctorUserId, $visitId, null, []);

        $list = $svc->listDocuments($this->doctorUserId, $visitId);
        $this->assertNotNull($list['document']);
        $this->assertCount(1, $list['document']['pages']);

        $empty = $svc->listDocuments($this->doctorUserId, $this->makeConsultation());
        $this->assertNull($empty['document']);
    }

    public function testAddPageAppendsSequentially(): void
    {
        $visitId = $this->makeConsultation();
        $svc = App::handwritingService();
        $doc = $svc->createDocument($this->doctorUserId, $visitId, null, []);

        $page2 = $svc->addPage($this->doctorUserId, (int) $doc['id'], ['background_template' => 'graph']);

        $this->assertSame(1, (int) $page2['page_index']);
        $this->assertSame('graph', (string) $page2['background_template']);
        $this->assertSame(2, (int) $svc->listDocuments($this->doctorUserId, $visitId)['document']['page_count']);
        $this->assertAudit('HW_PAGE_ADD', 'handwriting_page', (int) $page2['id']);
    }

    // ================= F2 — پروتکل Revision (ADR-0014) =================

    public function testSaveAppliesOnlyWhenClientRevisionIsServerPlusOne(): void
    {
        [$doc, $pageId] = $this->makeDocWithPage();
        $svc = App::handwritingService();

        // C = R+1 = 1 → apply
        $r1 = $svc->savePage($this->doctorUserId, $pageId, [
            'client_revision' => 1,
            'stroke_data' => $this->strokeData(),
            'width' => 1240, 'height' => 1754,
            'saved_by' => 'manual',
        ], $this->uuid());
        $this->assertSame(200, $r1['status']);
        $this->assertSame(1, $r1['response']['client_revision']);
        $this->assertSame(2, $r1['response']['version']);
        $this->assertSame(1, $r1['response']['stroke_count']);

        // C = 1 دوباره (کمتر از R+1=2) → 409 بدون bump
        try {
            $svc->savePage($this->doctorUserId, $pageId, [
                'client_revision' => 1,
                'stroke_data' => $this->strokeData(),
            ], $this->uuid());
            $this->fail('expected conflict');
        } catch (HandwritingException $e) {
            $this->assertSame('CLINIC_CONFLICT', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
            $this->assertSame(1, $e->data['server']['client_revision']);
            $this->assertIsArray($e->data['server']['strokes']);
            $this->assertSame(2, $e->data['server']['version']);
        }

        // C = R+2 → 409
        try {
            $svc->savePage($this->doctorUserId, $pageId, [
                'client_revision' => 3,
                'stroke_data' => $this->strokeData(),
            ], $this->uuid());
            $this->fail('expected conflict');
        } catch (HandwritingException $e) {
            $this->assertSame('CLINIC_CONFLICT', $e->errorCode);
        }

        // version/revision سرور دست‌نخورده
        $page = $svc->getPage($this->doctorUserId, $pageId);
        $this->assertSame(1, $page['client_revision']);
        $this->assertSame(2, $page['version']);
        $this->assertSame(1, $this->versionCount($pageId));

        $this->assertAudit('HW_PAGE_SAVE', 'handwriting_page', $pageId);
    }

    public function testForcePathAfterConflictAppliesWithConflictReason(): void
    {
        [, $pageId] = $this->makeDocWithPage();
        $svc = App::handwritingService();

        $svc->savePage($this->doctorUserId, $pageId, [
            'client_revision' => 1, 'stroke_data' => $this->strokeData(),
        ], $this->uuid());

        // تضاد → load سرور (R=1) → بازنویسی با C=2 و conflict_reason
        try {
            $svc->savePage($this->doctorUserId, $pageId, [
                'client_revision' => 1, 'stroke_data' => $this->strokeData(),
            ], $this->uuid());
            $this->fail('expected conflict');
        } catch (HandwritingException) {
            // سرور را «load» کردیم (R=1) — شبیه‌سازی کلاینت
        }

        $forced = $svc->savePage($this->doctorUserId, $pageId, [
            'client_revision' => 2,
            'stroke_data' => $this->strokeData(),
            'saved_by' => 'manual',
            'conflict_reason' => 'overwrite_after_conflict',
        ], $this->uuid());
        $this->assertSame(200, $forced['status']);
        $this->assertSame(2, $forced['response']['client_revision']);

        global $wpdb;
        $meta = $wpdb->get_var($wpdb->prepare(
            'SELECT meta_json FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE action = %s AND resource_id = %d ORDER BY id DESC LIMIT 1',
            'HW_PAGE_SAVE',
            $pageId
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertStringContainsString('overwrite_after_conflict', (string) $meta);
    }

    public function testIdempotentReplayReturnsStoredResponseWithoutVersionBump(): void
    {
        [, $pageId] = $this->makeDocWithPage();
        $svc = App::handwritingService();
        $key = $this->uuid();

        $first = $svc->savePage($this->doctorUserId, $pageId, [
            'client_revision' => 1, 'stroke_data' => $this->strokeData(),
        ], $key);
        $replay = $svc->savePage($this->doctorUserId, $pageId, [
            'client_revision' => 1, 'stroke_data' => $this->strokeData(),
        ], $key);

        $this->assertSame(200, $replay['status']);
        // JSON objectها ذاتاً بدون‌ترتیب‌اند (RFC 8259 §4) — مقایسه Value-wise با ksort
        $expected = $first['response'];
        $actual = $replay['response'];
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        // فقط یک نسخه — bump دوباره نداشتیم
        $this->assertSame(1, $this->versionCount($pageId));
    }

    // ================= stroke_data — سازگاری و اعتبارسنجی =================

    public function testRawJsonBase64AcceptedAndStoredGzipped(): void
    {
        [, $pageId] = $this->makeDocWithPage();
        $svc = App::handwritingService();

        // Safari قدیمی: base64(JSON خام) بدون gzip — سرور فشرده و ذخیره می‌کند
        $r = $svc->savePage($this->doctorUserId, $pageId, [
            'client_revision' => 1,
            'stroke_data' => base64_encode(json_encode([$this->stroke()]) ?: '[]'),
        ], $this->uuid());
        $this->assertSame(200, $r['status']);

        global $wpdb;
        $stored = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT stroke_data FROM ' . $wpdb->prefix . 'cpms_handwriting_pages WHERE id = %d',
            $pageId
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $decoded = base64_decode($stored, true);
        $this->assertNotFalse($decoded);
        $this->assertSame("\x1f\x8b", substr($decoded, 0, 2), 'stored payload must be gzip');

        // F3 — خواندن: Strokes decode شده برمی‌گردد
        $page = $svc->getPage($this->doctorUserId, $pageId);
        $this->assertCount(1, $page['strokes']);
        $this->assertSame('pen', $page['strokes'][0]['tool']);
        $this->assertSame(2, $page['version']);
    }

    public function testInvalidStrokePayloadRejected(): void
    {
        [, $pageId] = $this->makeDocWithPage();
        $svc = App::handwritingService();

        $cases = [
            '!!!not-base64!!!',
            base64_encode('{"not":"an array"}'),
            base64_encode('[{"no_points":true}]'),
            base64_encode('[{"points":[["x","y"]]}]'),
        ];
        foreach ($cases as $payload) {
            try {
                $svc->savePage($this->doctorUserId, $pageId, [
                    'client_revision' => 1, 'stroke_data' => $payload,
                ], $this->uuid());
                $this->fail('expected validation error for ' . $payload);
            } catch (HandwritingException $e) {
                $this->assertSame('CLINIC_VALIDATION', $e->errorCode);
                $this->assertSame(422, $e->httpStatus);
            }
        }

        // ابعاد نامعتبر
        try {
            $svc->savePage($this->doctorUserId, $pageId, [
                'client_revision' => 1, 'stroke_data' => $this->strokeData(), 'width' => 10,
            ], $this->uuid());
            $this->fail('expected width validation');
        } catch (HandwritingException $e) {
            $this->assertSame(422, $e->httpStatus);
        }
    }

    // ================= REST — لایه Contract =================

    public function testRestPutRequiresIdempotencyKeyHeader(): void
    {
        wp_set_current_user($this->doctorUserId);
        [, $pageId] = $this->makeDocWithPage();

        $res = $this->dispatch('PUT', self::NS . '/handwriting/pages/' . $pageId, [
            'client_revision' => 1, 'stroke_data' => $this->strokeData(),
        ]);
        $this->assertSame(400, $res->get_status());
        $this->assertSame('CLINIC_VALIDATION', $this->errorCode($res));
    }

    public function testRestSaveRoundTripAndConflictEnvelope(): void
    {
        wp_set_current_user($this->doctorUserId);
        [, $pageId] = $this->makeDocWithPage();

        $ok = $this->dispatch('PUT', self::NS . '/handwriting/pages/' . $pageId, [
            'client_revision' => 1,
            'stroke_data' => $this->strokeData(),
            'width' => 1240, 'height' => 1754,
        ], ['Idempotency-Key' => $this->uuid()]);
        $this->assertSame(200, $ok->get_status());
        $this->assertSame(1, $ok->get_data()['data']['client_revision']);

        // Save دیگر با C کهنه → Envelope خطای 409 (ADR-0019)
        $conflict = $this->dispatch('PUT', self::NS . '/handwriting/pages/' . $pageId, [
            'client_revision' => 1, 'stroke_data' => $this->strokeData(),
        ], ['Idempotency-Key' => $this->uuid()]);
        $this->assertSame(409, $conflict->get_status());
        $this->assertSame('CLINIC_CONFLICT', $this->errorCode($conflict));
        $this->assertArrayHasKey('server', $conflict->get_data()['data']);
    }

    public function testRestGetPageAndList(): void
    {
        wp_set_current_user($this->doctorUserId);
        [$doc, $pageId] = $this->makeDocWithPage();

        $page = $this->dispatch('GET', self::NS . '/handwriting/pages/' . $pageId);
        $this->assertSame(200, $page->get_status());
        $this->assertSame(1240, $page->get_data()['data']['width']);

        $list = $this->dispatch('GET', self::NS . '/handwriting/documents', ['visit_id' => (int) $doc['visit_id']]);
        $this->assertSame(200, $list->get_status());
        $this->assertNotNull($list->get_data()['data']['document']);

        // منشی: بدون cpms_medical_read → 403
        wp_set_current_user($this->secretaryUserId);
        $forbidden = $this->dispatch('GET', self::NS . '/handwriting/pages/' . $pageId);
        $this->assertSame(403, $forbidden->get_status());
    }

    // ================= GC — سیاست نگهداری (handwriting.gc) =================

    public function testGcPurgesOldVersionsBeyondKeep(): void
    {
        [, $pageId] = $this->makeDocWithPage();
        $svc = App::handwritingService();

        // 12 Save → نسخه‌های 2..13
        for ($c = 1; $c <= 12; $c++) {
            $svc->savePage($this->doctorUserId, $pageId, [
                'client_revision' => $c, 'stroke_data' => $this->strokeData(),
            ], $this->uuid());
        }
        $this->assertSame(12, $this->versionCount($pageId));

        // نسخه‌های 2..6 را 40 روز گذشته می‌کنیم (قدیمی‌تر از hw.version_max_age_days=30)
        global $wpdb;
        $old = gmdate('Y-m-d H:i:s', time() - 40 * 86400) . '.000';
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'cpms_handwriting_page_versions SET created_at = %s WHERE page_id = %d AND version BETWEEN 2 AND 6',
            $old,
            $pageId
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $deleted = $svc->purgeVersions();

        // keep=10: maxVersion=13 → حذف version≤3 و قدیمی → نسخه‌های 2 و 3 (نسخه 4..6 قدیمی اما در ۱۰ نسخه آخر)
        $this->assertSame(2, $deleted);
        $this->assertSame(10, $this->versionCount($pageId));
        // نسخه‌های تازه هرگز حذف نمی‌شوند
        $page = $svc->getPage($this->doctorUserId, $pageId);
        $this->assertSame(13, $page['version']);
    }

    public function testHandwritingGcIsScheduledRecurringJob(): void
    {
        App::scheduleRecurringJobs();

        $queued = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_jobs') . ' WHERE type = %s AND status = %s',
            ['handwriting.gc', \ClinicCore\Infrastructure\Queue\JobQueue::QUEUED]
        );
        $this->assertSame(1, $queued);

        // اجرا از مسیر Dispatcher واقعی (handler ثبت‌شده در App::dispatcher)
        $processed = App::dispatcher()->tick(20);
        $this->assertGreaterThanOrEqual(1, $processed);
    }

    // ================= Helpers =================

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function makeDocWithPage(): array
    {
        $visitId = $this->makeConsultation();
        $doc = App::handwritingService()->createDocument($this->doctorUserId, $visitId, null, []);

        return [$doc, (int) $doc['pages'][0]['id']];
    }

    /** ویزیت واقعی تا in_consultation — بیمار تازه هر بار (J-5). */
    private function makeConsultation(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(1000, 999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-HWV-' . $seq,
                'Visit',
                'P' . $seq,
                '0913' . sprintf('%07d', $seq),
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;

        $visit = App::visitService()->walkIn($this->secretaryUserId, $patientId, $this->clinicianId);
        $id = (int) $visit['id'];
        App::visitService()->transition($this->doctorUserId, $id, 'call');
        App::visitService()->transition($this->doctorUserId, $id, 'start');

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function stroke(): array
    {
        return [
            'id' => 's1',
            'tool' => 'pen',
            'color' => '#1a1a2e',
            'size' => 4,
            'points' => [[10, 20, 0.5, 1690000000], [40, 60, 0.8, 1690000050]],
        ];
    }

    private function strokeData(): string
    {
        return base64_encode(gzencode(json_encode([$this->stroke()], JSON_UNESCAPED_UNICODE) ?: '[]') ?: '[]');
    }

    /**
     * @return array<string, mixed>
     */
    private function visitRow(int $visitId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d LIMIT 1',
            [$visitId]
        );
        if ($row === null) {
            throw new \RuntimeException("visit {$visitId} not found");
        }

        return $row;
    }

    private function versionCount(int $pageId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_handwriting_page_versions') . ' WHERE page_id = %d',
            [$pageId]
        );
    }

    private function assertAudit(string $action, string $resourceType, int $resourceId): void
    {
        $count = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE action = %s AND resource_type = %s AND resource_id = %d',
            [$action, $resourceType, $resourceId]
        );
        $this->assertGreaterThanOrEqual(1, $count, "audit {$action} for {$resourceType}#{$resourceId}");
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    private function uuid(): string
    {
        $d = static fn (int $len): string => bin2hex(random_bytes((int) ceil($len / 2)));

        return sprintf('%s-%s-4%s-%s-%s', $d(8), $d(4), substr($d(3), 0, 3), substr($d(4), 0, 4), $d(12));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $route, array $body = [], array $headers = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }

        return rest_do_request($request);
    }

    private function errorCode(WP_REST_Response $res): string
    {
        $data = $res->get_data();

        return is_array($data) && isset($data['code']) ? (string) $data['code'] : '';
    }
}
