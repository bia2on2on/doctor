<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Bootstrap\App;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Tests\Integration\Fixtures\IranV1Fixture;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/IranV1Fixture.php';

/**
 * `pricing_context.locations` — the display projection behind the staff pricing
 * Location picker.
 *
 * Runtime proof (real WordPress + DB) that the list contains ONLY the active
 * Locations of the trusted Clinic that the actor may target, that another
 * Clinic's Location never appears, that an inactive Location never appears, and
 * that clinic-wide `0` is never a list entry (it is the picker's own explicit
 * option). The list must also mirror the write authorization: a Location that
 * is missing from the list is rejected by the fee-write path.
 *
 * REST money strings and `currency`/`currency_label` stay untouched here; this
 * suite only adds the new projection.
 */
final class PricingContextLocationsTest extends WP_UnitTestCase
{
    use IranV1Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->iv1_boot();
    }

    protected function tearDown(): void
    {
        $this->iv1_reset();
        parent::tearDown();
    }

    /**
     * @return array<string, int>
     */
    private function world(): array
    {
        $org       = $this->iv1_org('pricing-locations');
        $clinic    = $this->iv1_clinic($org);
        $foreign   = $this->iv1_clinic($this->iv1_org('pricing-locations-foreign'));
        $location  = $this->iv1_location($clinic, 'آ موقعیت نخست');
        $location2 = $this->iv1_location($clinic, 'ب موقعیت دوم');
        $inactive  = $this->iv1_location($clinic, 'ج موقعیت غیرفعال');
        $foreign_location = $this->iv1_location($foreign, 'موقعیت مطب دیگر');

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'cpms_locations',
            ['is_active' => 0],
            ['id' => $inactive]
        );

        $doctor = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'loc_doc');
        $membership = $this->iv1_membership($doctor, $clinic, RolesAndCapabilities::ROLE_DOCTOR);
        $this->iv1_clinician($clinic, $doctor, 'پزشک موقعیت');

        $scoped = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'loc_doc_scoped');
        $scoped_membership =
            $this->iv1_membership($scoped, $clinic, RolesAndCapabilities::ROLE_DOCTOR);
        $this->iv1_restrict_to_location($scoped_membership, $location2);
        $this->iv1_clinician($clinic, $scoped, 'پزشک محدود');

        return compact(
            'org',
            'clinic',
            'foreign',
            'location',
            'location2',
            'inactive',
            'foreign_location',
            'doctor',
            'membership',
            'scoped'
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return list<int>
     */
    private function ids(array $context): array
    {
        $ids = [];
        foreach ((array) $context['locations'] as $row) {
            $ids[] = (int) $row['id'];
        }

        sort($ids);

        return $ids;
    }

    public function testClinicScopedDoctorSeesOnlyActiveLocationsOfTheTrustedClinic(): void
    {
        $world = $this->world();
        wp_set_current_user((int) $world['doctor']);

        $context = $this->iv1_scope(
            (int) $world['clinic'],
            fn (): array => App::visit_pricing_service()->context((int) $world['doctor'])
        );

        self::assertSame(
            [$world['location'], $world['location2']],
            $this->ids($context),
            'only the trusted Clinic\'s ACTIVE Locations are offered'
        );
        self::assertNotContains(
            (int) $world['inactive'],
            $this->ids($context),
            'an inactive Location is never offered'
        );
        self::assertNotContains(
            (int) $world['foreign_location'],
            $this->ids($context),
            'another Clinic\'s Location never leaks into the projection'
        );
        self::assertNotContains(
            0,
            $this->ids($context),
            'clinic-wide 0 is a target, not a Location row'
        );

        $names = [];
        foreach ((array) $context['locations'] as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }
        self::assertSame('آ موقعیت نخست', $names[$world['location']]);
        self::assertSame('ب موقعیت دوم', $names[$world['location2']]);

        self::assertSame(0, (int) $context['location_id'], 'the default target is clinic-wide');
        self::assertSame('IRR', (string) $context['currency'], 'REST currency unchanged');
        self::assertSame('ریال', (string) $context['currency_label'], 'REST label unchanged');
        self::assertNotSame('', trim((string) $context['fee_policy']));
    }

    public function testLocationScopedDoctorSeesOnlyTheGrantedLocationAndWritesStayDenied(): void
    {
        $world = $this->world();
        wp_set_current_user((int) $world['scoped']);

        $context = $this->iv1_scope(
            (int) $world['clinic'],
            fn (): array => App::visit_pricing_service()->context((int) $world['scoped'])
        );

        self::assertSame(
            [$world['location2']],
            $this->ids($context),
            'a Location-scoped membership sees exactly its grant'
        );

        try {
            $this->iv1_scope(
                (int) $world['clinic'],
                fn (): array => App::visit_pricing_service()->set_default_fee(
                    (int) $world['scoped'],
                    (int) $world['location'],
                    1000
                )
            );
            self::fail('a Location outside the grant must be rejected');
        } catch (FinanceException $exception) {
            self::assertSame('CLINIC_PERMISSION_DENIED', $exception->errorCode);
            self::assertSame(403, $exception->httpStatus);
        }
    }

    /**
     * The picker keeps clinic-wide as an explicit option and posts the same
     * `location_id` field the service already authorizes.
     */
    public function testPricingTemplateKeepsClinicWideOptionAndLocationIdField(): void
    {
        $template = (string) file_get_contents(
            \dirname(__DIR__, 2) . '/templates/staff-pricing.php'
        );

        self::assertMatchesRegularExpression(
            '/<option value="0">/',
            $template,
            'the picker keeps an explicit clinic-wide option'
        );
        self::assertStringContainsString('data-role="pricing-default-location"', $template);
        self::assertStringContainsString('location_id: locationId', $template);
        self::assertStringContainsString('renderLocations(data.locations)', $template);
        self::assertStringContainsString('window.CpmsMoney', $template);
    }
}
