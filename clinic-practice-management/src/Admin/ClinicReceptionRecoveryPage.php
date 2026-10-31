<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Membership\ReceptionRecoveryService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * بازیابی پذیرش تک‌مطبی — انتساب پزشک به محلِ فعال (Persian recovery UI).
 *
 * این صفحه برای نصبِ تک‌مطبی که پس از First-Practice Setup هیچ انتساب
 * cpms_clinician_locations ندارد، مسیر بازیابیِ مجازِ Clinic-local را فراهم می‌کند:
 *
 * - فقط برای مدیرِ مجازِ همان Clinic (عضویت فعال + cpms_config از طریق
 *   AuthorizationService)؛ WordPress Administrator بدون عضویت Clinic هیچ
 *   دسترسی‌ای ندارد — پیامِ غیرِشمارشیِ “دسترسی” برمی‌گردد.
 * - پزشک و مدیر باید عضویت فعالِ همان Clinic داشته باشند؛ محل‌های انتخابی
 *   باید فعال و در محدودهٔ مجاز هر دو عضویت باشند.
 * - بازیابی فقط محل‌های انتخابی را اضافه می‌کند و انتساب‌های دیگر Clinicها
 *   یا محل اصلی موجود را تغییر نمی‌دهد. تکرار یک درخواست no-op است.
 * - درج و audit اجباری در یک تراکنش هستند؛ شکست audit تغییر را برمی‌گرداند.
 */
final class ClinicReceptionRecoveryPage {
    public const PAGE_SLUG     = 'cpms-reception-recovery';
    private const NONCE_ACTION = 'cpms_reception_recovery';
    private const NOTICE_KEY   = 'cpms_reception_recovery_notice';

    public static function register(): void {
        // App::boot() runs before WordPress loads the admin menu functions,
        // including on frontend, REST, CLI and activation requests.
        \add_action( 'admin_menu', [self::class, 'menu'] );
        \add_action( 'admin_post_cpms_reception_recovery_assign', [self::class, 'handle_assign'] );
    }

    public static function menu(): void {
        \add_submenu_page(
            CpmsAdminMenu::parent_slug(),
            'بازیابی پذیرش — انتساب پزشک به محل',
            'بازیابی پذیرش',
            RolesAndCapabilities::CONFIG,
            self::PAGE_SLUG,
            [self::class, 'render']
        );
        // Hidden from submenu by default — discovered via SystemPage guidance and direct URL.
        CpmsAdminMenu::hide_from_submenu( self::PAGE_SLUG );
    }

