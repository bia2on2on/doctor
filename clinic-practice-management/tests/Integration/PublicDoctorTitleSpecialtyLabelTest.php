<?php

/**
 * Public directory / booking — doctor title vs specialty label.
 *
 * Regression coverage for the public clinician rendering:
 *  - H1: the clinician name must be published through
 *        `DoctorDisplayName::with_title_or_fallback()` so the title is the
 *        proper Persian display label (added when missing, collapsed when the
 *        stored value already carries one, never doubled). The public surface
 *        previously rendered the raw stored `full_name`.
 *  - H2: the clinician specialty shown in the clinician item and in the
 *        specialty filter must resolve recognised catalogue values through
 *        `MedicalSpecialtyCatalog` (canonical Persian label) while preserving
 *        raw custom / historical / unknown values. The raw value is kept in
 *        `data-specialty` / option `value`; only the visible label changes.
 *
 * This suite is ADDITIVE: no existing Phase 8 assertion is modified, removed
 * or weakened. Synthetic fixture data only — no patient data.
 *
 * NOTICE: this sandbox has no PHP binary, so this suite is NOT executed here.
 * It is written to fail on live main and pass after the accompanying fix.
 */
declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

final class PublicDoctorTitleSpecialtyLabelTest extends WP_UnitTestCase
{
    private const SHORTCODE = 'cpms_public_booking';
    private const TZ_TEHRAN = 'Asia/Tehran';

