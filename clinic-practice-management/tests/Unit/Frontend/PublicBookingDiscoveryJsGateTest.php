<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Client regression for the Owner's public Clinic/doctor discovery mission
 * (clinic_id="all" directory + shared-clinic binding + weekly Jalali calendar
 * + doctor-directory filters) asserted on the production JS source.
 *
 * No WP, no DB — the JS file is the source of truth for what the browser
 * sends and renders. Covers:
 *
 *  A. Shared-clinic send binding: A1 query, A4 payloads, B1 body and A2
 *     payload all carry `clinic_id` from the server-published config, so the
 *     server can re-enforce participation + slot.clinic_id on every hop.
 *  B. Multi-root selection safety: stored selection is read through the
 *     clinic-guarded reader (no cross-clinic continuation).
 *  C. Weekly calendar is server-scaffolded: week-nav / day-empty markup,
 *     server weekday_names, jalali labels straight from A1 data — no
 *     Persian weekday literals in JS, no bookability math.
 *  D. Doctor-directory filters: server-rendered search/specialty/reset +
 *     mirrored Persian/Arabic normalization.
 */
final class PublicBookingDiscoveryJsGateTest extends TestCase
{
    private string $js;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/assets/js/cpms-public-booking.js';
        self::assertFileExists($path, 'public booking JS must exist');
        $this->js = (string) file_get_contents($path);
        self::assertNotSame('', $this->js);
    }

    public function testA1AvailabilityQueryBindsSelectedClinic(): void
    {
        self::assertStringContainsString(
            "config.availability_path + '?clinician_id=' + encodeURIComponent(String(clinicianId))",
            $this->js,
            'A1 must keep building the existing clinician_id query'
        );
        self::assertStringContainsString(
            "+ '&clinic_id=' + encodeURIComponent(String(toInt(config.clinic_id)))",
            $this->js,
            'A1 must additionally send the selected clinic_id so the server can scope availability to it'
        );
    }

    public function testA4B1AndSelectionCarryClinicId(): void
    {
        self::assertGreaterThanOrEqual(
            4,
            substr_count($this->js, 'clinic_id: toInt(config.clinic_id)'),
            'A4 payloads (slot click + restore/nearby), lastSelection stores must carry clinic_id'
        );
        self::assertStringContainsString(
            'body.clinic_id = toInt(config.clinic_id);',
            $this->js,
            'B1 hold must send the selected clinic_id (server re-verifies participation and slot.clinic_id)'
        );
        self::assertStringContainsString(
            'payload.clinic_id = toInt(config.clinic_id);',
            $this->js,
            'A2 otp/request must send the selected clinic_id with the preserved selection'
        );
    }

    public function testStoredSelectionIsClinicGuardedForMultiRoot(): void
    {
        self::assertStringContainsString(
            'function readStoredSelectionForClinic(clinicId)',
            $this->js,
            'a clinic-guarded reader must exist so two shortcode roots never continue each other\'s selection'
        );
        self::assertGreaterThanOrEqual(
            2,
            substr_count($this->js, 'readStoredSelectionForClinic(config.clinic_id)'),
            'A2 request and post-OTP auto-restore must both read through the clinic guard'
        );
        // Legacy contract keys stay untouched (BC with stored selections).
        self::assertStringContainsString(
            "var SELECT_KEY = 'cpms-public-booking:selection:v1'",
            $this->js,
            'the historical selection key must not change'
        );
    }

    public function testWeeklyCalendarUsesServerScaffold(): void
    {
        foreach ([
            'week-nav',
            'week-prev',
            'week-next',
            'week-first',
            'week-empty',
        ] as $role) {
            self::assertStringContainsString(
                sprintf('data-role="%s"', $role),
                $this->js,
                "the weekly calendar must consume the server-rendered [data-role=\"{$role}\"] markup"
            );
        }
        self::assertStringContainsString(
            "templateNode(root, 'day-empty')",
            $this->js,
            'empty week-day cells must clone the server-rendered day-empty template'
        );
        self::assertStringContainsString(
            'config.weekday_names',
            $this->js,
            'Persian weekday names must come from the server-published config, not from JS literals'
        );
        self::assertStringNotContainsString(
            'شنبه',
            $this->js,
            'JS must contain ZERO Persian weekday literals (server renders them)'
        );
        self::assertStringContainsString('function weekStartDayNum(', $this->js, 'Saturday-anchored week bucketing must exist');
        self::assertStringContainsString('function paintCurrentWeek(', $this->js, 'week painting must exist');
        self::assertStringContainsString('function changeWeek(', $this->js, 'prev/next navigation must exist');
        self::assertStringContainsString(
            'ensureWeekForDate(selection.slot_date);',
            $this->js,
            'restored/nearby selections must jump to their own week'
        );
        // Jalali labels stay server-provided — no conversion in JS.
        self::assertStringNotContainsString('toLocaleDateString', $this->js, 'no locale date formatting in the booking script');
    }

    public function testDoctorDirectoryFiltersAreServerScaffolded(): void
    {
        foreach ([
            'doctor-search',
            'specialty-filter',
            'doctor-filter-reset',
            'directory-empty',
        ] as $role) {
            self::assertStringContainsString(
                sprintf('data-role="%s"', $role),
                $this->js,
                "filters must consume server-rendered [data-role=\"{$role}\"] markup"
            );
        }
        self::assertStringContainsString(
            'function normalizeDoctorQuery(',
            $this->js,
            'the Persian/Arabic query normalizer (mirror of the server) must exist'
        );
        // Normalizer parity essentials: Arabic yeh/kaf folded to Persian forms.
        self::assertStringContainsString('\\u064A', $this->js, 'Arabic yeh must be folded');
        self::assertStringContainsString('\\u0643', $this->js, 'Arabic kaf must be folded');
        self::assertStringContainsString(
            'function applyDoctorFilters(',
            $this->js,
            'filter application over server-rendered data-search/data-specialty attributes must exist'
        );
    }
}
