<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Onboarding\FacilitySetupException;
use ClinicCore\Application\Onboarding\FacilitySetupService;
use ClinicCore\Application\Onboarding\FacilityTypeCatalog;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * Classic RTL top-down setup of a multi-Clinic facility (hospital outpatient
 * network or medical building), adapted to the facility type chosen first.
 *
 * Every selector (Organization, Clinic, site, room, user) is explicit and has no
 * default; GET is read-only; every write is a nonce-protected admin-post request
 * whose authority is re-established by FacilitySetupService (Organization authority
 * for Organization steps, the actor's own Clinic membership + CONFIG for Clinic
 * steps). WordPress Administrator status grants nothing here.
 */
final class FacilitySetupPage {

    public const PAGE   = 'cpms-facility-setup';
    public const ACTION = 'cpms_facility_setup';
    public const NONCE  = 'cpms_facility_setup';

    private const OPS = [ 'start', 'ack', 'site', 'clinic', 'manager', 'room', 'clinician', 'staff', 'schedule', 'booking', 'activate' ];

    /**
     * Staff roles offered by the staff step (labels); the service re-validates.
     */
    private const STAFF_ROLE_LABELS = [
        RolesAndCapabilities::ROLE_SECRETARY  => 'منشی / پذیرش',
        RolesAndCapabilities::ROLE_ACCOUNTANT => 'حسابدار / امور مالی',
    ];

    /**
     * Explicit DENY choices (only narrow a role preset; never a GRANT).
     */
    private const STAFF_DENY_LABELS = [
        RolesAndCapabilities::INVOICE_ADJUST => 'اصلاح مبلغ فاکتور (حساس)',
        RolesAndCapabilities::INVOICE_VOID   => 'ابطال فاکتور (حساس)',
        RolesAndCapabilities::PAYMENT_VOID   => 'ابطال پرداخت (حساس — فقط نقش مالی)',
        RolesAndCapabilities::EXPORT         => 'خروجی CSV (حساس — فقط نقش مالی)',
        RolesAndCapabilities::FILE_READ      => 'مشاهدهٔ فایل پزشکی (فقط نقش پذیرش)',
        RolesAndCapabilities::PATIENT_UPDATE => 'ویرایش اطلاعات بیمار (فقط نقش پذیرش)',
    ];

    private const DAYS = [ 'شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه' ];

    public static function menu(): void {
        add_submenu_page(
            OrganizationManagerPage::PAGE,
            'راه‌اندازی مرکز چنددرمانگاهی',
            'راه‌اندازی مرکز چنددرمانگاهی',
            'read',
            self::PAGE,
            [ self::class, 'render' ]
        );
    }

    public static function service(): FacilitySetupService {
        return new FacilitySetupService(
            App::db(),
            App::organization_clinic_provisioning_service(),
            App::locationService(),
            App::locationRepository(),
            App::authorization_service(),
            App::membership_service(),
            new MembershipRepository( App::db() ),
            App::clinicianRepository(),
            App::scheduleService(),
            App::settingsFactory(),
            App::audit()
        );
    }

    /**
     * Steps of the Organization journey for one flow (facility-adaptive).
     *
     * @return array<string, string>
     */
    public static function steps( string $flow ): array {
        if ( 'medical_building' === $flow ) {
            return [
                'identity' => 'نوع مرکز و هویت ساختمان/سازمان',
                'sites'    => 'ساختمان و نشانی',
                'clinics'  => 'مطب‌ها/درمانگاه‌های مستقل',
                'managers' => 'مدیر مستقل هر درمانگاه',
                'rooms'    => 'مکان‌ها و اتاق‌ها',
                'review'   => 'بازبینی نهایی و فعال‌سازی',
            ];
        }

        return [
            'identity'   => 'نوع مرکز و هویت بیمارستان',
            'sites'      => 'ساختمان‌ها و سایت‌های فیزیکی',
            'clinics'    => 'درمانگاه‌های سرپایی',
            'managers'   => 'مدیر هر درمانگاه',
            'rooms'      => 'اتاق‌ها و مکان‌های ویزیت',
            'clinicians' => 'تخصیص پزشکان به درمانگاه و اتاق',
            'staff'      => 'کارکنان و مجوزهای صریح',
            'schedules'  => 'برنامهٔ هفتگی هر پزشک در هر اتاق',
            'booking'    => 'سیاست رزرو و ظرفیت',
            'review'     => 'بازبینی یکپارچه و فعال‌سازی',
        ];
    }