    private int $org = 0;
    private int $clinic = 0;
    private int $location = 0;
    private string $uid = '';

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        $this->uid = uniqid('pdt', false);
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);

        parent::tearDown();
    }

    // ================= H1 — doctor title is a proper display label =================

    public function testClinicianNameIsPublishedAsTitledDisplayLabel(): void
    {
        $html = $this->render((string) $this->clinic);

        // No stored title -> the proper Persian title label is added.
        self::assertStringContainsString(
            '<span class="cpms-public-booking__clinician-name">دکتر سارا احمدی</span>',
            $html,
            'A clinician whose stored name has no title must be published with the title label.'
        );
        self::assertStringNotContainsString(
            '<span class="cpms-public-booking__clinician-name">سارا احمدی</span>',
            $html,
            'The raw stored name without a title must not be shown as-is.'
        );
        self::assertStringContainsString(
            'data-search="دکتر سارا احمدی"',
            $html,
            'The search index must match the displayed titled name.'
        );

        // Already titled -> shown unchanged (idempotent, no doubling).
        self::assertStringContainsString(
            '<span class="cpms-public-booking__clinician-name">دکتر رضا کریمی</span>',
            $html,
            'An already-titled stored name must be shown unchanged.'
        );

        // Doubled stored title collapses to a single title.
        self::assertStringContainsString(
            '<span class="cpms-public-booking__clinician-name">دکتر علی حسینی</span>',
            $html,
            'A doubled stored title must collapse to one title.'
        );
        self::assertStringNotContainsString(
            '<span class="cpms-public-booking__clinician-name">دکتر دکتر علی حسینی</span>',
            $html,
            'The doubled title must not be published.'
        );
    }

    // ================= H2 — specialty resolves through the catalogue =================

    public function testClinicianSpecialtyResolvesToCatalogLabelOrPreservesRaw(): void
    {
        $html = $this->render((string) $this->clinic);

        // Catalogue slug -> canonical Persian label; raw key kept for filtering.
        self::assertStringContainsString(
            'data-specialty="cardiology"',
            $html,
            'The raw catalogue key must remain in data-specialty.'
        );
        self::assertStringContainsString(
            '<span class="cpms-public-booking__clinician-specialty">متخصص قلب و عروق</span>',
            $html,
            'A catalogue slug must show its canonical Persian label.'
        );

        // Catalogue label stored verbatim resolves to the same canonical label.
        self::assertStringContainsString(
            'data-specialty="متخصص قلب و عروق"',
            $html,
            'The stored catalogue label must remain the filter key.'
        );

        // Custom / free-text specialty is preserved verbatim, never coerced.
        self::assertStringContainsString(
            'data-specialty="فلوشیپ ناباروری"',
            $html,
            'A custom specialty must keep its raw stored value.'
        );
        self::assertStringContainsString(
            '<span class="cpms-public-booking__clinician-specialty">فلوشیپ ناباروری</span>',
            $html,
            'A custom specialty must be shown as stored.'
        );

        // Empty specialty publishes no specialty span.
        self::assertSame(
            4,
            preg_match_all('#cpms-public-booking__clinician-specialty#', $html),
            'Exactly the clinicians with a non-empty specialty publish a specialty span.'
        );

        // Hostile custom text stays escaped; no raw markup leaks to the browser.
        self::assertStringContainsString(
            'data-specialty="فلوشیپ ناباروری&quot;',
            $html,
            'Hostile custom text must be attribute-escaped in data-specialty.'
        );
        self::assertStringNotContainsString(
            '<script>alert(1)</script>',
            $html,
            'Persisted hostile custom text must never appear unescaped.'
        );
    }

    public function testSpecialtyFilterOptionsShowLabelsAndPreserveValues(): void
    {
        $this->setConsent($this->clinic);
        Settings::flushCache();

        $html = $this->render('all');

        self::assertStringContainsString('data-mode="discovery"', $html, 'Discovery mode must be announced.');
        // Option value = raw key, label = resolved catalogue label.
        self::assertStringContainsString(
            '<option value="cardiology">متخصص قلب و عروق</option>',
            $html,
            'The filter option for a catalogue slug must show the Persian label.'
        );
        // Option value = raw custom text, label unchanged.
        self::assertStringContainsString(
            '<option value="فلوشیپ ناباروری">فلوشیپ ناباروری</option>',
            $html,
            'The filter option for a custom specialty must keep its raw value and label.'
        );
        // The raw catalogue slug must not leak into the visible label.
        self::assertStringNotContainsString(
            '<option value="cardiology">cardiology</option>',
            $html,
            'A catalogue slug must not be shown as the visible filter label.'
        );
    }

    // ================= Fixtures and helpers =================

    private function buildFixture(): void
    {
        $now = $this->nowUtcSql();

        $this->org = $this->insertRow('cpms_organizations', [
            'name' => 'Org PDT ' . $this->uid,
            'slug' => 'org-pdt-' . $this->uid,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s']);

        $this->clinic = $this->insertClinic(
            $this->org,
            'Clinic PDT ' . $this->uid,
            'clinic-pdt-' . $this->uid,
            'active',
            $now
        );

        $this->location = $this->insertLocation($this->clinic, 'Main PDT', 'main-pdt-' . $this->uid, $now);

        // c1: no stored title, catalogue slug specialty.
        $this->insertRow('cpms_clinicians', [
            'clinic_id' => $this->clinic,
            'full_name' => 'سارا احمدی',
            'specialty' => 'cardiology',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%d', '%s', '%s']);

        // c2: already titled, catalogue label specialty.
        $this->insertRow('cpms_clinicians', [
            'clinic_id' => $this->clinic,
            'full_name' => 'دکتر رضا کریمی',
            'specialty' => 'متخصص قلب و عروق',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%d', '%s', '%s']);

        // c3: doubled stored title, custom specialty.
        $this->insertRow('cpms_clinicians', [
            'clinic_id' => $this->clinic,
            'full_name' => 'دکتر دکتر علی حسینی',
            'specialty' => 'فلوشیپ ناباروری',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%d', '%s', '%s']);

        // c4: no title, empty specialty.
        $this->insertRow('cpms_clinicians', [
            'clinic_id' => $this->clinic,
            'full_name' => 'زهرا مرادی',
            'specialty' => '',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%d', '%s', '%s']);

        // c5: no title, hostile custom specialty.
        $this->insertRow('cpms_clinicians', [
            'clinic_id' => $this->clinic,
            'full_name' => 'امیر حسنی',
            'specialty' => 'فلوشیپ ناباروری"><script>alert(1)</script>',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%d', '%s', '%s']);

        $this->setConsent($this->clinic);
    }

    private function render(?string $clinicValue): string
    {
        App::boot();
        $atts = $clinicValue === null ? '' : ' clinic_id="' . $clinicValue . '"';

        return (string) do_shortcode('[cpms_public_booking' . $atts . ']');
    }

    private function insertClinic(int $orgId, string $name, string $slug, string $status, string $now): int
    {
        return $this->insertRow('cpms_clinics', [
            'organization_id' => $orgId,
            'name' => $name,
            'slug' => $slug,
            'timezone' => self::TZ_TEHRAN,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s']);
    }

    private function insertLocation(int $clinicId, string $name, string $slug, string $now): int
    {
        return $this->insertRow('cpms_locations', [
            'clinic_id' => $clinicId,
            'name' => $name,
            'slug' => $slug,
            'address' => 'Address ' . $name,
            'timezone' => self::TZ_TEHRAN,
            'is_primary' => 1,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']);
    }

    private function insertRow(string $table, array $data, array $formats): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->insert($wpdb->prefix . $table, $data, $formats);
        self::assertTrue((bool) $ok, 'fixture insert into ' . $table . ' failed: ' . $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture id must be positive/dynamic.');

        return $id;
    }

    private function nowUtcSql(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function setConsent(int $clinicId): void
    {
        $this->setSetting($clinicId, 'public.listing_opt_in', true);
    }

    private function setSetting(int $clinicId, string $key, mixed $value): void
    {
        global $wpdb;
        $table = App::db()->table('cpms_settings');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE clinic_id = %d AND `key` = %s", $clinicId, $key));
        $this->insertRow('cpms_settings', [
            'clinic_id' => $clinicId,
            'key' => $key,
            'value_json' => (string) wp_json_encode($value),
            'updated_at' => $this->nowUtcSql(),
        ], ['%d', '%s', '%s', '%s']);
        Settings::flushCache();
    }
}
