<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Integration\Fixtures\IranV1Fixture;
use WP_UnitTestCase;

/** CI-only, synthetic, comparable production-service measurements (not HTTP). */
final class ClassicV1PerformanceProbeTest extends WP_UnitTestCase
{
    use IranV1Fixture;

    public function testMeasurePortalReadsClinicalWritesAndMigrationRecheck(): void
    {
        $this->iv1_boot();
        $org = $this->iv1_org('perf');
        $clinic = $this->iv1_clinic($org);
        $location = $this->iv1_location($clinic);
        $user = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'perf_doctor');
        $this->iv1_membership($user, $clinic, RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->iv1_clinician($clinic, $user);
        (new \ClinicCore\Infrastructure\Repository\MembershipRepository(App::db()))->replace_clinician_locations($clinician, [$location], $location);
        $patient = $this->iv1_patient($clinic);
        wp_set_current_user($user);
        $headers = $this->iv1_headers($clinic, $location);
        $samples = [];
        $measure = function (string $name, callable $fn) use (&$samples): void {
            global $wpdb;
            $q = $wpdb->num_queries;
            $t = hrtime(true);
            $result = $fn();
            $samples[$name][] = ['ms' => (hrtime(true) - $t) / 1e6, 'queries' => $wpdb->num_queries - $q];
            self::assertLessThan(300, $result->get_status(), $name . ': ' . wp_json_encode($result->get_data()));
        };
        // Warm once; report every measured sample, never discard slow samples.
        $this->iv1_dispatch('GET', '/clinic/v1/doctor/portal/today', [], $headers);
        for ($i = 0; $i < 12; ++$i) {
            $visit = $this->iv1_visit($clinic, $location, $patient, $clinician, 'in_consultation');
            $measure('queue', fn() => $this->iv1_dispatch('GET', '/clinic/v1/doctor/portal/today', [], $headers));
            $measure('record', fn() => $this->iv1_dispatch('GET', '/clinic/v1/visits/' . $visit . '/record', [], $headers));
            $measure('note_post', fn() => $this->iv1_dispatch('POST', '/clinic/v1/visits/' . $visit . '/notes', [], $headers, ['category' => 'chief_complaint', 'visibility' => 'patient_visible', 'content_text' => 'Synthetic performance note']));
            $measure('prescription_post', fn() => $this->iv1_dispatch('POST', '/clinic/v1/visits/' . $visit . '/prescriptions', [], $headers, ['items' => [['generic_name' => 'Synthetic', 'dose' => '1', 'frequency' => 'daily']]]));
            $measure('complete_post', fn() => $this->iv1_dispatch('POST', '/clinic/v1/visits/' . $visit . '/complete', [], $headers, []));
            $q = $GLOBALS['wpdb']->num_queries;
            $t = hrtime(true);
            App::migrations()->migrate();
            $samples['migration_recheck'][] = ['ms' => (hrtime(true) - $t) / 1e6, 'queries' => $GLOBALS['wpdb']->num_queries - $q];
        }
        $report = ['schema' => 1, 'kind' => 'in_process_rest_dispatch_not_http', 'wp' => get_bloginfo('version'), 'db' => $GLOBALS['wpdb']->db_version(), 'samples' => $samples];
        $path = getenv('CPMS_PERF_OUTPUT');
        if (is_string($path) && preg_match('~\A/tmp/classic-v1/[A-Za-z0-9._-]+\.json\z~D', $path)) {
            wp_mkdir_p(dirname($path));
            file_put_contents($path, wp_json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        self::assertCount(12, $samples['complete_post']);
        $this->iv1_reset();
    }
}
