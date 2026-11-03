<?php
/** Source and offline-python gate for Management Portal Persian digits. */
declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

final class ManagementShellPersianDigitsTest extends TestCase
{
    private function plugin_file(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        self::assertFileExists($path, $rel . ' must exist');
        $src = (string) file_get_contents($path);
        self::assertNotSame('', $src, $rel . ' must not be empty');
        return $src;
    }

    public function test_template_counters_and_visible_ids_use_persian_digits(): void
    {
        $shell = $this->plugin_file('templates/management-portal-shell.php');

        self::assertStringContainsString('use ClinicCore\Domain\Time\PersianDigits;', $shell);
        // Counters (counts, never ids) go through format_int.
        self::assertStringContainsString('PersianDigits::format_int( $cpms_mp_loc_count )', $shell);
        self::assertStringContainsString('PersianDigits::format_int( $cpms_mp_sites )', $shell);
        self::assertStringContainsString('PersianDigits::format_int( $cpms_mp_clinics )', $shell);
        // Visible ids go through digits() only: no thousands grouping on ids.
        self::assertStringContainsString("PersianDigits::digits( (int) \$cpms_mp_mid )", $shell);
        self::assertStringContainsString('PersianDigits::digits( $cpms_mp_mgr_uid )', $shell);
        self::assertStringContainsString('PersianDigits::digits( $cpms_mp_staff_uid )', $shell);
        self::assertStringContainsString('PersianDigits::digits( $cpms_mp_clinician_id )', $shell);
        // The raw Latin display forms must not come back.
        $sites_raw = "(string) count( (array) \$cpms_mp_facility['sites'] )";
        $clinics_raw = "(string) count( (array) \$cpms_mp_facility['clinics'] )";
        self::assertStringNotContainsString($sites_raw, $shell);
        self::assertStringNotContainsString($clinics_raw, $shell);
        self::assertStringNotContainsString("(string) \$cpms_mp_row['location_count']", $shell);
        self::assertStringNotContainsString("(string) \$cpms_mp_clinician['id']", $shell);
        self::assertStringNotContainsString("'#' . (int) \$cpms_mp_mid", $shell);
    }

    public function test_machine_values_stay_latin_in_template(): void
    {
        $shell = $this->plugin_file('templates/management-portal-shell.php');

        // data-* attributes keep the raw machine id.
        $org_attr = 'data-cpms-org="<?php echo esc_attr( (string) $cpms_mp_org_view'
            . '[\'organization\'][\'id\'] ); ?>"';
        self::assertStringContainsString($org_attr, $shell);
        self::assertStringContainsString(
            'data-cpms-clinic="<?php echo esc_attr( (string) $cpms_mp_c[\'id\'] ); ?>"',
            $shell
        );
        // URL query ids and option values keep the raw machine id.
        self::assertStringContainsString(
            'add_query_arg( ManagementPortalShell::ORG_PARAM, (string) $cpms_mp_org[\'id\']',
            $shell
        );
        self::assertStringContainsString(
            'add_query_arg( ManagementPortalShell::CLINIC_PARAM, (string) $cpms_mp_clinic[\'id\']',
            $shell
        );
        self::assertStringContainsString(
            '<option value="<?php echo esc_attr( (string) $cpms_mp_clinician_option[\'id\'] ); ?>"',
            $shell
        );
        // The location-ids form value stays a machine (Latin, comma) list.
        self::assertStringContainsString(
            "implode( ',', array_map( 'intval', \$cpms_mp_member['locations'] ) )",
            $shell
        );
    }

    public function test_export_display_expiry_is_persian_and_stored_value_unchanged(): void
    {
        $export = $this->plugin_file('src/Application/Reports/ExportService.php');

        // User-visible notification placeholder: Jalali date, Persian digits.
        self::assertStringContainsString(
            'Jalali::format_display_ymd( substr( $expires_at, 0, 10 ) )',
            $export
        );
        self::assertStringNotContainsString('Jalali::formatYmd(substr(', $export);
        // Stored payload value stays the Gregorian machine timestamp.
        self::assertStringContainsString("'expires_at' => \$expires_at,", $export);
        // Touched locals are snake_case (WPCS changed-line gate), no camelCase left.
        self::assertStringNotContainsString('$expiresAt', $export);
        self::assertStringNotContainsString('$retentionDays', $export);
        self::assertStringContainsString('$retention_days * 86400', $export);
    }

    public function test_python_counter_digit_suite(): void
    {
        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        self::assertNotSame('', $python, 'python3 is required');
        $root = dirname(__DIR__, 3);
        $output = [];
        $exit = 0;
        $bin_dir = escapeshellarg($root . '/tests/bin');
        exec(
            escapeshellarg($python) . ' -m unittest discover -s ' . $bin_dir
            . " -p 'test_rwp_counter_digits.py' 2>&1",
            $output,
            $exit
        );
        self::assertSame(0, $exit, implode("\n", $output));
    }
}