    public static function render(): void {
        $actor_id = (int) \get_current_user_id();
        if ( $actor_id <= 0 ) {
            \wp_die( \esc_html__( 'دسترسی ندارید', 'cpms' ), '', ['response' => 403] );
        }

        $requested_clinic_id = AdminClinicSelector::requested_clinic_id( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector; verified below
        $choices             = AdminClinicSelector::choices( $actor_id, RolesAndCapabilities::CONFIG );

        // No eligible Clinic — show selector shell (non-enumerating).
        if ( $requested_clinic_id !== null ) {
            $scope = AdminClinicSelector::establish( $actor_id, $requested_clinic_id, RolesAndCapabilities::CONFIG );
            if ( $scope === null ) {
                self::render_selector_shell( $choices, true );
                return;
            }
            $clinic_id = $requested_clinic_id;
        } elseif ( count( $choices ) === 1 ) {
            // Single-membership shortcut (existing pattern in ClinicianAdminPage).
            $clinic_id = (int) ( $choices[0]['clinic_id'] ?? 0 );
            $scope     = AdminClinicSelector::establish( $actor_id, $clinic_id, RolesAndCapabilities::CONFIG );
            if ( $scope === null ) {
                self::render_selector_shell( $choices, true );
                return;
            }
        } else {
            self::render_selector_shell( $choices, false );
            return;
        }

        // Verified Clinic context — now enforce explicit CONFIG authorization per Clinic.
        try {
            App::authorization_service()->authorize( $actor_id, $clinic_id, RolesAndCapabilities::CONFIG );
        } catch ( \Throwable ) {
            \wp_die( \esc_html__( 'دسترسی به این درمانگاه مجاز نیست', 'cpms' ), '', ['response' => 403] );
        }

        $clinic_name      = (string) ( App::db()->fetchValue( 'SELECT name FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d', [$clinic_id] ) ?? '' );
        $locations        = App::db()->fetchAll(
            'SELECT id, name, slug, is_primary FROM ' . App::db()->table( 'cpms_locations' ) . ' WHERE clinic_id = %d AND is_active = 1 ORDER BY is_primary DESC, name ASC',
            [$clinic_id]
        );
        $actor_membership = App::membership_service()->active_membership_for( $clinic_id, $actor_id );
        $actor_mode       = (string) ( $actor_membership['scope_mode'] ?? '' );
        if ( $actor_mode === 'location' ) {
            $allowed   = App::membership_service()->membership_location_ids( (int) ( $actor_membership['id'] ?? 0 ) );
            $locations = array_values( array_filter( $locations, static fn( array $row ): bool => in_array( (int) $row['id'], $allowed, true ) ) );
        } elseif ( $actor_mode !== 'clinic' ) {
            $locations = [];
        }

        // Participation is membership-based, not the clinician's legacy home Clinic.
        $clinicians = App::db()->fetchAll(
            'SELECT c.id, c.full_name, c.wp_user_id, c.is_active FROM ' . App::db()->table( 'cpms_clinicians' ) . ' c'
                . ' INNER JOIN ' . App::db()->table( 'cpms_clinic_memberships' ) . " m ON m.wp_user_id = c.wp_user_id AND m.clinic_id = %d AND m.status = 'active'"
                . ' WHERE c.is_active = 1 AND c.wp_user_id > 0 ORDER BY c.full_name ASC, c.id ASC LIMIT 100',
            [$clinic_id]
        );

        // Display only Locations the manager is authorized to select.
        $assignments = [];
        if ( $clinicians !== [] && $locations !== [] ) {
            $clinician_ids          = array_map( static fn( array $row ): int => (int) $row['id'], $clinicians );
            $location_ids           = array_map( static fn( array $row ): int => (int) $row['id'], $locations );
            $clinician_placeholders = implode( ',', array_fill( 0, count( $clinician_ids ), '%d' ) );
            $location_placeholders  = implode( ',', array_fill( 0, count( $location_ids ), '%d' ) );
            $rows                   = App::db()->fetchAll(
                'SELECT clinician_id, location_id, is_primary FROM ' . App::db()->table( 'cpms_clinician_locations' ) . ' WHERE clinician_id IN (' . $clinician_placeholders . ') AND location_id IN (' . $location_placeholders . ') ORDER BY clinician_id, location_id',
                array_merge( $clinician_ids, $location_ids )
            );
            foreach ( $rows as $row ) {
                $cid                   = (int) ( $row['clinician_id'] ?? 0 );
                $assignments[ $cid ][] = $row;
            }
        }

        $notice       = self::pull_notice();
        $has_eligible = $locations !== [] && $clinicians !== [];

        // Header with Clinic context.
        echo '<div class="wrap cpms-reception-recovery" dir="rtl">';
        echo '<h1>بازیابی پذیرش — انتساب پزشک به محل</h1>';
        echo '<p>درمانگاهِ فعال: <strong>' . \esc_html( $clinic_name ) . '</strong> (شناسه ' . \esc_html( (string) $clinic_id ) . ') — ' . \esc_html( Jalali::todayJalali() ) . '</p>';

        if ( $notice !== null ) {
            $class = $notice['type'] === 'success' ? 'notice notice-success is-dismissible' : 'notice notice-error is-dismissible';
            echo '<div class="' . \esc_attr( $class ) . '"><p>' . \esc_html( $notice['message'] ) . '</p></div>';
        }

        // Guidance for empty assignment (the reported reception failure).
        if ( $clinicians === [] || $locations === [] ) {
            echo '<div class="notice notice-warning"><p>';
            if ( $clinicians === [] && $locations === [] ) {
                echo \esc_html__( 'هیچ پزشکِ فعال و هیچ محلِ فعالی برای این درمانگاه یافت نشد — ابتدا یک پزشک و یک محل بسازید.', 'cpms' );
            } elseif ( $clinicians === [] ) {
                echo \esc_html__( 'هیچ پزشکِ فعالِ دارای حساب کاربری در این درمانگاه نیست — ابتدا در «پزشکان و برنامه کاری» یک پزشک بسازید.', 'cpms' );
            } else {
                echo \esc_html__( 'هیچ محلِ فعالِ تأییدشده‌ای برای این درمانگاه نیست — در «شعبه‌ها و محل‌ها» یک محلِ فعال بسازید.', 'cpms' );
            }
            echo '</p></div>';
        }

        // Show current state table (for 0/1/multiple locations visibility).
        echo '<h2>وضعیتِ کنونیِ انتساب</h2>';
        if ( $clinicians === [] ) {
            echo '<p>—</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>پزشک</th><th>محل‌های منتسب</th><th>اولویت</th></tr></thead><tbody>';
            foreach ( $clinicians as $cl ) {
                $cid       = (int) ( $cl['id'] ?? 0 );
                $cname     = (string) ( $cl['full_name'] ?? '' );
                $assigned  = $assignments[ $cid ] ?? [];
                $loc_names = [];
                $primary   = null;
                foreach ( $assigned as $a ) {
                    $lid = (int) ( $a['location_id'] ?? 0 );
                    foreach ( $locations as $loc ) {
                        if ( (int) ( $loc['id'] ?? 0 ) === $lid ) {
                            $loc_names[] = (string) ( $loc['name'] ?? '' );
                            if ( (int) ( $a['is_primary'] ?? 0 ) === 1 ) {
                                $primary = (string) ( $loc['name'] ?? '' );
                            }
                        }
                    }
                }
                echo '<tr><td>' . \esc_html( $cname ) . ' (' . \esc_html( (string) $cid ) . ')</td>';
                echo '<td>' . \esc_html( $loc_names === [] ? '— بدون انتساب (پذیرش خالی)' : implode( '، ', $loc_names ) ) . '</td>';
                echo '<td>' . \esc_html( $primary ?? '—' ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        if ( ! $has_eligible ) {
            echo '<p><a href="' . \esc_url( \admin_url( 'admin.php?page=cpms-clinicians&clinic_id=' . $clinic_id ) ) . '" class="button">رفتن به «پزشکان و برنامه کاری»</a> ';
            echo '<a href="' . \esc_url( \admin_url( 'admin.php?page=cpms-locations&clinic_id=' . $clinic_id ) ) . '" class="button">رفتن به «شعبه‌ها و محل‌ها»</a></p>';
            echo '</div>';
            return;
        }

        // Assignment form (Persian, RTL, explicit Clinic authority).
        echo '<h2>انتسابِ جدید / ویرایش</h2>';
        echo '<p>یک پزشکِ فعال و یک یا چند محلِ فعالِ <strong>همان درمانگاه</strong> را انتخاب کنید؛ محل‌ها افزوده می‌شوند و انتساب‌های قبلی، از جمله درمانگاه‌های دیگر، حفظ می‌شوند. تغییر با audit ثبت می‌گردد.</p>';

        echo '<form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '" class="cpms-recovery-form">';
        \wp_nonce_field( self::NONCE_ACTION );
        echo '<input type="hidden" name="action" value="cpms_reception_recovery_assign" />';
        echo '<input type="hidden" name="clinic_id" value="' . \esc_attr( (string) $clinic_id ) . '" />';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="cpms_rr_clinician">پزشک</label></th><td>';
        echo '<select id="cpms_rr_clinician" name="clinician_id" required>';
        echo '<option value="">— انتخاب کنید —</option>';
        foreach ( $clinicians as $cl ) {
            echo '<option value="' . \esc_attr( (string) ( $cl['id'] ?? '' ) ) . '">' . \esc_html( (string) ( $cl['full_name'] ?? '' ) . ' — ' . (string) ( $cl['id'] ?? '' ) ) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row">محل‌های خدمت</th><td>';
        foreach ( $locations as $loc ) {
            $lid        = (int) ( $loc['id'] ?? 0 );
            $lname      = (string) ( $loc['name'] ?? '' );
            $is_primary = (int) ( $loc['is_primary'] ?? 0 ) === 1;
            echo '<label style="display:block;margin:6px 0;"><input type="checkbox" name="location_ids[]" value="' . \esc_attr( (string) $lid ) . '" /> ' . \esc_html( $lname ) . ' (' . \esc_html( (string) $lid ) . ')';
            if ( $is_primary ) {
                echo ' — <em>اصلی</em>';
            }
            echo '</label>';
        }
        echo '<p class="description">حداقل یک محل را انتخاب کنید؛ حداقل یک انتساب برای فعال‌شدنِ انتخاب‌گرِ پذیرش الزامی است (جدولِ خالی ⇒ انتخاب‌گرِ خالی).</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="cpms_rr_primary">محلِ اصلی (اختیاری)</label></th><td>';
        echo '<select id="cpms_rr_primary" name="primary_location_id">';
        echo '<option value="">— بدونِ اولویت —</option>';
        foreach ( $locations as $loc ) {
            echo '<option value="' . \esc_attr( (string) ( $loc['id'] ?? '' ) ) . '">' . \esc_html( (string) ( $loc['name'] ?? '' ) ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">اگر تعیین شود باید جزوِ محل‌های انتخاب‌شده باشد. محل اصلیِ موجود در این مسیر تغییر نمی‌کند؛ در صورت تعارض، انتخاب را خالی بگذارید.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary">ذخیرهٔ انتساب</button> ';
        echo '<a href="' . \esc_url( \admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . $clinic_id ) ) . '" class="button">بازنشانی</a></p>';
        echo '</form>';

        echo '<div class="cpms-help" style="margin-top:24px;padding:12px;background:#fff;border-right:4px solid #2271b1;">';
        echo '<p><strong>راهنما:</strong> پس از ذخیره، «پزشکان و برنامه کاری» و «پذیرش → انتخاب پزشک» باید پزشک را در محلِ مربوط نشان دهند. اگر هنوز خالی است، کشِ مرورگر را نوسازی کنید و از عدمِ تعلیقِ عضویتِ پزشک اطمینان یابید.</p>';
        echo '</div>';

        echo '</div>';
    }

    public static function handle_assign(): void {
        $actor_id = (int) \get_current_user_id();
        if ( $actor_id <= 0 ) {
            \wp_die( \esc_html__( 'دسترسی ندارید', 'cpms' ), '', ['response' => 403] );
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
            self::push_notice( 'error', 'درخواست نامعتبر است — دوباره تلاش کنید.' );
            self::redirect();
        }

        $clinic_id     = AdminClinicSelector::requested_clinic_id( $_POST ) ?? 0;
        $clinician_id  = self::request_id( $_POST['clinician_id'] ?? null );
        $raw_locations = $_POST['location_ids'] ?? [];
        $location_ids  = is_array( $raw_locations ) ? array_map( [self::class, 'request_id'], $raw_locations ) : [];
        $raw_primary   = $_POST['primary_location_id'] ?? '';
        $primary_id    = is_scalar( $raw_primary ) && (string) $raw_primary === '' ? null : self::request_id( $raw_primary );

        // A request value is only a selector, never authority. The service
        // re-establishes and re-authorizes it under the write transaction.
        $scope = AdminClinicSelector::establish( $actor_id, $clinic_id, RolesAndCapabilities::CONFIG );
        if ( $scope === null ) {
            self::push_notice( 'error', 'دسترسی به این درمانگاه مجاز نیست.' );
            self::redirect();
        }
        if ( $clinician_id <= 0 || $location_ids === [] || in_array( 0, $location_ids, true ) || $primary_id === 0 ) {
            self::push_notice( 'error', 'پزشک و حداقل یک محل معتبر را انتخاب کنید.' );
            self::redirect( $clinic_id );
        }

        try {
            $service = new ReceptionRecoveryService(
                App::db(),
                new MembershipRepository( App::db() ),
                App::authorization_service(),
                App::audit()
            );
            $changed = $service->assign( $actor_id, $clinic_id, $clinician_id, $location_ids, $primary_id );
        } catch ( \Throwable ) {
            // Do not log SQL, tenant data or arbitrary exception messages.
            self::push_notice( 'error', 'انتساب ثبت نشد. مجوز، وضعیت پزشک و محل و محل اصلی را بررسی کنید؛ در صورت خطای سامانه هیچ تغییری ثبت نشده است.' );
            self::redirect( $clinic_id );
        }

        self::push_notice(
            'success',
            $changed
            ? 'انتسابِ پزشک به محل با موفقیت ذخیره شد.'
            : 'این انتساب‌ها قبلاً ثبت شده‌اند؛ تغییری لازم نبود.'
        );
        \wp_safe_redirect( \admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . $clinic_id . '&assigned=1' ) );
        exit;
    }

    private static function request_id( mixed $value ): int {
        if ( ! is_scalar( $value ) ) {
            return 0;
        }
        $raw = trim( (string) \wp_unslash( (string) $value ) );
        return preg_match( '/^[1-9][0-9]{0,9}$/', $raw ) === 1 ? (int) $raw : 0;
    }

    private static function render_selector_shell( array $choices, bool $denied ): void {
        echo '<div class="wrap" dir="rtl"><h1>بازیابی پذیرش</h1>';
        if ( $denied ) {
            echo '<div class="notice notice-error"><p>دسترسی به درمانگاهِ انتخاب‌شده مجاز نیست.</p></div>';
        }
        if ( $choices === [] ) {
            echo '<p>هیچ درمانگاهِ واجدِ شرایطی برای شما یافت نشد.</p>';
        } else {
            echo '<p>درمانگاه را انتخاب کنید:</p><ul>';
            foreach ( $choices as $c ) {
                $cid   = (int) ( $c['clinic_id'] ?? 0 );
                $cname = (string) ( $c['clinic_name'] ?? $c['clinic_slug'] ?? '' );
                echo '<li><a href="' . \esc_url( \admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . $cid ) ) . '">' . \esc_html( $cname ) . ' (' . \esc_html( (string) $cid ) . ')</a></li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    private static function push_notice( string $type, string $message ): void {
        \set_transient( self::NOTICE_KEY . '_' . (int) \get_current_user_id(), ['type' => $type, 'message' => $message], 60 );
    }

    private static function pull_notice(): ?array {
        $key    = self::NOTICE_KEY . '_' . (int) \get_current_user_id();
        $notice = \get_transient( $key );
        if ( is_array( $notice ) ) {
            \delete_transient( $key );
            return $notice;
        }
        return null;
    }

    private static function redirect( int $clinic_id = 0 ): void {
        $url = \admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        if ( $clinic_id > 0 ) {
            $url .= '&clinic_id=' . $clinic_id;
        }
        \wp_safe_redirect( $url );
        exit;
    }
}