    public static function render(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'دسترسی ندارید.', 'cpms' ), 403 );
        }
        $actor_id      = (int) get_current_user_id();
        $service       = self::service();
        $organizations = $service->organizations( $actor_id );
        $notice_key    = 'cpms_facility_notice_' . $actor_id;
        $notice        = get_transient( $notice_key );
        if ( false !== $notice ) {
            delete_transient( $notice_key );
        }

        echo '<div class="wrap cpms-facility-setup" dir="rtl" style="max-width:100%;box-sizing:border-box;">';
        echo '<h1>راه‌اندازی مرکز چنددرمانگاهی (از بالا به پایین)</h1>';
        echo '<p class="description">ترتیب: نوع مرکز → سازمان/هویت → ساختمان‌ها → درمانگاه‌ها → مدیران → اتاق‌ها → پزشکان و کارکنان → برنامهٔ هفتگی → سیاست رزرو → بازبینی و فعال‌سازی. مرز مستأجر همان «سازمان ← درمانگاه ← مکان» است و هیچ درمانگاه یا مکانی حدس زده نمی‌شود.</p>';
        if ( is_string( $notice ) && '' !== $notice ) {
            $is_error = str_starts_with( $notice, 'خطا' );
            echo '<div class="notice ' . ( $is_error ? 'notice-error' : 'notice-success' ) . '" data-cpms-facility-notice="' . ( $is_error ? 'error' : 'ok' ) . '"><p>' . esc_html( $notice ) . '</p></div>';
        }
        if ( [] === $organizations ) {
            echo '<div class="notice notice-warning" data-cpms-facility-setup="denied"><p>برای این حساب، عضویت فعال و مجاز مدیر سازمان وجود ندارد. نقش مدیر وردپرس به‌تنهایی اجازهٔ راه‌اندازی سازمانی، ایجاد درمانگاه یا دسترسی به درمانگاه را نمی‌دهد.</p></div></div>';
            return;
        }

        $requested = self::requested_int( 'organization_id' );
        $allowed   = array_map( static fn ( array $org ): int => (int) $org['id'], $organizations );
        if ( null === $requested || ! in_array( $requested, $allowed, true ) ) {
            if ( null !== $requested ) {
                echo '<div class="notice notice-error" data-cpms-facility-setup="org-denied"><p>سازمان درخواست‌شده برای این حساب مجاز نیست؛ هیچ اطلاعاتی نمایش داده نمی‌شود.</p></div>';
            }
            self::render_organization_choice( $organizations );
            echo '</div>';
            return;
        }

        $organization_id = $requested;
        try {
            $plan = $service->plan( $actor_id, $organization_id );
        } catch ( FacilitySetupException $e ) {
            echo '<div class="notice notice-error" data-cpms-facility-setup="org-denied"><p>' . esc_html( $e->getMessage() ) . '</p></div></div>';
            return;
        }

        $org_name = '';
        foreach ( $organizations as $org ) {
            if ( (int) $org['id'] === $organization_id ) {
                $org_name = (string) $org['name'];
            }
        }
        $flow  = null === $plan ? '' : (string) $plan['flow'];
        $steps = self::steps( '' === $flow ? 'hospital_network' : $flow );
        $step  = self::requested_step( $steps, null === $plan ? 'identity' : 'review' );
        if ( null === $plan ) {
            $step = 'identity';
        }

        echo '<p data-cpms-facility-org="' . esc_attr( (string) $organization_id ) . '">سازمان انتخاب‌شده: <strong>' . esc_html( $org_name ) . '</strong>';
        echo ' — <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">تغییر سازمان</a></p>';
        if ( null !== $plan ) {
            $profile = FacilityTypeCatalog::type_profile( (string) $plan['facility_type'] );
            echo '<p data-cpms-facility-flow="' . esc_attr( $flow ) . '">نوع مرکز: <strong>' . esc_html( (string) ( FacilityTypeCatalog::find( (string) $plan['facility_type'] )['label'] ?? '' ) ) . '</strong> — ' . esc_html( (string) $plan['display_name'] ) . ' (' . esc_html( $profile['unit'] ) . ')</p>';
            if ( 'hospital_network' === $flow ) {
                echo '<div class="notice notice-warning inline" data-cpms-inpatient-unsupported="1"><p>بخش بستری و سامانهٔ جامع بیمارستانی (HIS) پشتیبانی نمی‌شود؛ این راه‌اندازی فقط درمانگاه‌ها و فرایندهای سرپایی را پوشش می‌دهد.</p></div>';
            }
            if ( '' !== (string) $plan['activated_at'] ) {
                echo '<div class="notice notice-success inline" data-cpms-facility-activated="1"><p>این راه‌اندازی بازبینی و فعال شده است. افزودن درمانگاه جدید آن را تا فعال‌سازی دوباره در حالت پیش‌نویس نگه می‌دارد.</p></div>';
            }
        }

        self::render_nav( $organization_id, $steps, $step, null !== $plan );
        echo '<div class="card" style="max-width:100%;box-sizing:border-box;" data-cpms-facility-step="' . esc_attr( $step ) . '">';
        echo '<h2>' . esc_html( $steps[ $step ] ?? '' ) . '</h2>';
        switch ( $step ) {
            case 'identity':
                self::render_identity( $organization_id, $plan );
                break;
            case 'sites':
                self::render_sites( $organization_id, (array) $plan );
                break;
            case 'clinics':
                self::render_clinics( $organization_id, (array) $plan );
                break;
            case 'managers':
                self::render_managers( $organization_id, (array) $plan );
                break;
            case 'rooms':
                self::render_rooms( $organization_id, (array) $plan );
                break;
            case 'clinicians':
                self::render_clinicians( $actor_id, $organization_id, (array) $plan );
                break;
            case 'staff':
                self::render_staff( $actor_id, $organization_id, (array) $plan );
                break;
            case 'schedules':
                self::render_schedules( $actor_id, $organization_id, (array) $plan );
                break;
            case 'booking':
                self::render_booking( $organization_id, (array) $plan );
                break;
            default:
                self::render_review( $actor_id, $organization_id );
        }
        echo '</div></div>';
    }

    public static function handle(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'دسترسی ندارید.', 'cpms' ), 403 );
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
            wp_die( esc_html__( 'درخواست نامعتبر است.', 'cpms' ), 403 );
        }
        $actor_id        = (int) get_current_user_id();
        $organization_id = isset( $_POST['organization_id'] ) ? absint( $_POST['organization_id'] ) : 0;
        $op              = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
        $next_step       = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'review';
        $field           = static function ( string $key ): string {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above in handle().
            return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
        };
        $room_keys       = isset( $_POST['room_keys'] ) && is_array( $_POST['room_keys'] ) ? array_values( array_map( 'sanitize_key', wp_unslash( $_POST['room_keys'] ) ) ) : [];
        $deny_caps       = isset( $_POST['deny_caps'] ) && is_array( $_POST['deny_caps'] ) ? array_values( array_map( 'sanitize_key', wp_unslash( $_POST['deny_caps'] ) ) ) : [];

        $notice = self::dispatch( $actor_id, $organization_id, $op, $field, $room_keys, $deny_caps );
        set_transient( 'cpms_facility_notice_' . $actor_id, $notice, 120 );

        $args = [
            'page'            => self::PAGE,
            'organization_id' => $organization_id,
            'step'            => $next_step,
        ];
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Pure dispatcher (testable without a redirect): returns the Persian notice.
     *
     * @param callable(string): string $field
     * @param list<string>             $room_keys
     * @param list<string>             $deny_caps
     */
    public static function dispatch( int $actor_id, int $organization_id, string $op, callable $field, array $room_keys, array $deny_caps = [] ): string {
        if ( ! in_array( $op, self::OPS, true ) ) {
            return 'خطا: عملیات نامعتبر است.';
        }
        $service = self::service();
        try {
            switch ( $op ) {
                case 'start':
                    $service->start( $actor_id, $organization_id, $field( 'facility_type' ), $field( 'structure' ), $field( 'display_name' ) );
                    return 'نوع مرکز و هویت ثبت شد.';
                case 'ack':
                    $service->acknowledge_outpatient( $actor_id, $organization_id, '1' === $field( 'outpatient_ack' ) );
                    return 'محدودهٔ فقط سرپایی صریحاً تأیید شد.';
                case 'site':
                    $service->add_site( $actor_id, $organization_id, $field( 'site_label' ), $field( 'site_address' ) );
                    return 'ساختمان/سایت ثبت شد.';
                case 'clinic':
                    $entry = $service->add_clinic( $actor_id, $organization_id, $field( 'request_key' ), $field( 'clinic_name' ), $field( 'site_key' ), $field( 'timezone' ) );
                    return 'درمانگاه «' . (string) $entry['name'] . '» با مکان اصلی ایجاد شد و تا فعال‌سازی نهایی پذیرش نوبت جدید ندارد.';
                case 'manager':
                    $result = $service->designate_manager( $actor_id, $organization_id, $field( 'clinic_key' ), self::user_id_from( $field( 'user_login' ) ) );
                    return $result['noop'] ? 'این کاربر از قبل مدیر فعال همین درمانگاه است؛ تغییری لازم نبود.' : 'مدیر درمانگاه به‌صورت صریح تعیین شد.';
                case 'room':
                    $service->add_room( $actor_id, $organization_id, $field( 'clinic_key' ), $field( 'request_key' ), $field( 'room_name' ), $field( 'site_key' ) );
                    return 'اتاق/مکان ویزیت ایجاد شد.';
                case 'clinician':
                    $result = $service->assign_clinician( $actor_id, $organization_id, $field( 'clinic_key' ), self::user_id_from( $field( 'user_login' ) ), $field( 'full_name' ), $room_keys );
                    return $result['profile_created'] ? 'پروفایل پزشک ساخته و به این درمانگاه و اتاق‌ها تخصیص داده شد.' : 'پزشک موجود با عضویت صریح به این درمانگاه و اتاق‌ها تخصیص داده شد.';
                case 'staff':
                    $result = $service->assign_staff( $actor_id, $organization_id, $field( 'clinic_key' ), self::user_id_from( $field( 'user_login' ) ), $field( 'role_key' ), $field( 'access' ), $room_keys, $deny_caps );
                    if ( $result['noop'] ) {
                        return 'همین نقش و محدودهٔ دسترسی از قبل برای این کاربر ثبت شده است؛ تغییری لازم نبود.';
                    }
                    return 'location' === $result['scope_mode']
                        ? 'نقش کارکنان فقط برای ' . count( $result['location_ids'] ) . ' مکان انتخاب‌شده ثبت شد (منع صریح: ' . count( $result['denied'] ) . ').'
                        : 'نقش کارکنان برای کل این درمانگاه ثبت شد (منع صریح: ' . count( $result['denied'] ) . ').';
                case 'schedule':
                    $input  = [
                        'day_of_week'              => (int) $field( 'day_of_week' ),
                        'start_time'               => $field( 'start_time' ),
                        'end_time'                 => $field( 'end_time' ),
                        'appointment_duration_min' => (int) $field( 'duration' ),
                        'slot_capacity'            => (int) $field( 'slot_capacity' ),
                    ];
                    $result = $service->add_weekly_schedule( $actor_id, $organization_id, $field( 'clinic_key' ), (int) $field( 'clinician_id' ), $field( 'room_key' ), $input );
                    return $result['noop'] ? 'این برنامه از قبل ثبت شده است؛ ردیف تکراری ساخته نشد.' : 'برنامهٔ هفتگی ثبت شد.';
                case 'booking':
                    $policy = [
                        'duration' => $field( 'duration' ),
                        'capacity' => $field( 'capacity' ),
                        'lead'     => $field( 'lead' ),
                        'future'   => $field( 'future' ),
                        'cancel'   => $field( 'cancel' ),
                    ];
                    $service->save_booking_policy( $actor_id, $organization_id, $field( 'clinic_key' ), $policy );
                    return 'سیاست رزرو و ظرفیت این درمانگاه ذخیره شد.';
                default:
                    $result = $service->activate( $actor_id, $organization_id, '1' === $field( 'confirm' ) );
                    return 'راه‌اندازی فعال شد؛ ' . count( $result['activated'] ) . ' درمانگاه اکنون نوبت جدید می‌پذیرد.';
            }
        } catch ( FacilitySetupException $e ) {
            return 'خطا: ' . $e->getMessage();
        }
    }

    // ------------------------------------------------------------------
    // Step renderers
    // ------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $organizations
     */
    private static function render_organization_choice( array $organizations ): void {
        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" data-cpms-facility-org-choice="1">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
        echo '<p><label for="cpms_facility_org">سازمان</label><br>';
        echo '<select id="cpms_facility_org" name="organization_id" required style="max-width:100%;"><option value="">انتخاب صریح سازمان</option>';
        foreach ( $organizations as $org ) {
            echo '<option value="' . esc_attr( (string) (int) $org['id'] ) . '">' . esc_html( (string) $org['name'] ) . '</option>';
        }
        echo '</select></p><p><button type="submit" class="button button-primary">ادامه با این سازمان</button></p>';
        echo '<p class="description">فهرست فقط سازمان‌هایی است که در آن‌ها مدیر سازمانِ فعال با اختیار ایجاد درمانگاه هستید؛ هیچ سازمانی پیش‌فرض انتخاب نمی‌شود.</p></form>';
    }

    /**
     * @param array<string, string> $steps
     */
    private static function render_nav( int $organization_id, array $steps, string $current, bool $has_plan ): void {
        echo '<nav aria-label="گام‌های راه‌اندازی سازمانی" style="display:flex;flex-wrap:wrap;gap:6px;margin:12px 0;max-width:100%;">';
        $number = 0;
        foreach ( $steps as $id => $title ) {
            ++$number;
            $label = $number . '. ' . $title;
            $style = 'display:inline-block;box-sizing:border-box;max-width:100%;padding:6px 8px;min-height:36px;text-decoration:none;border:1px solid #c3c4c7;border-radius:3px;';
            if ( $id === $current ) {
                $style .= 'background:#2271b1;color:#fff;';
            }
            if ( ! $has_plan && 'identity' !== $id ) {
                echo '<span data-cpms-facility-nav="' . esc_attr( $id ) . '" style="' . esc_attr( $style . 'color:#787c82;' ) . '">' . esc_html( $label ) . '</span>';
                continue;
            }
            echo '<a data-cpms-facility-nav="' . esc_attr( $id ) . '" aria-current="' . ( $id === $current ? 'step' : 'false' ) . '" style="' . esc_attr( $style ) . '" href="' . esc_url( self::step_url( $organization_id, $id ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
    }

    /**
     * @param array<string, mixed>|null $plan
     */
    private static function render_identity( int $organization_id, ?array $plan ): void {
        $current_type = null === $plan ? '' : (string) $plan['facility_type'];
        self::open_form( $organization_id, 'start', 'identity' );
        echo '<fieldset><legend>نوع مرکز (نخستین انتخاب؛ گام‌های بعدی بر اساس آن تطبیق می‌یابد)</legend>';
        foreach ( FacilityTypeCatalog::all() as $type ) {
            echo '<label style="display:block;margin:4px 0;"><input type="radio" name="facility_type" value="' . esc_attr( $type['id'] ) . '" required ' . checked( $current_type, $type['id'], false ) . '> ' . esc_html( $type['label'] ) . '</label>';
        }
        echo '</fieldset>';
        echo '<p><label for="cpms_facility_structure">ساختار</label><br><select id="cpms_facility_structure" name="structure" style="max-width:100%;">';
        echo '<option value="">مطابق نوع مرکز</option>';
        foreach ( FacilityTypeCatalog::structures() as $id => $label ) {
            echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select></p>';
        echo '<p class="description">این جریان فقط برای بیمارستان (ساختار شبکهٔ درمانی) و ساختمان پزشکی با چند مطب مستقل است؛ مطب و درمانگاه تک‌واحدی همان ویزارد هفت‌گامی «راه‌اندازی» را ادامه می‌دهند.</p>';
        echo '<p><label for="cpms_facility_display_name">نام نمایشی بیمارستان/ساختمان (هویت سازمان)</label><br>';
        echo '<input id="cpms_facility_display_name" name="display_name" maxlength="190" required style="width:100%;max-width:420px;box-sizing:border-box;" value="' . esc_attr( null === $plan ? '' : (string) $plan['display_name'] ) . '"></p>';
        self::close_form( 'ثبت نوع مرکز و هویت' );

        if ( null !== $plan && 'hospital_network' === (string) $plan['flow'] ) {
            echo '<hr><h3>محدودهٔ فقط سرپایی</h3>';
            if ( (int) $plan['outpatient_ack_by'] > 0 ) {
                echo '<p data-cpms-outpatient-ack="1">✅ محدودهٔ فقط سرپایی صریحاً تأیید شده است.</p>';
                return;
            }
            self::open_form( $organization_id, 'ack', 'sites' );
            echo '<p><label><input type="checkbox" name="outpatient_ack" value="1" required> تأیید می‌کنم که فقط درمانگاه‌ها و فرایندهای سرپایی پشتیبانی می‌شود؛ بستری، اتاق عمل بستری و HIS در این محصول وجود ندارد.</label></p>';
            self::close_form( 'ثبت تأیید سرپایی' );
        }
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function render_sites( int $organization_id, array $plan ): void {
        echo '<p class="description">ساختمان/سایت فقط اطلاعات توصیفی (نام و نشانی) است: نه درمانگاه است، نه مکان قابل نوبت‌دهی. نشانی آن روی مکان‌های درمانگاه‌هایی که صریحاً آن را انتخاب می‌کنند ثبت می‌شود.</p>';
        self::render_site_list( $plan );
        self::open_form( $organization_id, 'site', 'sites' );
        self::text_input( 'site_label', 'نام ساختمان/سایت', 120, true );
        self::text_input( 'site_address', 'نشانی', 255, false );
        self::close_form( 'افزودن ساختمان/سایت' );
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function render_clinics( int $organization_id, array $plan ): void {
        $hospital = 'hospital_network' === (string) $plan['flow'];
        echo '<p class="description">' . ( $hospital ? 'هر درمانگاه سرپایی یک Clinic مستقل در همین سازمان است' : 'هر مطب/درمانگاه مستقل یک Clinic جدا با مرز دادهٔ خودش است؛ هیچ داده یا اختیاری میان آن‌ها مشترک نمی‌شود' ) . '. درمانگاه با مکان اصلی در ساختمان انتخاب‌شده ساخته و تا فعال‌سازی نهایی در حالت تعلیق (بدون پذیرش نوبت جدید) نگه داشته می‌شود. ارسال دوبارهٔ همین فرم درمانگاه تکراری نمی‌سازد.</p>';
        if ( $hospital && (int) $plan['outpatient_ack_by'] <= 0 ) {
            echo '<div class="notice notice-warning inline"><p>ابتدا در گام نخست محدودهٔ فقط سرپایی را تأیید کنید.</p></div>';
            return;
        }
        if ( [] === $plan['sites'] ) {
            echo '<div class="notice notice-warning inline"><p>ابتدا دست‌کم یک ساختمان/سایت ثبت کنید.</p></div>';
            return;
        }
        echo '<ul data-cpms-facility-clinics="' . esc_attr( (string) count( $plan['clinics'] ) ) . '">';
        foreach ( $plan['clinics'] as $clinic ) {
            echo '<li data-cpms-facility-clinic="' . esc_attr( (string) $clinic['key'] ) . '">' . esc_html( (string) $clinic['name'] ) . ' — ' . esc_html( self::site_label( $plan, (string) $clinic['site_key'] ) ) . '</li>';
        }
        echo '</ul>';
        self::open_form( $organization_id, 'clinic', 'clinics' );
        echo '<input type="hidden" name="request_key" value="' . esc_attr( FacilitySetupService::new_request_key() ) . '">';
        self::text_input( 'clinic_name', $hospital ? 'نام درمانگاه سرپایی' : 'نام مطب/درمانگاه مستقل', 150, true );
        self::site_select( $plan, 'cpms_facility_clinic_site' );
        echo '<p><label for="cpms_facility_timezone">منطقهٔ زمانی</label><br><select id="cpms_facility_timezone" name="timezone" required style="max-width:100%;"><option value="">انتخاب صریح</option>';
        foreach ( [ 'Asia/Tehran', 'Asia/Dubai', 'Europe/Istanbul', 'UTC' ] as $timezone ) {
            echo '<option value="' . esc_attr( $timezone ) . '">' . esc_html( $timezone ) . '</option>';
        }
        echo '</select></p>';
        self::close_form( $hospital ? 'ایجاد درمانگاه سرپایی' : 'ایجاد درمانگاه مستقل' );
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function render_managers( int $organization_id, array $plan ): void {
        $building = 'medical_building' === (string) $plan['flow'];
        echo '<p class="description">مدیر هر درمانگاه صریحاً با نام کاربری یا ایمیل تعیین می‌شود (عضویت داخلی «مدیر کلینیک» فقط در همان درمانگاه). ' . ( $building ? 'در ساختمان پزشکی هر درمانگاه مدیر مستقل خودش را دارد و مدیر سازمان بدون عضویت صریح به داده‌های درمانگاه‌ها دسترسی ندارد.' : 'اگر خودتان را مدیر تعیین کنید، این انتخاب صریح و ممیزی‌شده است.' ) . ' عضویت موجودِ دیگر بازنویسی نمی‌شود.</p>';
        if ( ! self::require_clinics( $plan ) ) {
            return;
        }
        self::open_form( $organization_id, 'manager', 'managers' );
        self::clinic_select( $plan, 'cpms_facility_manager_clinic' );
        self::text_input( 'user_login', 'نام کاربری یا ایمیل مدیر', 100, true );
        self::close_form( 'تعیین مدیر درمانگاه' );
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function render_rooms( int $organization_id, array $plan ): void {
        echo '<p class="description">اتاق ویزیت یک مکان (Location) داخل همان درمانگاه است، نه درمانگاه جدا. ساخت آن فقط با عضویت فعال و مجوز پیکربندی در همان درمانگاه انجام می‌شود.</p>';
        if ( ! self::require_clinics( $plan ) ) {
            return;
        }
        foreach ( $plan['clinics'] as $clinic ) {
            echo '<p data-cpms-facility-rooms="' . esc_attr( (string) $clinic['key'] ) . '"><strong>' . esc_html( (string) $clinic['name'] ) . ':</strong> ';
            $names = [ 'مکان اصلی' ];
            foreach ( $clinic['rooms'] as $room ) {
                $names[] = (string) $room['name'];
            }
            echo esc_html( implode( '، ', $names ) ) . '</p>';
        }
        self::open_form( $organization_id, 'room', 'rooms' );
        echo '<input type="hidden" name="request_key" value="' . esc_attr( FacilitySetupService::new_request_key() ) . '">';
        self::clinic_select( $plan, 'cpms_facility_room_clinic' );
        self::text_input( 'room_name', 'نام اتاق/مکان ویزیت', 190, true );
        self::site_select( $plan, 'cpms_facility_room_site' );
        self::close_form( 'افزودن اتاق/مکان' );
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function render_clinicians( int $actor_id, int $organization_id, array $plan ): void {
        echo '<p class="description">هر کاربر فقط یک پروفایل حرفه‌ای دارد. نخستین درمانگاه پروفایل را می‌سازد و درمانگاه‌های دیگرِ همین سازمان فقط عضویت صریح پزشک اضافه می‌کنند (پزشک مشترک)، بدون هویت دوم. تخصیص اتاق‌های درمانگاه‌های دیگر حفظ می‌شود.</p>';
        if ( ! self::require_clinics( $plan ) ) {
            return;
        }
        self::render_clinic_rosters( $actor_id, $organization_id, $plan );
        self::open_form( $organization_id, 'clinician', 'clinicians' );
        self::clinic_select( $plan, 'cpms_facility_clinician_clinic' );
        self::text_input( 'user_login', 'نام کاربری یا ایمیل پزشک', 100, true );
        self::text_input( 'full_name', 'نام کامل (فقط برای پروفایل جدید)', 190, false );
        echo '<fieldset><legend>اتاق‌ها/مکان‌های این پزشک در درمانگاه انتخاب‌شده</legend>';
        foreach ( $plan['clinics'] as $clinic ) {
            echo '<div data-cpms-room-group="' . esc_attr( (string) $clinic['key'] ) . '"><strong>' . esc_html( (string) $clinic['name'] ) . '</strong>';
            echo '<label style="display:block;"><input type="checkbox" name="room_keys[]" value="primary"> مکان اصلی</label>';
            foreach ( $clinic['rooms'] as $room ) {
                echo '<label style="display:block;"><input type="checkbox" name="room_keys[]" value="' . esc_attr( (string) $room['key'] ) . '"> ' . esc_html( (string) $room['name'] ) . '</label>';
            }
            echo '</div>';
        }
        echo '<p class="description">فقط اتاق‌های همان درمانگاهی که بالا انتخاب کرده‌اید پذیرفته می‌شود؛ انتخاب اتاق درمانگاه دیگر رد می‌شود.</p></fieldset>';
        self::close_form( 'تخصیص پزشک' );
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function render_staff( int $actor_id, int $organization_id, array $plan ): void {
        echo '<p class="description">نقش کارکنان (پذیرش یا مالی) برای یک درمانگاهِ صریحاً انتخاب‌شده ثبت می‌شود. محدودهٔ دسترسی نیز صریح است: کل درمانگاه یا فقط مکان‌های انتخاب‌شده. منع صریح (DENY) فقط دامنهٔ نقش را محدود می‌کند و همیشه بر GRANT مقدم است؛ این گام هیچ مجوز اضافه‌ای (GRANT) نمی‌دهد. عضویت موجود هرگز بازنویسی نمی‌شود.</p>';
        if ( ! self::require_clinics( $plan ) ) {
            return;
        }
        $service = self::service();
        foreach ( $plan['clinics'] as $clinic ) {
            echo '<div data-cpms-facility-staff-roster="' . esc_attr( (string) $clinic['key'] ) . '"><strong>' . esc_html( (string) $clinic['name'] ) . ':</strong> ';
            try {
                $rows = $service->clinic_staff( $actor_id, $organization_id, (string) $clinic['key'] );
            } catch ( FacilitySetupException $e ) {
                echo '<span class="description" data-cpms-facility-staff-denied="1">دسترسی پیکربندی در این درمانگاه ندارید؛ کارکنان آن را مدیر همان درمانگاه تعیین می‌کند.</span></div>';
                continue;
            }
            if ( [] === $rows ) {
                echo '<span>هنوز نقش کارکنانی ثبت نشده است.</span>';
            }
            $labels = [];
            foreach ( $rows as $row ) {
                $scope    = 'location' === $row['scope_mode'] ? $row['locations'] . ' مکان' : 'کل درمانگاه';
                $labels[] = $row['login'] . ' — ' . ( self::STAFF_ROLE_LABELS[ $row['role_key'] ] ?? $row['role_key'] ) . ' — ' . $scope . ' — منع صریح: ' . $row['denied'] . ( 'active' === $row['status'] ? '' : ' (تعلیق)' );
            }
            echo esc_html( implode( '، ', $labels ) );
            echo ' — <a href="' . esc_url( add_query_arg( [ 'clinic_id' => (int) $clinic['clinic_id'] ], admin_url( 'admin.php?page=' . StaffManagementPage::PAGE_SLUG ) ) ) . '">مدیریت کارکنان این درمانگاه</a></div>';
        }

        self::open_form( $organization_id, 'staff', 'staff' );
        self::clinic_select( $plan, 'cpms_facility_staff_clinic' );
        self::text_input( 'user_login', 'نام کاربری یا ایمیل کارمند', 100, true );
        echo '<p><label for="cpms_facility_staff_role">نقش</label><br><select id="cpms_facility_staff_role" name="role_key" required style="max-width:100%;"><option value="">انتخاب صریح نقش</option>';
        foreach ( self::STAFF_ROLE_LABELS as $role_key => $label ) {
            echo '<option value="' . esc_attr( $role_key ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select></p>';
        echo '<fieldset><legend>محدودهٔ دسترسی (انتخاب صریح)</legend>';
        echo '<label style="display:block;"><input type="radio" name="access" value="clinic" required> کل همین درمانگاه</label>';
        echo '<label style="display:block;"><input type="radio" name="access" value="locations"> فقط مکان‌های انتخاب‌شدهٔ زیر</label>';
        foreach ( $plan['clinics'] as $clinic ) {
            echo '<div data-cpms-staff-room-group="' . esc_attr( (string) $clinic['key'] ) . '"><strong>' . esc_html( (string) $clinic['name'] ) . '</strong>';
            echo '<label style="display:block;"><input type="checkbox" name="room_keys[]" value="primary"> مکان اصلی</label>';
            foreach ( $clinic['rooms'] as $room ) {
                echo '<label style="display:block;"><input type="checkbox" name="room_keys[]" value="' . esc_attr( (string) $room['key'] ) . '"> ' . esc_html( (string) $room['name'] ) . '</label>';
            }
            echo '</div>';
        }
        echo '<p class="description">فقط مکان‌های همان درمانگاهی که بالا انتخاب کرده‌اید پذیرفته می‌شود؛ مکان درمانگاه دیگر یا مکان غیرفعال رد می‌شود.</p></fieldset>';
        echo '<fieldset><legend>منع صریح (DENY) — اختیاری</legend>';
        foreach ( self::STAFF_DENY_LABELS as $capability => $label ) {
            echo '<label style="display:block;"><input type="checkbox" name="deny_caps[]" value="' . esc_attr( $capability ) . '"> ' . esc_html( $label ) . '</label>';
        }
        echo '<p class="description">فقط مجوزی که در نقش انتخاب‌شده وجود دارد قابل منع است؛ در غیر این صورت هیچ چیزی ثبت نمی‌شود.</p></fieldset>';
        self::close_form( 'ثبت نقش کارکنان' );
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function render_schedules( int $actor_id, int $organization_id, array $plan ): void {
        echo '<p class="description">برنامهٔ هفتگی برای هر پزشک، در هر درمانگاه و هر اتاق جداگانه ثبت می‌شود؛ پزشک مشترک در دو درمانگاه دو برنامهٔ مستقل دارد. استثناها و تعطیلی‌ها (با تاریخ شمسی) در صفحهٔ «پزشکان» همان درمانگاه ثبت می‌شود.</p>';
        if ( ! self::require_clinics( $plan ) ) {
            return;
        }
        $clinicians = self::render_clinic_rosters( $actor_id, $organization_id, $plan );
        if ( [] === $clinicians ) {
            echo '<div class="notice notice-warning inline"><p>ابتدا در درمانگاهی که در آن مجوز پیکربندی دارید پزشک تخصیص دهید.</p></div>';
            return;
        }
        self::open_form( $organization_id, 'schedule', 'schedules' );
        self::clinic_select( $plan, 'cpms_facility_schedule_clinic' );
        echo '<p><label for="cpms_facility_schedule_clinician">پزشک</label><br><select id="cpms_facility_schedule_clinician" name="clinician_id" required style="max-width:100%;"><option value="">انتخاب صریح پزشک</option>';
        foreach ( $clinicians as $id => $name ) {
            echo '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $name ) . '</option>';
        }
        echo '</select></p>';
        echo '<p><label for="cpms_facility_schedule_room">اتاق/مکان</label><br><select id="cpms_facility_schedule_room" name="room_key" required style="max-width:100%;"><option value="">انتخاب صریح اتاق</option>';
        foreach ( $plan['clinics'] as $clinic ) {
            echo '<option value="primary" data-clinic="' . esc_attr( (string) $clinic['key'] ) . '">' . esc_html( (string) $clinic['name'] ) . ' — مکان اصلی</option>';
            foreach ( $clinic['rooms'] as $room ) {
                echo '<option value="' . esc_attr( (string) $room['key'] ) . '" data-clinic="' . esc_attr( (string) $clinic['key'] ) . '">' . esc_html( (string) $clinic['name'] . ' — ' . (string) $room['name'] ) . '</option>';
            }
        }
        echo '</select></p>';
        echo '<p><label for="cpms_facility_schedule_day">روز هفته</label><br><select id="cpms_facility_schedule_day" name="day_of_week" required style="max-width:100%;"><option value="">انتخاب روز</option>';
        foreach ( self::DAYS as $index => $day ) {
            echo '<option value="' . esc_attr( (string) $index ) . '">' . esc_html( $day ) . '</option>';
        }
        echo '</select></p>';
        echo '<p><label for="cpms_facility_schedule_start">شروع</label> <input type="time" id="cpms_facility_schedule_start" name="start_time" required> ';
        echo '<label for="cpms_facility_schedule_end">پایان</label> <input type="time" id="cpms_facility_schedule_end" name="end_time" required></p>';
        echo '<p><label for="cpms_facility_schedule_duration">مدت هر نوبت (دقیقه)</label> <input type="number" id="cpms_facility_schedule_duration" name="duration" min="5" max="240" value="20" required> ';
        echo '<label for="cpms_facility_schedule_capacity">ظرفیت هر نوبت</label> <input type="number" id="cpms_facility_schedule_capacity" name="slot_capacity" min="1" max="50" value="1" required></p>';
        self::close_form( 'ثبت برنامهٔ هفتگی' );
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function render_booking( int $organization_id, array $plan ): void {
        echo '<p class="description">سیاست رزرو و ظرفیت برای هر درمانگاه جداگانه و فقط با مجوز پیکربندی همان درمانگاه ذخیره می‌شود (همان کلیدهای گام رزرو ویزارد).</p>';
        if ( ! self::require_clinics( $plan ) ) {
            return;
        }
        self::open_form( $organization_id, 'booking', 'booking' );
        self::clinic_select( $plan, 'cpms_facility_booking_clinic' );
        $fields = [
            'duration' => [ 'مدت پیش‌فرض نوبت (دقیقه)', 5, 240, 20 ],
            'capacity' => [ 'ظرفیت پیش‌فرض هر نوبت', 1, 20, 1 ],
            'lead'     => [ 'حداقل فاصله تا نوبت (ساعت)', 1, 72, 2 ],
            'future'   => [ 'حداکثر روزهای آینده برای رزرو', 1, 365, 60 ],
            'cancel'   => [ 'مهلت لغو (ساعت)', 0, 168, 24 ],
        ];
        foreach ( $fields as $name => [ $label, $min, $max, $default ] ) {
            echo '<p><label for="cpms_facility_booking_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><br>';
            echo '<input type="number" id="cpms_facility_booking_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" value="' . esc_attr( (string) $default ) . '" required></p>';
        }
        self::close_form( 'ذخیرهٔ سیاست رزرو' );
    }

    private static function render_review( int $actor_id, int $organization_id ): void {
        $review = self::safe_review( $actor_id, $organization_id );
        if ( null === $review ) {
            return;
        }
        $plan = $review['plan'];
        echo '<div data-cpms-facility-review="' . ( $review['ready'] ? 'ready' : 'blocked' ) . '">';
        echo '<p>ساختمان‌ها/سایت‌ها: ' . esc_html( (string) count( $plan['sites'] ) ) . ' — درمانگاه‌ها: ' . esc_html( (string) count( $review['clinics'] ) ) . '</p>';
        foreach ( $review['clinics'] as $clinic ) {
            echo '<div class="card" style="max-width:100%;box-sizing:border-box;" data-cpms-review-clinic="' . esc_attr( (string) $clinic['clinic_id'] ) . '">';
            echo '<h3>' . esc_html( (string) $clinic['name'] ) . ' <small>(' . esc_html( 'active' === $clinic['status'] ? 'فعال' : 'در انتظار فعال‌سازی' ) . ')</small></h3>';
            echo '<ul>';
            echo '<li>ساختمان: ' . esc_html( self::site_label( $plan, (string) $clinic['site_key'] ) ) . '</li>';
            echo '<li>مدیران فعال: ' . esc_html( (string) $clinic['managers'] ) . '</li>';
            echo '<li>مکان‌های فعال: ' . esc_html( (string) $clinic['active_locations'] ) . '</li>';
            echo '<li>پزشکان دارای برنامه: ' . esc_html( (string) $clinic['scheduled_clinicians'] ) . ' — ردیف‌های برنامهٔ هفتگی: ' . esc_html( (string) $clinic['schedule_rows'] ) . '</li>';
            echo '<li>کارکنان فعال دیگر: ' . esc_html( (string) $clinic['staff'] ) . ' — محدود به مکان‌های مشخص: ' . esc_html( (string) $clinic['staff_location_scope'] ) . '</li>';
            echo '<li>سیاست رزرو: ' . esc_html( $clinic['booking_policy'] ? 'ثبت شده' : 'ثبت نشده' ) . '</li>';
            echo '</ul>';
            if ( $clinic['can_configure'] ) {
                $clinic_arg = [ 'clinic_id' => (int) $clinic['clinic_id'] ];
                echo '<p data-cpms-review-links="' . esc_attr( (string) $clinic['clinic_id'] ) . '">مدیریت پس از راه‌اندازی (انتخاب صریح همین درمانگاه): ';
                echo '<a href="' . esc_url( add_query_arg( $clinic_arg, admin_url( 'admin.php?page=cpms-clinicians' ) ) ) . '">پزشکان و برنامه</a> | ';
                echo '<a href="' . esc_url( add_query_arg( $clinic_arg, admin_url( 'admin.php?page=cpms-locations' ) ) ) . '">مکان‌ها</a> | ';
                echo '<a href="' . esc_url( add_query_arg( $clinic_arg, admin_url( 'admin.php?page=' . StaffManagementPage::PAGE_SLUG ) ) ) . '">کارکنان و مجوزها</a></p>';
            } else {
                echo '<p class="description" data-cpms-review-no-clinic-access="' . esc_attr( (string) $clinic['clinic_id'] ) . '">شما در این درمانگاه عضویت پیکربندی ندارید؛ پیکربندی بالینی آن با مدیر خود همان درمانگاه است.</p>';
            }
            echo '</div>';
        }
        if ( [] !== $review['blocking'] ) {
            echo '<div class="notice notice-warning inline" data-cpms-facility-blocking="' . esc_attr( (string) count( $review['blocking'] ) ) . '"><p>موارد باقی‌مانده پیش از فعال‌سازی:</p><ul>';
            foreach ( $review['blocking'] as $item ) {
                echo '<li>' . esc_html( $item ) . '</li>';
            }
            echo '</ul></div>';
        }
        echo '</div>';
        if ( $review['ready'] ) {
            self::open_form( $organization_id, 'activate', 'review' );
            echo '<p><label><input type="checkbox" name="confirm" value="1" required> بازبینی بالا را تأیید می‌کنم؛ درمانگاه‌های این راه‌اندازی از این لحظه نوبت جدید می‌پذیرند.</label></p>';
            self::close_form( 'فعال‌سازی مرکز' );
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array{plan: array<string, mixed>, clinics: list<array<string, mixed>>, blocking: list<string>, ready: bool}|null
     */
    private static function safe_review( int $actor_id, int $organization_id ): ?array {
        try {
            return self::service()->review( $actor_id, $organization_id );
        } catch ( FacilitySetupException $e ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
            return null;
        }
    }

    /**
     * Rosters of the Clinics the actor may configure; returns id => label for selects.
     *
     * @param array<string, mixed> $plan
     * @return array<int, string>
     */
    private static function render_clinic_rosters( int $actor_id, int $organization_id, array $plan ): array {
        $service = self::service();
        $options = [];
        foreach ( $plan['clinics'] as $clinic ) {
            echo '<div data-cpms-facility-roster="' . esc_attr( (string) $clinic['key'] ) . '"><strong>' . esc_html( (string) $clinic['name'] ) . ':</strong> ';
            try {
                $rows = $service->clinic_clinicians( $actor_id, $organization_id, (string) $clinic['key'] );
            } catch ( FacilitySetupException $e ) {
                echo '<span class="description">دسترسی پیکربندی در این درمانگاه ندارید.</span></div>';
                continue;
            }
            if ( [] === $rows ) {
                echo '<span>هنوز پزشکی تخصیص داده نشده است.</span></div>';
                continue;
            }
            $labels = [];
            foreach ( $rows as $row ) {
                $labels[]                    = $row['full_name'] . ' (' . $row['schedule_rows'] . ' ردیف برنامه)';
                $options[ (int) $row['id'] ] = $row['full_name'];
            }
            echo esc_html( implode( '، ', $labels ) ) . '</div>';
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function require_clinics( array $plan ): bool {
        if ( [] === $plan['clinics'] ) {
            echo '<div class="notice notice-warning inline"><p>ابتدا دست‌کم یک درمانگاه ایجاد کنید.</p></div>';
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function render_site_list( array $plan ): void {
        echo '<ul data-cpms-facility-sites="' . esc_attr( (string) count( $plan['sites'] ) ) . '">';
        foreach ( $plan['sites'] as $site ) {
            echo '<li>' . esc_html( (string) $site['label'] ) . ( '' !== (string) $site['address'] ? ' — ' . esc_html( (string) $site['address'] ) : '' ) . '</li>';
        }
        echo '</ul>';
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function site_select( array $plan, string $element_id ): void {
        echo '<p><label for="' . esc_attr( $element_id ) . '">ساختمان/سایت</label><br><select id="' . esc_attr( $element_id ) . '" name="site_key" required style="max-width:100%;"><option value="">انتخاب صریح ساختمان</option>';
        foreach ( $plan['sites'] as $site ) {
            echo '<option value="' . esc_attr( (string) $site['key'] ) . '">' . esc_html( (string) $site['label'] ) . '</option>';
        }
        echo '</select></p>';
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function clinic_select( array $plan, string $element_id ): void {
        echo '<p><label for="' . esc_attr( $element_id ) . '">درمانگاه</label><br><select id="' . esc_attr( $element_id ) . '" name="clinic_key" required style="max-width:100%;"><option value="">انتخاب صریح درمانگاه</option>';
        foreach ( $plan['clinics'] as $clinic ) {
            echo '<option value="' . esc_attr( (string) $clinic['key'] ) . '">' . esc_html( (string) $clinic['name'] ) . '</option>';
        }
        echo '</select></p>';
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function site_label( array $plan, string $site_key ): string {
        foreach ( $plan['sites'] as $site ) {
            if ( (string) $site['key'] === $site_key ) {
                return (string) $site['label'];
            }
        }

        return '—';
    }

    private static function text_input( string $name, string $label, int $max_length, bool $required ): void {
        $id = 'cpms_facility_' . $name;
        echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><br>';
        echo '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" maxlength="' . esc_attr( (string) $max_length ) . '"' . ( $required ? ' required' : '' ) . ' style="width:100%;max-width:420px;box-sizing:border-box;"></p>';
    }

    private static function open_form( int $organization_id, string $op, string $next_step ): void {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-cpms-facility-form="' . esc_attr( $op ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
        echo '<input type="hidden" name="step" value="' . esc_attr( $next_step ) . '">';
        echo '<input type="hidden" name="organization_id" value="' . esc_attr( (string) $organization_id ) . '">';
        wp_nonce_field( self::NONCE );
    }

    private static function close_form( string $label ): void {
        echo '<p><button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></p></form>';
    }

    private static function step_url( int $organization_id, string $step ): string {
        return add_query_arg(
            [
                'page'            => self::PAGE,
                'organization_id' => $organization_id,
                'step'            => $step,
            ],
            admin_url( 'admin.php' )
        );
    }

    /**
     * @param array<string, string> $steps
     */
    private static function requested_step( array $steps, string $fallback ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation, whitelisted against the step registry.
        $raw = isset( $_GET['step'] ) && is_string( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';

        return isset( $steps[ $raw ] ) ? $raw : $fallback;
    }

    private static function requested_int( string $key ): ?int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector; re-verified against the actor's Organization authority.
        if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
            return null;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector; re-verified against the actor's Organization authority.
        $raw = trim( sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) );
        if ( '' === $raw ) {
            return null;
        }

        return 1 === preg_match( '/^[1-9][0-9]{0,9}$/', $raw ) ? (int) $raw : 0;
    }

    private static function user_id_from( string $login_or_email ): int {
        $login_or_email = trim( $login_or_email );
        if ( '' === $login_or_email ) {
            return 0;
        }
        $user = is_email( $login_or_email ) ? get_user_by( 'email', $login_or_email ) : get_user_by( 'login', $login_or_email );

        return $user instanceof \WP_User ? (int) $user->ID : 0;
    }
}
