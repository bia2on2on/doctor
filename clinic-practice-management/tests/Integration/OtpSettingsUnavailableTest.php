<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Unreadable Settings at the OTP REST boundary.
 *
 * When a Clinic's settings cannot be read, OTP rate-limit inputs are
 * unverifiable: both routes must fail closed with the established neutral
 * CLINIC_SERVICE_UNAVAILABLE 503 envelope (no raw internal error, no
 * weakened limits, no issued or consumed token), and must recover fully
 * once settings are readable again. Established behavior proven here from
 * the production code path: verify() charges the otp-verify-ip counter
 * BEFORE the settings read (OtpService::verify hits the counter ahead of
 * policy resolution), while request() verifies the snapshot before any
 * counter or quota is touched.
 */
final class OtpSettingsUnavailableTest extends WP_UnitTestCase
{
    private const MOBILE = '09121234567';

    /** @var callable|null */
    private $settingsReadFault = null;

    private bool $previousSuppression = false;

    private ?string $previousRemoteAddr = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        $this->previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->restoreSettingsBulkReads();
        Settings::flushCache();
        if ($this->previousRemoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->previousRemoteAddr;
        }
        parent::tearDown();
    }

    public function testRequestRouteFailsClosedWith503AndRecovers(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.21';

        $this->failSettingsBulkReads();
        $response = $this->dispatch('/clinic/v1/otp/request', [
            'mobile'  => self::MOBILE,
            'purpose' => 'login',
        ]);

        self::assertInstanceOf(\WP_Error::class, $response, 'unreadable settings must fail the request route closed');
        self::assertSame('CLINIC_SERVICE_UNAVAILABLE', $response->get_error_code());
        self::assertSame(503, (int) ($response->get_error_data()['status'] ?? 0));
        self::assertSame('سرویس موقتاً در دسترس نیست.', $response->get_error_message(), 'only the neutral service-unavailable message may be exposed');
        self::assertSame(0, $this->tokenCount(self::MOBILE), 'no challenge may be issued while limits are unverifiable');

        $this->restoreSettingsBulkReads();
        Settings::flushCache();

        $response = $this->dispatch('/clinic/v1/otp/request', [
            'mobile'  => self::MOBILE,
            'purpose' => 'login',
        ]);

        self::assertInstanceOf(\WP_REST_Response::class, $response, 'the request route must recover once settings are readable');
        self::assertSame(200, $response->get_status());
        self::assertSame(1, $this->tokenCount(self::MOBILE), 'a challenge must be issued after recovery');
    }

    public function testVerifyRouteFailsClosedWithoutConsumingAndChargesIpCounterFirst(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.22';
        $token_id = $this->seedToken('123456');

        $this->failSettingsBulkReads();
        $response = $this->dispatch('/clinic/v1/otp/verify', [
            'mobile'  => self::MOBILE,
            'code'    => '123456',
            'purpose' => 'login',
        ]);

        self::assertInstanceOf(\WP_Error::class, $response, 'unreadable settings must fail the verify route closed');
        self::assertSame('CLINIC_SERVICE_UNAVAILABLE', $response->get_error_code());
        self::assertSame(503, (int) ($response->get_error_data()['status'] ?? 0));
        self::assertSame('سرویس موقتاً در دسترس نیست.', $response->get_error_message());
        self::assertSame(1, $this->verifyIpHits('203.0.113.22'), 'verify charges the otp-verify-ip counter before the settings read (established production order)');
        self::assertNull($this->tokenConsumedAt($token_id), 'the challenge must not be consumed while limits are unverifiable');

        $this->restoreSettingsBulkReads();
        Settings::flushCache();

        $response = $this->dispatch('/clinic/v1/otp/verify', [
            'mobile'  => self::MOBILE,
            'code'    => '123456',
            'purpose' => 'login',
        ]);

        self::assertInstanceOf(\WP_REST_Response::class, $response, 'the verify route must recover once settings are readable');
        self::assertSame(200, $response->get_status());
        self::assertNotNull($this->tokenConsumedAt($token_id), 'the challenge must be consumed by the recovered verify');
    }

    public function testRestrictivePersistedLimitsApplyAfterRecovery(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.23';

        // Restrictive persisted limits: one code per day, no cooldown.
        App::settings()->set('otp.cooldown_sec', 0);
        App::settings()->set('otp.daily_max', 1);
        Settings::flushCache();

        $first = $this->dispatch('/clinic/v1/otp/request', [
            'mobile'  => self::MOBILE,
            'purpose' => 'login',
        ]);
        self::assertInstanceOf(\WP_REST_Response::class, $first);
        self::assertSame(200, $first->get_status());

        $second = $this->dispatch('/clinic/v1/otp/request', [
            'mobile'  => self::MOBILE,
            'purpose' => 'login',
        ]);
        self::assertInstanceOf(\WP_Error::class, $second, 'the restrictive persisted daily limit (1, not the default 3) must deny the second request');
        self::assertSame('CLINIC_OTP_DAILY_LIMIT', $second->get_error_code());
        self::assertSame(429, (int) ($second->get_error_data()['status'] ?? 0));
        self::assertSame(1, $this->tokenCount(self::MOBILE), 'the denied request must not issue a second challenge');
    }

    // ---------------------------------------------------------------

    private function dispatch(string $route, array $params): mixed
    {
        $request = new \WP_REST_Request('POST', $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_get_server()->dispatch($request);
    }

    private function seedToken(string $code): int
    {
        $db = App::db();
        $pepper = defined('CPMS_PEPPER') && (string) CPMS_PEPPER !== ''
            ? (string) CPMS_PEPPER
            : (string) get_option('cpms_otp_pepper', '');
        if ($pepper === '') {
            try {
                App::otpService()->request(self::MOBILE, 'login');
            } catch (\Throwable) {
                // Rate limiting is irrelevant for pepper provisioning here.
            }
            $pepper = (string) get_option('cpms_otp_pepper', '');
        }

        $db->insert('cpms_otp_tokens', [
            'mobile'     => self::MOBILE,
            'purpose'    => 'login',
            'code_hash'  => OtpPolicy::hashCode($code, $pepper),
            'expires_at' => gmdate('Y-m-d H:i:s.000', time() + 300),
            'attempts'   => 0,
            'created_at' => $db->nowUtcSql(),
        ]);

        return (int) $db->wpdb_last_insert_id();
    }

    private function tokenCount(string $mobile): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE mobile = %s',
            [$mobile]
        );
    }

    private function tokenConsumedAt(int $token_id): ?string
    {
        $value = App::db()->fetchValue(
            'SELECT consumed_at FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE id = %d',
            [$token_id]
        );

        return $value === null ? null : (string) $value;
    }

    private function verifyIpHits(string $ip): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_rate_limits';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed test table
        $sql = "SELECT hits FROM {$table} WHERE window_key = %s AND window_id = %d";

        return (int) $wpdb->get_var(
            $wpdb->prepare($sql, 'otp-verify-ip:' . $ip, intdiv(time(), 3600))
        );
    }

    private function failSettingsBulkReads(): void
    {
        global $wpdb;
        self::assertNull($this->settingsReadFault, 'the settings read fault must not be installed twice');
        $clinic_id      = (int) App::scope()->clinicId;
        $settings_table = $wpdb->prefix . 'cpms_settings';

        $fault = static function ($query) use ($clinic_id, $settings_table) {
            $query = (string) $query;
            if (
                str_contains($query, 'SELECT `key`, value_json FROM ' . $settings_table)
                && preg_match('/WHERE clinic_id = ' . $clinic_id . '\\s*$/', $query) === 1
            ) {
                return str_replace('FROM ' . $settings_table, 'FROM ' . $settings_table . '_read_failure', $query);
            }

            return $query;
        };

        $this->previousSuppression = (bool) $wpdb->suppress_errors(true);
        $this->settingsReadFault   = $fault;
        add_filter('query', $fault, 5, 1);
    }

    private function restoreSettingsBulkReads(): void
    {
        if ($this->settingsReadFault === null) {
            return;
        }
        remove_filter('query', $this->settingsReadFault, 5);
        $this->settingsReadFault = null;

        global $wpdb;
        $wpdb->suppress_errors($this->previousSuppression);
    }
}
