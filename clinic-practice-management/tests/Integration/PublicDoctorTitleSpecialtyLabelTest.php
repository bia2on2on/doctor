<?php

/**
 * Public directory / booking — doctor title vs specialty label.
 *
 * Regression coverage for the public clinician rendering:
 *  - H1: the clinician name must be published through
 *        `DoctorDisplayName::with_title_or_fallback()` so the title is the
 *        proper Persian display label (title added when missing, collapsed when
 *        the stored value already carries one, never doubled). The public
 *        surface previously rendered the raw stored `full_name`. The
 *        'پزشک' name fallback of `with_title_or_fallback()` is unreachable on
 *        this surface because clinician rows with an empty name are filtered
 *        before rendering (`PublicBookingShortcode::active_clinicians()` and
 *        `PublicClinicDirectory::clinicians_by_clinic()`), so this suite does
 *        not assert it.
 *  - H2: the clinician specialty shown in the clinician item and in the
 *        specialty filter must resolve recognised catalogue values through
 *        `MedicalSpecialtyCatalog` (canonical Persian label) while preserving
 *        raw custom / historical / unknown values. `data-specialty` and the
 *        option `value` carry the resolved label (raw value only when no
 *        catalogue match exists); only the visible label changes.
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

        // Latin 'Dr.' title -> titled Persian label; both raw ('dr') and
        // titled ('دکتر') forms must be searchable through data-search.
        self::assertStringContainsString(
            '<span class="cpms-public-booking__clinician-name">دکتر Smith</span>',
            $html,
            'A Latin Dr. title must be published as the titled Persian label.'
        );
        // data-search publishes the titled name first, then the raw name
        // when the raw contributes search terms not already in the titled
        // form (e.g. 'dr' is not contained in 'دکتر smith').
        self::assertStringContainsString(
            'data-search="دکتر smith dr. smith"',
            $html,
            'data-search must contain both the normalised titled name and the normalised raw name so "dr" and "دکتر smith" both match.'
        );
    }

    // ================= H2 — specialty resolves through the catalogue =================

    public function testClinicianSpecialtyResolvesToCatalogLabelOrPreservesRaw(): void
    {
        $html = $this->render((string) $this->clinic);

        // Catalogue slug -> canonical Persian label; data-specialty carries the
        // SAME resolved value as the filter option so the JS exact-match works.
        self::assertStringContainsString(
            'data-specialty="متخصص قلب و عروق"',
            $html,
            'data-specialty carries the resolved catalogue label (same as the filter option value).'
        );
        self::assertStringContainsString(
            '<span class="cpms-public-booking__clinician-specialty">متخصص قلب و عروق</span>',
            $html,
            'A catalogue slug must show its canonical Persian label.'
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
            5,
            preg_match_all('#cpms-public-booking__clinician-specialty#', $html),
            'Exactly the clinicians with a non-empty specialty publish a specialty span.'
        );

        // F2: no two filter options share visible text (catalogue aliases
        // 'cardiology' and 'متخصص قلب و عروق' resolve to one option).
        preg_match_all('#<option value="([^"]+)">([^<]+)</option>#', $html, $optionMatches, PREG_SET_ORDER);
        $labels = array_map(fn (array $m): string => $m[2], $optionMatches);
        self::assertSame(
            count($labels),
            count(array_unique($labels)),
            'No two filter options may share visible text.'
        );
        // Each option value equals its visible label (both resolved through
        // the same catalogue resolver).
        foreach ( $optionMatches as $match ) {
            self::assertSame(
                $match[1],
                $match[2],
                'Filter option value must equal its resolved visible label.'
            );
        }

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
        // Option value = resolved catalogue label, same as data-specialty;
        // the raw slug must not appear as the option value either.
        self::assertStringContainsString(
            '<option value="متخصص قلب و عروق">متخصص قلب و عروق</option>',
            $html,
            'The filter option value and label are both the resolved catalogue label.'
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

        // c6: Latin 'Dr.' title, catalogue slug specialty — exercises that both
        // the raw name ('dr') and the titled name ('دکتر') are searchable.
        $this->insertRow('cpms_clinicians', [
            'clinic_id' => $this->clinic,
            'full_name' => 'Dr. Smith',
            'specialty' => 'cardiology',
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
