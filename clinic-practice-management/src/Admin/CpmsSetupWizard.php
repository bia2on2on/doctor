<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Clinic\ClinicProfileException;
use ClinicCore\Application\Onboarding\FacilityTypeCatalog;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Geography\IranProvinceCityCatalog;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Settings\Settings;

/**
 * «راه‌اندازی گام‌به‌گام» (Setup Wizard) — خود-سرویس و قابلیت ادامه.
 *
 * طراحی این صفحه (Chunk B) دقیقاً برای «مدیر غیرفنی» است: بدون SQL / phpMyAdmin /
 * REST / CLI — نصب → «راه‌اندازی» → پیکربندی کلینیک → کاربران و نقش‌ها →
 * پزشکان → برنامه کاری → SMS (اختیاری) → بکاپ → Health → شروع عملیات.
 *
 * قواعد سخت‌گیرانه (هم‌راستا با مشخصات فاز و سابقه security):
 *  - هیچ منطق business جدیدی ساخته نمی‌شود؛ فقط Orchestration + Persist روی
 *    Settings موجود + ارجاع به صفحات از قبل موجود (پزشک/برنامه، نقش‌ها، SMS،
 *    بکاپ، Health) — بدون duplicate logic.
 *  - هر گام به‌صورت Atomic + resumable ذخیره می‌شود؛ «مرحلهٔ جاری» در
 *    `setup.current_step` ثبت می‌شود تا محو نشود.
 *  - اعتبارسنجی: Nonce (CSRF) + Capability + Sanitize + bounded → سرور.
 *  - هیچ دادهٔ PHI اینجا نمایش داده نمی‌شود (ADR-0002).
 *  - گام پایانی فقط وقتی «READY FOR CLINIC OPERATION» می‌شود که پیش‌نیازهای
 *    الزامی برآورده شده باشند؛ در غیر این صورت راهنما می‌دهد (نه خطای خام).
 *
 * Phase4 — Clinic Profile Canonicalization:
 *  - منبع canonical برای name/address/phone: cpms_clinics
 *  - setup.clinic.* دیگر second writable canonical نیست (تاریخی حذف نمی‌شود)
 *  - saveClinic از مسیر canonical یکسان با سرویس ClinicProfileService استفاده می‌کند
 *  - timezone از این گام حذف شد — حقیقت عملیاتی از Location است، بدون sync
 */
final class CpmsSetupWizard
{
    public const PAGE_SLUG = 'cpms-wizard';

    /** کلید مقصد نهایی (همان کلید مورداستفادهٔ داشبورد/اکشن‌لینک/onboarding). */
    public const COMPLETE_KEY = 'setup.completed';

    private const NOTICE_KEY = 'cpms_wizard_notice';

    /**
     * @return list<array{id:string,title:string,icon:string,required:bool}>
     */
    public static function steps(): array
    {
        return [
            ['id' => 'facility', 'title' => 'نوع مرکز', 'icon' => 'dashicons-building', 'required' => true],
            ['id' => 'welcome', 'title' => 'خوش‌آمدید', 'icon' => 'dashicons-heart', 'required' => true],
            ['id' => 'clinic', 'title' => 'اطلاعات کلینیک', 'icon' => 'dashicons-building', 'required' => true],
            ['id' => 'booking', 'title' => 'تنظیم رزرو نوبت', 'icon' => 'dashicons-calendar-alt', 'required' => true],
            ['id' => 'users', 'title' => 'کاربران و نقش‌ها', 'icon' => 'dashicons-admin-users', 'required' => true],
            ['id' => 'doctors', 'title' => 'پزشکان', 'icon' => 'dashicons-stethoscope', 'required' => true],
            ['id' => 'schedules', 'title' => 'برنامه کاری', 'icon' => 'dashicons-backup', 'required' => true],
            ['id' => 'locations', 'title' => 'مکان‌ها', 'icon' => 'dashicons-location', 'required' => true],
            ['id' => 'sms', 'title' => 'پیامک (اختیاری)', 'icon' => 'dashicons-email-alt', 'required' => false],
            ['id' => 'backup', 'title' => 'پشتیبان‌گیری', 'icon' => 'dashicons-cloud-saved', 'required' => false],
            ['id' => 'license', 'title' => 'مجوز', 'icon' => 'dashicons-yes-alt', 'required' => false],
            ['id' => 'health', 'title' => 'سلامت سیستم', 'icon' => 'dashicons-health', 'required' => true],
            ['id' => 'review', 'title' => 'بازبینی', 'icon' => 'dashicons-visibility', 'required' => true],
            ['id' => 'finish', 'title' => 'شروع عملیات', 'icon' => 'dashicons-universal-access-alt', 'required' => true],
        ];
    }

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_cpms_wizard_save', [self::class, 'save']);
        add_action('admin_post_cpms_wizard_restart', [self::class, 'restart']);
        add_action( 'admin_post_cpms_wizard_facility', [self::class, 'save_facility_selection'] );
    }

    public static function menu(): void
    {
        add_submenu_page(
            CpmsAdminMenu::parentSlug(),
            'راه‌اندازی',
            'راه‌اندازی',
            RolesAndCapabilities::CONFIG,
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    // ================= Render =================

    public static function render(): void
    {
        if (!current_user_can(RolesAndCapabilities::CONFIG)) {
            wp_die('دسترسی ندارید', 403);
        }

        $notice = get_transient(self::NOTICE_KEY);
        if ($notice !== false) {
            delete_transient(self::NOTICE_KEY);
        }

        // Single visible onboarding journey: an eligible first run (fresh
        // bootstrap, strictly-eligible interrupted-setup recovery, or a
        // blocked interrupted setup) is handled by the embedded
        // first-practice panel, reusing FirstPracticeSetupService. This is
        // checked before any Clinic-scoped Settings access because the
        // actor has zero memberships in that state. Rendering only.
        if ( current_user_can( 'manage_options' ) && self::onboarding_panel_applies() ) {
            if ( self::selected_facility_type() === '' ) {
                // First screen = facility question, with the embedded
                // first-practice bootstrap panel below it (single journey;
                // the existing browser acceptance requires the bootstrap
                // form to stay embedded in the wizard).
                self::render_facility_first_screen();
                FirstPracticeSetupPage::render();
                return;
            }
            self::render_flow_summary();
            FirstPracticeSetupPage::render();
            return;
        }

        try {
            $settings = App::settings();
        } catch ( ScopeRequiredException ) {
            $guidance = CpmsAdminMenu::ambiguous_clinic_guidance();
            echo '<div class="wrap" dir="rtl">';
            echo '<h1>' . esc_html( FacilityTypeCatalog::wizard_heading( self::selected_facility_type() ) ) . '</h1>';
            echo '<div class="notice notice-warning" data-cpms-scope-required="1"><p>';
            echo esc_html( $guidance );
            echo '</p></div></div>';
            return;
        }
        $steps = self::active_steps();
        $index = self::currentStepIndex();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selector; values are restricted to registered steps.
        if ( isset( $_GET['step'] ) && is_string( $_GET['step'] ) ) {
            $requested_step = sanitize_key( wp_unslash( $_GET['step'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, registry-whitelisted navigation value.
            if ( in_array( $requested_step, array_column( $steps, 'id' ), true ) ) {
                $index = self::stepIndexById( $requested_step );
            }
        }
        $step = $steps[ $index ];
        // Truthful completion state: the historical setup.completed flag is
        // preserved in storage, but the banner/indicator only claims
        // completion when the CURRENT flow supports provisioning and every
        // CURRENT required step is verifiably complete.
        $is_complete = self::current_flow_is_complete( $settings );

        // GET is strictly read-only: rendering must never persist settings
        // or audit rows (a former setup.started_at write here is what made
        // migration 0026's conservative backfill consume the first-setup
        // marker for merely-viewed, zero-membership installations).
        ?>
        <div class="wrap" dir="rtl">
            <h1><?php echo esc_html( FacilityTypeCatalog::wizard_heading( self::selected_facility_type() ) ); ?> — قدم <?php echo esc_html((string) ($index + 1)); ?> از <?php echo esc_html((string) count($steps)); ?></h1>
            <p class="description">
                این راهنما به شما کمک می‌کند بدون نیاز به دانش فنی، مطب را راه بیندازید.
                پیشرفت شما ذخیره می‌شود و هر زمان می‌توانید ادامه دهید.
            </p>

            <?php if (is_string($notice) && $notice !== '') : ?>
                <div class="notice <?php echo str_starts_with($notice, 'خطا') ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <?php if ( $is_complete ) : ?>
                <div class="notice notice-success"><p>✅ راه‌اندازی تکمیل شده است — کلینیک برای شروع عملیات آماده است.</p></div>
            <?php endif; ?>

            <?php if ( CpmsAdminMenu::is_first_practice_setup_pending() ) : ?>
                <div class="notice notice-info">
                    <p>
                        <strong>راه‌اندازی اولین مطب:</strong>
                        پیش از ویرایش مشخصات کلینیک در این ویزارد، ابتدا هویت مطب اولیه و عضویت مدیریتی خود را در صفحهٔ اختصاصی تأیید کنید.
                        <a class="button button-primary" data-cpms-first-practice-link="1" href="<?php echo esc_url( admin_url( 'admin.php?page=' . FirstPracticeSetupPage::PAGE_SLUG ) ); ?>">راه‌اندازی مطب من</a>
                    </p>
                </div>
            <?php endif; ?>

            <nav aria-label="گام‌های راه‌اندازی" style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0;max-width:100%;">
                <?php foreach ($steps as $i => $s) : ?>
                    <?php
                    $current    = $i === $index;
                    $done       = self::step_is_complete( $s['id'], $settings );
                    $icon_class = $current ? 'dashicons-star-filled' : ( $done ? 'dashicons-yes' : 'dashicons-marker' );
                    $icon_style = $current ? 'background:#2271b1;color:#fff;padding:2px 8px;border-radius:3px;' : ( $done ? 'color:#00a32a;' : 'color:#787c82;' );
                    ?>
                    <a
                        data-cpms-wizard-step="<?php echo esc_attr( $s['id'] ); ?>"
                        href="<?php echo esc_url( self::step_url( $s['id'] ) ); ?>"
                        aria-current="<?php echo $current ? 'step' : 'false'; ?>"
                        class="dashicons-before <?php echo esc_attr( $icon_class ); ?>"
                        style="display:inline-block;text-decoration:none;box-sizing:border-box;max-width:100%;padding:6px 8px;min-height:36px;line-height:1.8;<?php echo esc_attr( $icon_style ); ?>">
                        <?php echo esc_html( (string) ( $i + 1 ) . '. ' . $s['title'] . ( $s['required'] ? ' (الزامی)' : ' (اختیاری)' ) ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php self::render_flow_summary(); ?>
            <?php self::renderStep($step, $settings); ?>
        </div>
        <?php
    }

    // ================= Getters / helpers =================

    private static function currentStepIndex(): int
    {
        $settings = App::settings();
        $store = (string) ($settings->get('setup.current_step', 'welcome') ?? 'welcome');
        foreach ( self::active_steps() as $i => $s ) {
            if ($s['id'] === $store) {
                return $i;
            }
        }

        return 0;
    }

    private static function stepIndexById(string $id): int
    {
        foreach ( self::active_steps() as $i => $s ) {
            if ($s['id'] === $id) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * URL یک گام فقط از registry گام‌های ویزارد ساخته می‌شود.
     */
    private static function step_url( string $step_id ): string {
        if ( ! in_array( $step_id, array_column( self::steps(), 'id' ), true ) ) {
            return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        }

        return add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'step' => $step_id ],
            admin_url( 'admin.php' )
        );
    }

    private static function setCurrentStep(string $id): void
    {
        App::settings()->set('setup.current_step', $id, (int) get_current_user_id());
    }

    /**
     * گام بعدی (یا همان گام اگر آخرین باشد).
     */
    private static function nextStepId(string $id): string
    {
        $steps = self::active_steps();
        $found = false;
        foreach ($steps as $s) {
            if ($found) {
                return $s['id'];
            }
            if ($s['id'] === $id) {
                $found = true;
            }
        }

        return $id;
    }

    // ================= Step renderers =================

    private static function renderStep(array $step, Settings $settings): void
    {
        switch ($step['id']) {
            case 'facility':
                self::render_facility( $settings );
                break;
            case 'welcome':
                self::renderWelcome();
                break;
            case 'clinic':
                self::renderClinic($settings);
                break;
            case 'booking':
                self::renderBooking($settings);
                break;
            case 'users':
                self::renderUsers();
                break;
            case 'doctors':
                self::renderDoctors();
                break;
            case 'schedules':
                self::renderSchedules();
                break;
            case 'locations':
                self::render_locations();
                break;
            case 'sms':
                self::renderSms($settings);
                break;
            case 'backup':
                self::renderBackup();
                break;
            case 'license':
                self::renderLicense();
                break;
            case 'health':
                self::renderHealth();
                break;
            case 'review':
                self::render_review( $settings );
                break;
            case 'finish':
                self::renderFinish($settings);
                break;
            default:
                self::renderWelcome();
        }
    }

    private static function open_step_form( string $step_id, string $extra_notice = '' ): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'cpms_wizard_save', '_wpnonce' ); ?>
            <input type="hidden" name="action" value="cpms_wizard_save">
            <input type="hidden" name="step" value="<?php echo esc_attr( $step_id ); ?>">
            <?php echo $extra_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML از کد داخل همین کلاس ساخته می‌شود ?>
        <?php
    }

    private static function submit_step_form( string $step_id, string $submit_label ): void {
        ?>
            <p class="submit">
                <button type="submit" class="button button-primary button-large"><?php echo esc_html( $submit_label ); ?></button>
                <?php if ( $step_id !== 'finish' ) : ?>
                    <a class="button" href="<?php echo esc_url( self::step_url( 'review' ) ); ?>">پرش به بازبینی</a>
                <?php endif; ?>
            </p>
        <?php
    }

    private static function close_step_form(): void {
        ?>
        </form>
        <?php
    }

    private static function form_tag( string $step_id, string $submit_label, string $extra_notice = '' ): void {
        self::open_step_form( $step_id, $extra_notice );
        self::submit_step_form( $step_id, $submit_label );
        self::close_step_form();
    }

    private static function renderWelcome(): void
    {
        ?>
        <div class="card">
            <h2>چه کاری انجام می‌شود؟</h2>
            <p>این راهنما در <?php echo esc_html( (string) count( self::steps() ) ); ?> گام به شما کمک می‌کند مطب را به‌صورت امن راه‌اندازی کنید؛ از اطلاعات کلینیک و رزرو تا بررسی سلامت و شروع عملیات.</p>
            <p>همهٔ مراحل ذخیره می‌شوند؛ اگر وسط کار خارج شوید، از همان‌جا ادامه می‌دهید.</p>
            <p><strong>توجه:</strong> این صفحه هیچ دادهٔ پزشکی/حساس بیمار را نمایش یا ذخیره نمی‌کند.</p>
        </div>
        <?php
        self::form_tag( 'welcome', 'شروع' );
    }

    private static function renderClinic(Settings $settings): void
    {
        if ( CpmsAdminMenu::is_first_practice_setup_pending() ) {
            ?>
            <div class="card">
                <h2>مشخصات کلینیک — نیازمند راه‌اندازی اولین مطب</h2>
                <p>در نصب تازه، پیش از ویرایش مشخصات کلینیک باید هویت مطب اولیه را تأیید کنید تا عضویت مدیریتی شما در کلینیک به صورت رسمی ثبت شود.</p>
                <p>
                    <a class="button button-primary" data-cpms-first-practice-link="1" href="<?php echo esc_url( admin_url( 'admin.php?page=' . FirstPracticeSetupPage::PAGE_SLUG ) ); ?>">رفتن به «راه‌اندازی مطب من»</a>
                </p>
            </div>
            <?php
            return;
        }

        if ( ! ClinicianAdminPage::can_current_user_access_page() ) {
            ?>
            <div class="notice notice-warning">
                <p>برای ذخیرهٔ مشخصات کلینیک، عضویت فعال در همین مطب و مجوز پیکربندی لازم است. به همین دلیل فرم ویرایش نمایش داده نمی‌شود.</p>
            </div>
            <?php
            return;
        }

        // Canonical source: cpms_clinics — no longer setup.clinic.* as second writable canonical.
        // Historical setup.clinic.* rows are not deleted, but we do not read them for display as primary;
        // we show canonical values. Fallback to old settings only if canonical unavailable (e.g. pre-migration).
        $canonical = null;
        try {
            $scope = ScopeContext::tryGet() ?? App::scope();
            $canonical = App::clinicRepository()->find((int) $scope->clinicId);
        } catch ( \Throwable ) {
            $canonical = null;
        }

        if (is_array($canonical)) {
            $name = (string) ($canonical['name'] ?? '');
            $address = (string) ($canonical['address'] ?? '');
            $phone = (string) ($canonical['phone'] ?? '');
        } else {
            // Fallback for edge cases (e.g. tests without DB) — keep old keys read-only, not writable.
            $name = (string) ($settings->get('setup.clinic.name', '') ?? '');
            $address = (string) ($settings->get('setup.clinic.address', '') ?? '');
            $phone = (string) ($settings->get('setup.clinic.phone', '') ?? '');
        }

        ?>
        <?php self::open_step_form( 'clinic' ); ?>
        <?php $facility_profile = self::current_facility_profile(); ?>
        <div class="card">
            <h2>مشخصات <?php echo esc_html( $facility_profile['unit'] ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="cname"><?php echo esc_html( $facility_profile['name_label'] ); ?></label></th>
                    <td><input type="text" id="cname" name="clinic_name" class="regular-text" required value="<?php echo esc_attr($name); ?>"></td>
                </tr>
                <tr>
                    <th><label for="caddr">آدرس</label></th>
                    <td><?php AddressSelectorField::render( $address, 'caddr', 'address_province', 'address_city', 'caddr' ); ?><input type="text" id="caddr" name="clinic_address" class="regular-text" value="<?php echo esc_attr( $address ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="cphone">تلفن</label></th>
                    <td><input type="text" id="cphone" name="clinic_phone" class="regular-text" value="<?php echo esc_attr($phone); ?>"></td>
                </tr>
            </table>
            <p class="description">این اطلاعات فقط برای شناسایی مطب است و هیچ دادهٔ پزشکی در آن نیست. ساعت کاری مطب از برنامهٔ کاری پزشکان مشخص می‌شود و اینجا ویرایش نمی‌شود.</p>
            <?php self::submit_step_form( 'clinic', 'ذخیره و ادامه' ); ?>
        </div>
        <?php
        self::close_step_form();
    }

    private static function renderBooking(Settings $settings): void
    {
        if ( ! ClinicianAdminPage::can_current_user_access_page() ) {
            ?>
            <div class="notice notice-warning">
                <p>برای ذخیرهٔ تنظیمات رزرو، عضویت فعال در همین مطب و مجوز پیکربندی لازم است. به همین دلیل فرم ویرایش نمایش داده نمی‌شود.</p>
            </div>
            <?php
            return;
        }

        $duration = (int) $settings->get('booking.duration_default_min', 20);
        $capacity = (int) $settings->get('booking.slot_capacity_default', 1);
        $lead = (int) $settings->get('booking.min_lead_hours', 2);
        $future = (int) $settings->get('booking.max_future_days', 60);
        $cancel = (int) $settings->get('booking.cancel_deadline_hours', 24);
        ?>
        <?php self::open_step_form( 'booking' ); ?>
        <div class="card">
            <h2>تنظیم پیش‌فرض رزرو</h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="b1">مدت هر نوبت (دقیقه)</label></th><td><input type="number" id="b1" name="duration" min="5" max="240" required value="<?php echo esc_attr( (string) $duration ); ?>"></td></tr>
                <tr><th><label for="b2">ظرفیت هر زمان‌بازه</label></th><td><input type="number" id="b2" name="capacity" min="1" max="20" required value="<?php echo esc_attr( (string) $capacity ); ?>"></td></tr>
                <tr><th><label for="b3">حداقل فاصلهٔ رزرو (ساعت)</label></th><td><input type="number" id="b3" name="lead" min="1" max="72" required value="<?php echo esc_attr( (string) $lead ); ?>"></td></tr>
                <tr><th><label for="b4">سقف رزرو آینده (روز)</label></th><td><input type="number" id="b4" name="future" min="1" max="365" required value="<?php echo esc_attr( (string) $future ); ?>"></td></tr>
                <tr><th><label for="b5">مهلت لغو (ساعت)</label></th><td><input type="number" id="b5" name="cancel" min="0" max="168" required value="<?php echo esc_attr( (string) $cancel ); ?>"></td></tr>
            </table>
            <p class="description">پس از ذخیره، همین مقادیر به‌عنوان پیش‌فرض در رزرو نوبت استفاده می‌شوند.</p>
            <?php self::submit_step_form( 'booking', 'ذخیره و ادامه' ); ?>
        </div>
        <?php
        self::close_step_form();
    }

    private static function renderUsers(): void
    {
        $roles = [
            'administrator',
            RolesAndCapabilities::ROLE_DOCTOR,
            RolesAndCapabilities::ROLE_SECRETARY,
            RolesAndCapabilities::ROLE_PATIENT,
        ];
        $users = count(get_users(['fields' => 'ID', 'role__in' => $roles])); // phpcs:ignore
        ?>
        <div class="card">
            <h2>کاربران و نقش‌ها</h2>
            <p class="description" data-cpms-users-step-optional="1">این گام اختیاری است: هیچ کاربر جدیدی اینجا ساخته نمی‌شود و راه‌اندازی بدون آن متوقف نمی‌شود؛ پرسنل پذیرش و مالی را هر زمان که نیاز داشتید اضافه کنید.</p>
            <p>کاربران، نقش‌ها، مجوزهای پیش‌فرض و مجوزهای پیشرفته را می‌توانید از صفحهٔ «کاربران و دسترسی‌ها» مدیریت کنید.</p>
            <p>تعداد کاربران دارای نقش‌های CPMS/ادمین: <strong><?php echo esc_html((string) $users); ?></strong></p>
            <ul>
                <li><strong>نقش‌های پیش‌فرض:</strong> مدیر کلینیک (cpms_config)، منشی، پزشک، بیمار.</li>
                <li>مجوزهای پیش‌فرض انسانی و قابل‌فهم هستند؛ مجوزهای پیشرفته به‌صورت جمع‌شده و با توضیح فارسی است.</li>
                <li>ایجاد/مدیریت پرسنل از صفحهٔ «کاربران و دسترسی‌ها» انجام می‌شود؛ نقش administrator قابل انتساب نیست (جلوگیری از ارتقاء غیرمجاز).</li>
                <li>هیچ تغییر مجوزی بدون ثبت در Audit انجام نمی‌شود.</li>
            </ul>
            <p>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-staff')); ?>">رفتن به «کاربران و دسترسی‌ها»</a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-roles')); ?>">مجوزهای پیشرفته</a>
            </p>
        </div>
        <?php
        self::form_tag( 'users', 'ادامه' );
    }

    private static function renderDoctors(): void
    {
        $count                 = count( App::clinicianRepository()->listAll( App::scope()->clinicId, false ) );
        $can_manage_clinicians = ClinicianAdminPage::can_current_user_access_page();
        $facility_profile      = self::current_facility_profile();
        ?>
        <div class="card">
            <h2><?php echo esc_html( $facility_profile['clinicians'] ); ?></h2>
            <p><?php echo esc_html( $facility_profile['clinicians'] ); ?> را اضافه و به کاربر و برنامهٔ کاری متصل کنید. افزودن <?php echo esc_html( $facility_profile['clinician'] ); ?> بدون حذف تاریخچه انجام می‌شود.</p>
            <p>تعداد <?php echo esc_html( $facility_profile['clinicians'] ); ?> فعال: <strong><?php echo esc_html( (string) $count ); ?></strong></p>
            <?php if ( $can_manage_clinicians ) : ?>
                <p><a class="button" data-cpms-wizard-action="clinicians" href="<?php echo esc_url( admin_url( 'admin.php?page=cpms-clinicians' ) ); ?>">رفتن به «پزشکان و برنامه کاری»</a></p>
            <?php endif; ?>
        </div>
        <?php self::open_step_form( 'doctors' ); ?>
        <?php
        $min_clinicians   = self::flow_min_clinicians();
        $is_multi_doctor  = $min_clinicians >= 2;
        $intended_display = max( $min_clinicians, self::intended_clinicians( App::settings() ) );
        ?>
        <div class="card">
            <p>
                <label for="cpms_intended_clinicians">تعداد <?php echo esc_html( $facility_profile['clinicians'] ); ?> موردنظر</label>
                <input type="number" id="cpms_intended_clinicians" name="intended_clinicians" min="<?php echo esc_attr( (string) $min_clinicians ); ?>" max="200" style="max-width:100%;" value="<?php echo esc_attr( (string) $intended_display ); ?>">
            </p>
            <p class="description">
                این گام فقط وقتی کامل است که دست‌کم به همین تعداد <?php echo esc_html( $facility_profile['clinician'] ); ?> فعال واقعاً ثبت شده باشد؛ اعلام عدد به‌تنهایی کافی نیست.
                <?php if ( $is_multi_doctor ) : ?>
                    برای جریان‌های چندارائه‌دهنده (درمانگاه چندپزشکه و شبکهٔ سرپایی بیمارستان)، مقدار موردنظر باید دست‌کم ۲ باشد (اعتبارسنجی سمت سرور).
                <?php endif; ?>
            </p>
            <?php self::submit_step_form( 'doctors', 'ذخیره و ادامه' ); ?>
        </div>
        <?php
        self::close_step_form();
    }

    private static function renderSchedules(): void
    {
        $can_manage_clinicians = ClinicianAdminPage::can_current_user_access_page();
        ?>
        <div class="card">
            <h2>برنامه کاری هفتگی</h2>
            <p>برنامهٔ هفتگی هر پزشک (۷ روز) و استثناها (تعطیلی/مرخصی) از صفحهٔ «پزشکان و برنامه کاری» تنظیم می‌شود.</p>
            <?php if ( $can_manage_clinicians ) : ?>
                <p><a class="button" data-cpms-wizard-action="clinicians" href="<?php echo esc_url( admin_url( 'admin.php?page=cpms-clinicians' ) ); ?>">رفتن به تنظیم برنامه</a></p>
            <?php endif; ?>
        </div>
        <?php
        self::form_tag( 'schedules', 'ادامه' );
    }

    private static function renderSms(Settings $settings): void
    {
        if ( ! ClinicianAdminPage::can_current_user_access_page() ) {
            ?>
            <div class="notice notice-warning">
                <p>برای ذخیرهٔ تنظیمات پیامک، عضویت فعال در همین مطب و مجوز پیکربندی لازم است. به همین دلیل فرم ویرایش نمایش داده نمی‌شود.</p>
            </div>
            <?php
            return;
        }

        $provider = (string) ($settings->get('sms.provider', '') ?? '');
        $sender = (string) ($settings->get('sms.sender', '') ?? '');
        ?>
        <div class="card">
            <h2>پیامک (اختیاری)</h2>
            <p>برای ارسال اعلان/یادآوری به بیماران (OTP، نوبت، و…) پیامک را پیکربندی کنید. این گام اختیاری است.</p>
            <p>ارائه‌دهندهٔ فعلی: <strong><?php echo esc_html($provider !== '' ? $provider : 'لایهٔ لاگ (پیکربندی نشده)'); ?></strong> — فرستنده: <strong><?php echo esc_html($sender !== '' ? $sender : '—'); ?></strong></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('cpms_wizard_save', '_wpnonce'); ?>
                <input type="hidden" name="action" value="cpms_wizard_save">
                <input type="hidden" name="step" value="sms">
                <label>ارائه‌دهندهٔ پیامک: <input type="text" name="sms_provider" value="<?php echo esc_attr($provider); ?>" placeholder="generic_api"></label>
                <label>فرستنده: <input type="text" name="sms_sender" value="<?php echo esc_attr($sender); ?>"></label>
                <p class="submit"><button type="submit" class="button button-primary">ذخیره و ادامه</button></p>
            </form>
            <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-sms')); ?>">تنظیمات کامل پیامک</a></p>
        </div>
        <?php
    }

    private static function renderBackup(): void
    {
        $backupEnabled = App::installationSettings()->getBackupEnabled();
        ?>
        <div class="card">
            <h2>پشتیبان‌گیری (اختیاری)</h2>
            <p>پشتیبان‌گیری خودکار از پایگاه‌داده (بدون دادهٔ پزشکی خام در شبکهٔ عمومی) از صفحهٔ «فنی و سلامت» فعال می‌شود.</p>
            <p>وضعیت فعلی: <strong><?php echo $backupEnabled ? 'فعال' : 'غیرفعال'; ?></strong></p>
            <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-settings')); ?>">رفتن به تنظیمات بکاپ</a></p>
        </div>
        <?php
        self::form_tag( 'backup', 'ادامه' );
    }

    private static function renderLicense(): void
    {
        $state = App::licenseService()->currentState();
        $status = (string) ($state['status'] ?? 'unknown');
        ?>
        <div class="card">
            <h2>مجوز (اختیاری)</h2>
            <p>فعال‌سازی/وضعیت مجوز از صفحهٔ سلامت سیستم قابل مشاهده و مدیریت است.</p>
            <p>وضعیت مجوز: <strong><?php echo esc_html($status); ?></strong></p>
            <p><a class="button" data-cpms-wizard-action="system" href="<?php echo esc_url( admin_url( 'admin.php?page=cpms-system' ) ); ?>">رفتن به سلامت/مجوز</a></p>
        </div>
        <?php
        self::form_tag( 'license', 'ادامه' );
    }

    private static function renderHealth(): void
    {
        $result = App::systemHealthService()->run();
        $checks = $result['checks'] ?? [];
        ?>
        <div class="card">
            <h2>بررسی سلامت پیش‌نیازها</h2>
            <p style="color:#787c82;">این بررسی، سلامت میزبان (PHP، دیتابیس، Schema، Cron، Storage، License) را نشان می‌دهد.</p>
            <table class="widefat striped" role="presentation">
                <thead><tr><th>نوع</th><th>وضعیت</th><th>جزئیات</th></tr></thead>
                <tbody>
                <?php foreach ($checks as $c) : ?>
                    <tr>
                        <td><?php echo esc_html((string) ($c['label'] ?? $c['key'] ?? '')); ?></td>
                        <td>
                            <?php
                            $st = (string) ($c['status'] ?? '');
                            $cls = $st === 'pass' ? '#00a32a' : ($st === 'warning' ? '#b26b00' : '#d63638');
                            echo '<span style="color:' . esc_attr($cls) . ';font-weight:600;">' . esc_html($st) . '</span>';
                            ?>
                        </td>
                        <td><?php echo esc_html((string) ($c['detail'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">در صورت وجود وضعیت «fail»، پیش از شروع عملیات آن را برطرف کنید؛ می‌توانید بعداً به این صفحه برگردید.</p>
            <p><a class="button" data-cpms-wizard-action="system" href="<?php echo esc_url( admin_url( 'admin.php?page=cpms-system' ) ); ?>">بازکردن جزئیات و ابزارهای سیستم</a></p>
        </div>
        <?php
        if ( self::current_flow() === 'hospital_network' ) :
            ?>
            <?php self::open_step_form( 'health' ); ?>
            <div class="card" data-cpms-hospital-outpatient-scope="1">
                <h2>محدودهٔ پشتیبانی بیمارستان — فقط سرپایی</h2>
                <div class="notice notice-warning inline" data-cpms-inpatient-unsupported="1">
                    <p>بخش بستری و سامانهٔ جامع بیمارستانی (HIS) پشتیبانی نمی‌شود؛ این راه‌اندازی فقط درمانگاه‌ها و فرایندهای سرپایی را پوشش می‌دهد و هیچ ماژول بستری ساخته نمی‌شود.</p>
                </div>
                <p>
                    <label for="cpms_outpatient_only_ack" style="display:block;padding:10px;min-height:44px;box-sizing:border-box;">
                        <input type="checkbox" id="cpms_outpatient_only_ack" name="outpatient_only_ack" value="1" <?php checked( (bool) App::settings()->get( 'setup.outpatient_only_ack', false ) ); ?>>
                        تأیید می‌کنم این مرکز را فقط برای فرایندهای سرپایی راه‌اندازی می‌کنم و می‌دانم بخش بستری و HIS پشتیبانی نمی‌شود.
                    </label>
                </p>
                <?php self::submit_step_form( 'health', 'ذخیره و ادامه' ); ?>
            </div>
            <?php
            self::close_step_form();
        else :
            self::form_tag( 'health', 'ادامه' );
        endif;
    }

    /**
     * Health-step save. For the hospital flow the step is conditional: it
     * requires an explicit outpatient-only acknowledgment (persisted as a
     * Clinic-scoped configuration key). For every other flow the health step
     * stays informational exactly as before. Never destructive: an existing
     * acknowledgment is preserved; no tenant row is touched.
     *
     * @param array<string, mixed> $post
     *
     * @return string '' = ok
     */
    public static function save_health( Settings $settings, array $post, int $updated_by ): string {
        // Defense in depth: same Clinic-local CONFIG gate as every other
        // wizard write, before any flow or payload inspection.
        if ( ! self::has_clinic_local_config( $updated_by ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- pre-existing public wizard API parameter name
            return 'برای ذخیره در راه‌اندازی، عضویت فعال در همین مطب و مجوز پیکربندی لازم است.';
        }
        if ( self::current_flow() !== 'hospital_network' ) {
            return '';
        }
        // Fresh-acknowledgment semantics: EVERY submission must carry the
        // affirmative check on the current POST; a previously stored true
        // flag is never a substitute (it is additionally reset whenever the
        // type/structure selection changes or progress is restarted). A
        // rejected submission never destroys the stored historical value.
        if ( ! isset( $post['outpatient_only_ack'] ) || (string) $post['outpatient_only_ack'] !== '1' ) {
            return 'برای جریان بیمارستان، ثبت این گام فقط با تأیید صریح و تازهٔ «فقط فرایندهای سرپایی» در همین فرم ممکن است؛ بخش بستری و HIS پشتیبانی نمی‌شود.';
        }
        $settings->set( 'setup.outpatient_only_ack', true, $updated_by );

        return '';
    }

    private static function canonical_clinic_name( Settings $settings ): string {
        try {
            $scope = ScopeContext::tryGet() ?? App::scope();
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ScopeContext exposes the public domain property as clinicId.
            $row = App::clinicRepository()->find( (int) $scope->clinicId );
            if ( is_array( $row ) ) {
                // An empty canonical value is a real blocker; never mask it with stale settings.
                return trim( (string) ( $row['name'] ?? '' ) );
            }
        } catch ( \Throwable ) {
            // Fallback is limited to legacy installs where the canonical row is unavailable.
            return trim( (string) ( $settings->get( 'setup.clinic.name', '' ) ?? '' ) );
        }

        return trim( (string) ( $settings->get( 'setup.clinic.name', '' ) ?? '' ) );
    }

    private static function render_prerequisite_links( string $clinic_name, int $doctor_count ): void {
        $clinic_missing     = trim( $clinic_name ) === '';
        $clinicians_missing = $doctor_count === 0;
        if ( ! $clinic_missing && ! $clinicians_missing ) {
            return;
        }

        $can_manage_scoped_clinic = ClinicianAdminPage::can_current_user_access_page();
        $first_practice_pending   = CpmsAdminMenu::is_first_practice_setup_pending();
        $profile                  = self::current_facility_profile();
        ?>
        <ul class="cpms-wizard-prerequisite-actions">
            <?php if ( $clinic_missing ) : ?>
                <li>
                    اطلاعات <?php echo esc_html( $profile['unit'] ); ?> ثبت نشده است.
                    <?php if ( $can_manage_scoped_clinic ) : ?>
                        <a data-cpms-wizard-action="clinic" href="<?php echo esc_url( self::step_url( 'clinic' ) ); ?>">اصلاح مشخصات <?php echo esc_html( $profile['unit'] ); ?></a>
                    <?php elseif ( $first_practice_pending ) : ?>
                        <a data-cpms-first-practice-link="1" href="<?php echo esc_url( admin_url( 'admin.php?page=' . FirstPracticeSetupPage::PAGE_SLUG ) ); ?>">راه‌اندازی اولین مطب</a>
                    <?php else : ?>
                        <span>برای اصلاح، عضویت فعال در <?php echo esc_html( $profile['unit'] ); ?> و مجوز پیکربندی لازم است.</span>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
            <?php if ( $clinicians_missing ) : ?>
                <li>
                    <?php echo esc_html( $profile['clinician'] ); ?> فعالی ثبت نشده است.
                    <?php if ( $can_manage_scoped_clinic ) : ?>
                        <a data-cpms-wizard-action="clinicians" href="<?php echo esc_url( admin_url( 'admin.php?page=cpms-clinicians' ) ); ?>">افزودن/مدیریت <?php echo esc_html( $profile['clinicians'] ); ?></a>
                    <?php elseif ( $first_practice_pending ) : ?>
                        <a data-cpms-first-practice-link="1" href="<?php echo esc_url( admin_url( 'admin.php?page=' . FirstPracticeSetupPage::PAGE_SLUG ) ); ?>">ابتدا اولین مطب را راه‌اندازی کنید</a>
                    <?php else : ?>
                        <span>برای مدیریت <?php echo esc_html( $profile['clinicians'] ); ?>، عضویت فعال در <?php echo esc_html( $profile['unit'] ); ?> و مجوز پیکربندی لازم است.</span>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
        </ul>
        <?php
    }

    private static function render_review( Settings $settings ): void {
        $clinic_name      = self::canonical_clinic_name( $settings );
        $doctor_count     = count( App::clinicianRepository()->listAll( App::scope()->clinicId, false ) );
        $facility_profile = self::current_facility_profile();
        ?>
        <?php if ( self::current_flow() === 'medical_building' ) : ?>
            <div class="notice notice-info inline" data-cpms-building-handoff="1" style="margin-bottom:12px;">
                <p>
                    <strong>ساختمان پزشکی با چند مطب مستقل:</strong>
                    این ویزارد فقط همین کلینیکِ دارای محدودهٔ صریح و مجاز را پیکربندی می‌کند و درمانگاه مستقلِ جدید نمی‌سازد؛ هیچ محدودهٔ مستأجری از اینجا حدس زده نمی‌شود.
                    ایجاد هر کلینیک جدید (اتمی، با ممیزی) فقط با اختیار مدیر سازمان و انتخاب صریح سازمان در «سازمان‌ها → مدیریت سازمان و درمانگاه‌ها» انجام می‌شود.
                    پس از ایجاد، پیکربندی هر کلینیک با انتخاب صریح همان کلینیک و عضویت معتبر در آن ادامه می‌یابد؛ تکمیل راه‌اندازی ساختمان پزشکی در این ویزارد عمداً مسدود و امن باقی می‌ماند.
                </p>
                <p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=cpms-organizations' ) ); ?>">رفتن به «مدیریت سازمان و درمانگاه‌ها»</a></p>
            </div>
        <?php endif; ?>
        <div class="card">
            <h2>بازبینی پیش از شروع</h2>
            <table class="form-table" role="presentation">
                <tr><th><?php echo esc_html( $facility_profile['name_label'] ); ?></th><td><?php echo esc_html( $clinic_name !== '' ? $clinic_name : '—' ); ?></td></tr>
                <tr><th><?php echo esc_html( $facility_profile['clinicians'] ); ?> فعال</th><td><?php echo esc_html( (string) $doctor_count ); ?></td></tr>
                <tr><th>تعداد <?php echo esc_html( $facility_profile['clinicians'] ); ?> موردنظر</th><td><?php echo esc_html( (string) self::intended_clinicians( $settings ) ); ?></td></tr>
                <tr><th>مکان‌های فعال</th><td><?php echo esc_html( (string) count( array_filter( App::locationRepository()->listForClinic( (int) App::scope()->clinicId ), static fn ( $loc ) => (int) ( $loc['is_active'] ?? 0 ) === 1 ) ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase ?></td></tr>
                <tr><th>مدت پیش‌فرض نوبت (دقیقه)</th><td><?php echo esc_html( (string) (int) $settings->get( 'booking.duration_default_min', 20 ) ); ?></td></tr>
                <tr><th>گام‌های الزامیِ باقی‌مانده</th><td>
                <?php
                $missing_steps = self::missing_required_steps( $settings );
                echo esc_html( $missing_steps === [] ? 'هیچ‌کدام' : implode( '، ', $missing_steps ) );
                ?>
                </td></tr>
                <tr><th>دسترسی مدیریت</th><td>cpms_config (مدیر)</td></tr>
            </table>
            <p>برای تکمیل، اطلاعات <?php echo esc_html( $facility_profile['unit'] ); ?> و دست‌کم یک <?php echo esc_html( $facility_profile['clinician'] ); ?> فعال لازم است.</p>
            <?php self::render_prerequisite_links( $clinic_name, $doctor_count ); ?>
        </div>
        <?php
        self::form_tag( 'review', 'ذخیره و ادامه' );
    }

    private static function renderFinish(Settings $settings): void
    {
        $clinic_name      = self::canonical_clinic_name( $settings );
        $doctor_count     = count( App::clinicianRepository()->listAll( App::scope()->clinicId, false ) );
        $facility_chosen  = self::facility_selected( $settings );
        $supports_flow    = FacilityTypeCatalog::flow_supports_provisioning( self::current_flow() );
        $missing_now      = $facility_chosen ? self::missing_required_steps( $settings ) : [];
        $stored_completed = (bool) $settings->get( self::COMPLETE_KEY, false );
        // Same invariant as the header banner and step indicator: the stored
        // historical flag is preserved, but the card only claims readiness
        // when the CURRENT flow genuinely satisfies it.
        $ready      = $facility_chosen
            ? ( $supports_flow && $missing_now === [] )
            : ( $clinic_name !== '' && $doctor_count > 0 );
        $stale_note = '';
        if ( $stored_completed ) {
            $stale_note = $supports_flow
                ? ' تکمیل راه‌اندازی تاریخی (از جریان پیشین) در سوابق باقی مانده، اما نشانهٔ آمادگی جریان فعلی نیست؛ هیچ دادهٔ مستأجر یا تاریخچه‌ای حذف نشده است.'
                : ' تکمیل راه‌اندازی تاریخی (از جریان پیشین) در سوابق باقی مانده و هیچ دادهٔ مستأجر یا تاریخچه‌ای حذف نشده است.';
        }
        ?>
        <div class="card" data-cpms-finish-ready="<?php echo $ready ? '1' : '0'; ?>">
            <h2>شروع عملیات کلینیک</h2>
            <?php if ( $ready ) : ?>
                <p>🎉 همهٔ پیش‌نیازهای الزامی برآورده شده‌اند. با فشردن دکمهٔ بالا، راه‌اندازی تکمیل و وضعیت «آمادهٔ شروع عملیات» ثبت می‌شود.</p>
            <?php elseif ( $facility_chosen && ! $supports_flow ) : ?>
                <p style="color:#b26b00;" data-cpms-stale-completion="<?php echo $stored_completed ? '1' : '0'; ?>">⚠️ تکمیل راه‌اندازی برای ساختمان/مجتمع پزشکی در این ویزارد به‌شکل ایمن مسدود است؛ ایجاد درمانگاه مستقل فقط از طریق اختیار صریح مدیر سازمان انجام می‌شود.<?php echo esc_html( $stale_note ); ?></p>
            <?php elseif ( $facility_chosen && $missing_now !== [] ) : ?>
                <p style="color:#b26b00;" data-cpms-stale-completion="<?php echo $stored_completed ? '1' : '0'; ?>">⚠️ پیش‌نیازهای الزامی جریان فعلی برآورده نشده است: <?php echo esc_html( implode( '، ', $missing_now ) ); ?>.<?php echo esc_html( $stale_note ); ?></p>
                <?php self::render_prerequisite_links( $clinic_name, $doctor_count ); ?>
            <?php else : ?>
                <p style="color:#b26b00;">⚠️ هنوز همهٔ پیش‌نیازهای الزامی (اطلاعات کلینیک + یک پزشک فعال) برآورده نشده است.</p>
                <?php self::render_prerequisite_links( $clinic_name, $doctor_count ); ?>
            <?php endif; ?>
        </div>
        <?php
        self::form_tag( 'finish', 'تکمیل راه‌اندازی' );
        self::render_restart_form();
    }

    private static function render_restart_form(): void {
        ?>
        <div class="card">
            <h2>بازنشانی پیشرفت راه‌اندازی</h2>
            <p>این کار فقط مرحلهٔ راه‌اندازی را از ابتدا آغاز می‌کند. هیچ دادهٔ کلینیک، کاربر، عضویت، پزشک، بیمار، نوبت یا سابقهٔ بالینی حذف یا تغییر نمی‌کند.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cpms_wizard_restart', '_wpnonce' ); ?>
                <input type="hidden" name="action" value="cpms_wizard_restart">
                <p class="submit">
                    <button type="submit" class="button">شروع دوباره — فقط بازنشانی پیشرفت</button>
                </p>
            </form>
        </div>
        <?php
    }


    // ================= Facility-type onboarding (presentation only) =================

    public static function selected_facility_type(): string {
        $uid = (int) get_current_user_id();
        if ( $uid > 0 ) {
            $v = (string) get_user_meta( $uid, 'cpms_onboarding_facility_type', true );
            if ( FacilityTypeCatalog::is_valid( $v ) ) {
                return $v;
            }
        }
        // Cross-admin resumability: the persisted Clinic-scoped selection is
        // the fallback for an authorized manager without user-meta, so the
        // facility radio, the flow summary and the hospital/medical-building
        // disclosures resume identically. Read-only; GET never writes, and a
        // pre-bootstrap install (no Clinic scope) simply has no selection.
        try {
            $stored = (string) ( App::settings()->get( 'setup.facility_type', '' ) ?? '' );
            if ( FacilityTypeCatalog::is_valid( $stored ) ) {
                return $stored;
            }
        } catch ( \Throwable ) {
            $stored = ''; // Without Clinic-scoped Settings nothing is selected.
        }

        return '';
    }

    private static function selected_structure(): string {
        $uid = (int) get_current_user_id();
        if ( $uid > 0 ) {
            $v = (string) get_user_meta( $uid, 'cpms_onboarding_structure', true );
            if ( isset( FacilityTypeCatalog::structures()[ $v ] ) ) {
                return $v;
            }
        }
        // Resumable/shared presentation state: the Clinic-scoped stored
        // structure is the fallback so every authorized manager of the same
        // Clinic sees the same adaptive flow. Read-only; GET never writes.
        try {
            $stored = (string) ( App::settings()->get( 'setup.facility_structure', '' ) ?? '' );
            if ( isset( FacilityTypeCatalog::structures()[ $stored ] ) ) {
                return $stored;
            }
        } catch ( \Throwable ) {
            $stored = ''; // Without Clinic-scoped Settings no structure is selected.
        }

        return '';
    }

    /**
     * Adaptive Persian presentation profile for the current selection;
     * generic legacy wording when nothing is selected. Presentation only.
     *
     * @return array{unit:string,clinician:string,clinicians:string,name_label:string}
     */
    private static function current_facility_profile(): array {
        return FacilityTypeCatalog::type_profile( self::selected_facility_type() );
    }

    /**
     * Whether any facility selection exists (per-admin meta or persisted
     * Clinic-scoped settings). Legacy installs without one keep their
     * previous wizard semantics untouched.
     */
    private static function facility_selected( Settings $settings ): bool {
        return self::selected_facility_type() !== ''
            || FacilityTypeCatalog::is_valid( (string) ( $settings->get( 'setup.facility_type', '' ) ?? '' ) );
    }

    /**
     * Truthful current-flow completion for display (banner, finish-step
     * indicator, finish card): stored flag AND — when a facility selection
     * exists — the current flow supports provisioning and every current
     * required step is verifiably complete. The stored flag itself is never
     * erased here; evolution keeps tenant and settings history intact.
     */
    private static function current_flow_is_complete( Settings $settings ): bool {
        if ( ! (bool) $settings->get( self::COMPLETE_KEY, false ) ) {
            return false;
        }
        if ( ! self::facility_selected( $settings ) ) {
            return true;
        }

        return FacilityTypeCatalog::flow_supports_provisioning( self::current_flow() )
            && self::missing_required_steps( $settings ) === [];
    }

    /**
     * Minimum real clinicians for the CURRENT flow: multi-provider flows
     * (multi-doctor clinic and the hospital outpatient network) need at
     * least 2; the dedicated doctor practice needs 1. No other clinical
     * requirement is invented.
     */
    private static function flow_min_clinicians(): int {
        return in_array( self::current_flow(), [ 'multi_doctor_clinic', 'hospital_network' ], true ) ? 2 : 1;
    }

    private static function current_flow(): string {
        return FacilityTypeCatalog::resolve_flow( self::selected_facility_type(), self::selected_structure() );
    }

    /**
     * Wizard steps actually shown for the selected facility flow.
     * Without any facility selection the full legacy registry is shown.
     *
     * @return list<array{id:string,title:string,icon:string,required:bool}>
     */
    public static function active_steps(): array {
        if ( self::selected_facility_type() === '' ) {
            return self::steps();
        }
        $facility = self::selected_facility_type();
        $flow     = self::current_flow();
        $ids      = FacilityTypeCatalog::flow_step_ids( $flow );
        $required = FacilityTypeCatalog::flow_required_step_ids( $flow );
        $by_id    = [];
        foreach ( self::steps() as $step ) {
            $by_id[ $step['id'] ] = $step;
        }
        $active = [];
        foreach ( $ids as $id ) {
            if ( isset( $by_id[ $id ] ) ) {
                $step             = $by_id[ $id ];
                $step['required'] = in_array( $id, $required, true );
                // Facility-aware nav/heading wording (single source of truth:
                // FacilityTypeCatalog::step_title()) — e.g. "اطلاعات بیمارستان
                // (فقط سرپایی)" for a hospital instead of the generic
                // "اطلاعات کلینیک", or "دندانپزشکان" instead of "پزشکان" for a
                // dental clinic. Other step ids keep their generic title.
                $step['title']    = FacilityTypeCatalog::step_title( $facility, $id, $step['title'] );
                $active[]         = $step;
            }
        }

        return $active !== [] ? $active : self::steps();
    }

    public static function intended_clinicians( Settings $settings ): int {
        return max( 0, (int) ( $settings->get( 'setup.intended_clinicians', 0 ) ?? 0 ) );
    }

    /**
     * @return list<string>
     */
    public static function completed_step_ids( Settings $settings ): array {
        $raw = (string) ( $settings->get( 'setup.completed_steps', '' ) ?? '' );
        $ids = array_filter( array_map( 'trim', explode( ',', $raw ) ) );

        return array_values( array_intersect( $ids, array_column( self::steps(), 'id' ) ) );
    }

    public static function mark_step_completed( Settings $settings, string $step_id, int $updated_by ): void {
        if ( ! in_array( $step_id, array_column( self::steps(), 'id' ), true ) ) {
            return;
        }
        $ids = self::completed_step_ids( $settings );
        if ( ! in_array( $step_id, $ids, true ) ) {
            $ids[] = $step_id;
        }
        $settings->set( 'setup.completed_steps', implode( ',', $ids ), $updated_by );
    }

    /**
     * Completion is verified against actually saved configuration
     * (canonical Clinic name, real Locations, real clinicians/schedules),
     * never against a numeric declaration alone.
     */
    public static function step_is_complete( string $step_id, Settings $settings ): bool {
        try {
            switch ( $step_id ) {
                case 'facility':
                    return self::selected_facility_type() !== '' || FacilityTypeCatalog::is_valid( (string) ( $settings->get( 'setup.facility_type', '' ) ?? '' ) );
                case 'clinic':
                    return self::canonical_clinic_name( $settings ) !== '';
                case 'locations':
                    $clinic_id = (int) App::scope()->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ScopeContext public property
                    foreach ( App::locationRepository()->listForClinic( $clinic_id ) as $loc ) {
                        if ( (int) ( $loc['is_active'] ?? 0 ) === 1 && trim( (string) ( $loc['timezone'] ?? '' ) ) !== '' ) {
                            return true;
                        }
                    }

                    return false;
                case 'doctors':
                    $clinic_id = (int) App::scope()->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ScopeContext public property
                    $actual    = count( App::clinicianRepository()->listAll( $clinic_id, false ) );
                    $intended  = self::intended_clinicians( $settings );
                    $flow_min  = self::flow_min_clinicians();
                    if ( $intended <= 0 ) {
                        $intended = $flow_min;
                    } elseif ( self::facility_selected( $settings ) ) {
                        // A stale intended count from a previous single-provider
                        // flow must not understate the current multi-provider
                        // flow's requirement (displayed min and validation agree).
                        $intended = max( $intended, $flow_min );
                    }

                    return $actual >= $intended;
                case 'schedules':
                    $clinic_id = (int) App::scope()->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ScopeContext public property
                    foreach ( App::clinicianRepository()->listAll( $clinic_id, false ) as $clinician ) {
                        if ( (int) ( $clinician['schedule_days'] ?? 0 ) > 0 ) {
                            return true;
                        }
                    }

                    return false;
                case 'health':
                    if ( self::current_flow() === 'hospital_network' ) {
                        return (bool) $settings->get( 'setup.outpatient_only_ack', false );
                    }

                    return in_array( $step_id, self::completed_step_ids( $settings ), true );
                case 'review':
                    return self::missing_required_steps( $settings ) === [];
                case 'finish':
                    return self::current_flow_is_complete( $settings );
                default:
                    return in_array( $step_id, self::completed_step_ids( $settings ), true );
            }
        } catch ( \Throwable ) {
            return false;
        }
    }

    /**
     * Required steps (excluding facility/review/finish) not yet verified complete.
     *
     * @return list<string> Persian step titles
     */
    public static function missing_required_steps( Settings $settings ): array {
        $required = FacilityTypeCatalog::flow_required_step_ids( self::current_flow() );
        $titles   = [];
        // Facility-aware titles (active_steps() already resolves them via
        // FacilityTypeCatalog::step_title()); self::steps() stays the
        // fallback source for any id active_steps() does not currently list.
        foreach ( self::active_steps() as $step ) {
            $titles[ $step['id'] ] = $step['title'];
        }
        foreach ( self::steps() as $step ) {
            if ( ! isset( $titles[ $step['id'] ] ) ) {
                $titles[ $step['id'] ] = $step['title'];
            }
        }
        $missing = [];
        foreach ( $required as $id ) {
            if ( $id === 'facility' || $id === 'review' ) {
                continue;
            }
            if ( ! self::step_is_complete( $id, $settings ) ) {
                $missing[] = $titles[ $id ] ?? $id;
            }
        }

        return $missing;
    }

    /**
     * Save the intended clinician count for the doctors step.
     * Multi-doctor flows require at least two; completion is later
     * verified against actually registered active clinicians.
     *
     * @param array<string, mixed> $post
     */
    public static function save_doctors( Settings $settings, array $post, int $updated_by ): string {
        // Defense in depth: the same Clinic-local CONFIG gate every other
        // wizard write enforces, even when this method is called directly.
        if ( ! self::has_clinic_local_config( $updated_by ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- pre-existing public wizard API parameter name
            return 'برای ذخیره در راه‌اندازی، عضویت فعال در همین مطب و مجوز پیکربندی لازم است.';
        }
        $flow_min   = self::flow_min_clinicians();
        $clinicians = self::current_facility_profile()['clinicians'];
        $raw        = $post['intended_clinicians'] ?? null;
        if ( $raw === null || ! is_scalar( $raw ) || trim( (string) $raw ) === '' ) {
            if ( $flow_min >= 2 ) {
                return 'تعداد ' . $clinicians . ' موردنظر را مشخص کنید.';
            }
            $settings->set( 'setup.intended_clinicians', 1, $updated_by );

            return '';
        }
        $intended = (int) $raw;
        if ( $intended < 1 || $intended > 200 ) {
            return 'تعداد ' . $clinicians . ' باید بین ۱ تا ۲۰۰ باشد.';
        }
        if ( $intended < $flow_min ) {
            return self::current_flow() === 'hospital_network'
                ? 'بیمارستان (فقط محدودهٔ سرپایی) یک جریان چندارائه‌دهنده است: تعداد ' . $clinicians . ' موردنظر باید دست‌کم ۲ باشد.'
                : 'برای درمانگاه چندپزشکه، تعداد ' . $clinicians . ' موردنظر باید دست‌کم ۲ باشد.';
        }
        $settings->set( 'setup.intended_clinicians', $intended, $updated_by );

        return '';
    }

    /** Pre-bootstrap first screen: facility question only; POST-only, nonce + manage_options, user-meta presentation state (no Clinic write). */
    public static function save_facility_selection(): void {
        if ( ! current_user_can( 'manage_options' )
            || ! isset( $_POST['_wpnonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cpms_wizard_facility' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        ) {
            wp_die( 'اعتبارسنجی ناموفق', 403 );
        }
        $facility = isset( $_POST['facility_type'] ) ? sanitize_key( wp_unslash( $_POST['facility_type'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! FacilityTypeCatalog::is_valid( $facility ) ) {
            wp_die( 'نوع مرکز نامعتبر است', 400 );
        }
        $structure = isset( $_POST['structure'] ) ? sanitize_key( wp_unslash( $_POST['structure'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( $structure !== '' && ! isset( FacilityTypeCatalog::structures()[ $structure ] ) ) {
            wp_die( 'ساختار نامعتبر است', 400 );
        }
        if ( ! FacilityTypeCatalog::structure_is_compatible( $facility, $structure ) ) {
            wp_die( esc_html( FacilityTypeCatalog::structure_incompatibility_message( $facility, $structure ) ), 400 );
        }
        $uid = (int) get_current_user_id();
        update_user_meta( $uid, 'cpms_onboarding_facility_type', $facility );
        update_user_meta( $uid, 'cpms_onboarding_structure', $structure );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
        exit;
    }

    private static function render_facility_first_screen(): void {
        ?>
        <div class="wrap" dir="rtl">
            <h1>راه‌اندازی</h1>
            <?php self::render_facility_picker_form( admin_url( 'admin-post.php' ), 'cpms_wizard_facility', 'cpms_wizard_facility', 'ادامه' ); ?>
        </div>
        <?php
    }

    private static function render_facility_picker_form( string $action_url, string $action, string $nonce_action, string $submit ): void {
        // Compatibility policy (single source of truth: FacilityTypeCatalog)
        // is enforced in the UI and again by server validation. Without JS
        // the incompatible options stay disabled for the current selection;
        // the server never trusts the submitted combination.
        $picker_type    = self::selected_facility_type();
        $structure_keys = array_keys( FacilityTypeCatalog::structures() );
        $policy         = [];
        foreach ( array_merge( [ '' ], array_column( FacilityTypeCatalog::all(), 'id' ) ) as $tid ) {
            $blocked = [];
            foreach ( $structure_keys as $sk ) {
                if ( ! FacilityTypeCatalog::structure_is_compatible( $tid, $sk ) ) {
                    $blocked[] = $sk;
                }
            }
            if ( $blocked !== [] ) {
                $policy[ $tid ] = $blocked;
            }
        }
        ?>
        <div class="card" style="max-width:900px;">
            <h2>چه نوع مرکز یا مجموعه درمانی می‌خواهید راه‌اندازی کنید؟</h2>
            <p class="description">نوع مرکز فقط شیوهٔ نمایش و پیکربندی راه‌اندازی را مشخص می‌کند و به‌تنهایی هیچ مجوزی ایجاد نمی‌کند. نام واقعی مرکز با مرز داخلی مجموعه یکسان نیست.</p>
            <form method="post" action="<?php echo esc_url( $action_url ); ?>">
                <?php wp_nonce_field( $nonce_action, '_wpnonce' ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
                <?php
                if ( $action === 'cpms_wizard_save' ) {
                    echo '<input type="hidden" name="step" value="facility">';
                }
                ?>
                <fieldset>
                    <legend class="screen-reader-text">چه نوع مرکز یا مجموعه درمانی می‌خواهید راه‌اندازی کنید؟</legend>
                    <?php foreach ( FacilityTypeCatalog::all() as $t ) : ?>
                        <label style="display:block;padding:10px;border:1px solid #dcdcde;margin:6px 0;min-height:44px;box-sizing:border-box;">
                            <input type="radio" name="facility_type" value="<?php echo esc_attr( $t['id'] ); ?>" required <?php checked( self::selected_facility_type(), $t['id'] ); ?>>
                            <?php echo esc_html( $t['label'] ); ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <p><label for="cpms_structure">ساختار مجموعه</label>
                <select id="cpms_structure" name="structure" style="max-width:100%;" data-cpms-structure-policy="<?php echo esc_attr( (string) wp_json_encode( $policy ) ); ?>">
                    <option value="">متناسب با نوع مرکز</option>
                    <?php foreach ( FacilityTypeCatalog::structures() as $k => $label ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>" <?php selected( self::selected_structure(), $k ); ?> <?php disabled( ! FacilityTypeCatalog::structure_is_compatible( $picker_type, $k ) ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select></p>
                <p class="description">ساختار باید با نوع مرکز سازگار باشد: بیمارستان فقط با ساختار «بیمارستان یا شبکهٔ درمانی» (یا «متناسب با نوع مرکز») راه‌اندازی می‌شود تا گام سلامت و تأیید «فقط سرپایی» هرگز حذف نشود؛ ساختار «بیمارستان یا شبکهٔ درمانی» نیز فقط برای نوع بیمارستان قابل انتخاب است. اعتبارسنجی سمت سرور مستقل از این فرم انجام می‌شود.</p>
                <p class="submit"><button type="submit" class="button button-primary button-large"><?php echo esc_html( $submit ); ?></button></p>
            </form>
        </div>
        <script>
        (function () {
            var sel = document.getElementById('cpms_structure');
            if (!sel || !sel.getAttribute('data-cpms-structure-policy')) { return; }
            var policy = JSON.parse(sel.getAttribute('data-cpms-structure-policy'));
            function applyPolicy() {
                var checked = document.querySelector('input[name="facility_type"]:checked');
                var blocked = checked ? (policy[checked.value] || []) : (policy[''] || []);
                Array.prototype.forEach.call(sel.options, function (opt) {
                    if (opt.value === '') { opt.disabled = false; return; }
                    opt.disabled = blocked.indexOf(opt.value) !== -1;
                });
                var current = sel.options[sel.selectedIndex];
                if (current && current.disabled) { sel.value = ''; }
            }
            Array.prototype.forEach.call(document.querySelectorAll('input[name="facility_type"]'), function (radio) {
                radio.addEventListener('change', applyPolicy);
            });
            applyPolicy();
        }());
        </script>
        <?php
    }

    private static function render_flow_summary(): void {
        $facility = self::selected_facility_type();
        if ( $facility === '' ) {
            return;
        }
        $flow = self::current_flow();
        $type = FacilityTypeCatalog::find( $facility );
        ?>
        <div class="card" data-cpms-onboarding-flow="<?php echo esc_attr( $flow ); ?>" style="max-width:900px;">
            <h2><?php echo esc_html( $type['label'] ?? '' ); ?></h2>
            <ol>
                <?php
                foreach ( FacilityTypeCatalog::flow_steps( $flow ) as $st ) {
                    echo '<li>' . esc_html( $st ) . '</li>';
                }
                ?>
            </ol>
            <?php
            $guidance = FacilityTypeCatalog::type_profile( $facility )['guidance'];
            if ( $guidance !== '' ) :
                ?>
                <p class="description" data-cpms-facility-guidance="<?php echo esc_attr( $facility ); ?>"><?php echo esc_html( $guidance ); ?></p>
            <?php endif; ?>
            <?php if ( $flow === 'hospital_network' ) : ?>
                <div class="notice notice-warning inline" data-cpms-inpatient-unsupported="1"><p>بخش بستری و سامانهٔ جامع بیمارستانی پشتیبانی نمی‌شود؛ این راه‌اندازی فقط درمانگاه‌ها و فرایندهای سرپایی را پوشش می‌دهد.</p></div>
            <?php endif; ?>
            <?php if ( $flow === 'medical_building' ) : ?>
                <div class="notice notice-info inline" data-cpms-multi-clinic-blocked="1"><p>ایجاد درمانگاه مستقل فقط با عضویت صریح مدیر سازمان و در محدودهٔ همان سازمان مجاز است؛ این اختیار دسترسی بالینی یا مالی ایجاد نمی‌کند. ایجاد درمانگاه: <a href="<?php echo esc_url( admin_url( 'admin.php?page=cpms-organizations' ) ); ?>">مدیریت سازمان و درمانگاه‌ها</a>. گام‌های تطبیقی درمانگاه‌های جدید در این ویزارد هنوز پیاده‌سازی نشده‌اند.</p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_facility( Settings $settings ): void {
        // The stored Clinic-scoped choice is only read here; GET never writes.
        $stored = (string) ( $settings->get( 'setup.facility_type', '' ) ?? '' );
        if ( FacilityTypeCatalog::is_valid( $stored ) && self::selected_facility_type() === '' ) {
            echo '<p class="description" data-cpms-stored-facility="' . esc_attr( $stored ) . '"></p>';
        }
        self::render_facility_picker_form( admin_url( 'admin-post.php' ), 'cpms_wizard_save', 'cpms_wizard_save', 'ذخیره و ادامه' );
        // The picker posts hidden action cpms_wizard_save only when used inside the wizard step:
        ?>
        <p class="description">پس از انتخاب، گام‌های راه‌اندازی متناسب با نوع مرکز نمایش داده می‌شوند و پیشرفت ذخیره‌شده قابل ادامه است.</p>
        <?php
    }

    public static function save_facility( Settings $settings, array $post, int $updated_by ): string {
        // Defense in depth: the same Clinic-local CONFIG gate every other
        // wizard write enforces (the pre-bootstrap entry point is the
        // separate manage_options + nonce handler save_facility_selection).
        if ( ! self::has_clinic_local_config( $updated_by ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- pre-existing public wizard API parameter name
            return 'برای ذخیره در راه‌اندازی، عضویت فعال در همین مطب و مجوز پیکربندی لازم است.';
        }
        $facility = isset( $post['facility_type'] ) && is_string( $post['facility_type'] ) ? sanitize_key( $post['facility_type'] ) : '';
        if ( ! FacilityTypeCatalog::is_valid( $facility ) ) {
            return 'نوع مرکز نامعتبر است.';
        }
        $structure = isset( $post['structure'] ) && is_string( $post['structure'] ) ? sanitize_key( $post['structure'] ) : '';
        if ( $structure !== '' && ! isset( FacilityTypeCatalog::structures()[ $structure ] ) ) {
            return 'ساختار مجموعه نامعتبر است.';
        }
        // Compatibility policy (same source of truth as the picker UI):
        // a hospital can never be downgraded to a flow that would skip the
        // outpatient-only disclosure/health step/acknowledgment, and the
        // hospital-network structure is reserved for the hospital type.
        if ( ! FacilityTypeCatalog::structure_is_compatible( $facility, $structure ) ) {
            return FacilityTypeCatalog::structure_incompatibility_message( $facility, $structure );
        }
        $prev_type      = (string) ( $settings->get( 'setup.facility_type', '' ) ?? '' );
        $prev_structure = (string) ( $settings->get( 'setup.facility_structure', '' ) ?? '' );
        // Safe configuration evolution: the selection may be revisited at
        // any time (including after completion — the established acceptance
        // journey evolves a finished practice setup into a multi-doctor
        // clinic). Evolution writes ONLY Clinic-scoped presentation/config
        // keys and this admin's user-meta; tenant rows (Clinic/Location/
        // Membership/Clinician) are never touched, completion markers are
        // never silently deleted, and invalid input never writes anything.
        $settings->set( 'setup.facility_type', $facility, $updated_by );
        $settings->set( 'setup.facility_structure', $structure, $updated_by );
        if ( $prev_type !== $facility || $prev_structure !== $structure ) {
            // Stale-acknowledgment prevention: the outpatient-only
            // acknowledgment is bound to the concrete type/structure
            // selection; on any change it must be freshly re-affirmed.
            // Config-scope only — clinical, financial and tenant history
            // are preserved untouched.
            $settings->set( 'setup.outpatient_only_ack', false, $updated_by );
        }
        if ( $updated_by > 0 ) {
            update_user_meta( $updated_by, 'cpms_onboarding_facility_type', $facility );
            update_user_meta( $updated_by, 'cpms_onboarding_structure', $structure );
        }
        return '';
    }

    private static function render_locations(): void {
        $can = ClinicianAdminPage::can_current_user_access_page();
        ?>
        <div class="card">
            <h2>مکان‌ها</h2>
            <p>مکان اصلی را مشخص کنید و در صورت نیاز مکان‌های بیشتر اضافه کنید. برای هر مکان، منطقهٔ زمانی دقیق باید صریح انتخاب شود و مقدار پیش‌فرض خودکار وجود ندارد.</p>
            <?php if ( $can ) : ?>
                <p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=cpms-locations' ) ); ?>">رفتن به مدیریت مکان‌ها</a></p>
            <?php else : ?>
                <div class="notice notice-warning inline"><p>برای مدیریت مکان‌ها، عضویت فعال در همین مرکز و مجوز پیکربندی لازم است؛ فرم بدون مجوز نمایش داده نمی‌شود.</p></div>
            <?php endif; ?>
        </div>
        <?php
        self::form_tag( 'locations', 'ادامه' );
    }

    // ================= Save / actions =================

    public static function save(): void
    {
        if (!current_user_can(RolesAndCapabilities::CONFIG)
            || !isset($_POST['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'cpms_wizard_save')
        ) {
            wp_die('اعتبارسنجی ناموفق', 403);
        }

        if ( CpmsAdminMenu::is_first_practice_setup_pending() ) {
            // Stay inside the single visible wizard; its render embeds the
            // first-practice bootstrap panel for this state.
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
            return;
        }

        $settings = App::settings();
        $step = trim((string) sanitize_key(wp_unslash($_POST['step'] ?? 'welcome'))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $valid = array_column(self::steps(), 'id');
        if (!in_array($step, $valid, true)) {
            wp_die('گام نامعتبر', 400);
        }

        // Every wizard write (including mere progress) requires effective
        // Clinic-local CONFIG — a generic capability is never enough.
        if ( ! self::has_clinic_local_config( (int) get_current_user_id() ) ) {
            wp_die( 'برای ذخیره در راه‌اندازی، عضویت فعال در همین مطب و مجوز پیکربندی لازم است.', 403 );
        }

        $error = '';

        switch ($step) {
            case 'facility':
                $error = self::save_facility( $settings, (array) wp_unslash( $_POST ), (int) get_current_user_id() );
                break;
            case 'clinic':
                $error = self::saveClinic($settings, (array) wp_unslash($_POST), (int) get_current_user_id());
                break;
            case 'doctors':
                $error = self::save_doctors( $settings, (array) wp_unslash( $_POST ), (int) get_current_user_id() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- fields revalidated in save_doctors
                break;
            case 'booking':
                $error = self::saveBooking($settings, (array) wp_unslash($_POST), (int) get_current_user_id());
                break;
            case 'sms':
                $error = self::saveSms($settings, (array) wp_unslash($_POST), (int) get_current_user_id());
                break;
            case 'health':
                $error = self::save_health( $settings, (array) wp_unslash( $_POST ), (int) get_current_user_id() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checkbox revalidated in save_health
                break;
            case 'finish':
                $error = self::saveFinish($settings, (int) get_current_user_id());
                break;
            default:
                // گام‌های ارجاعی/اطلاعی: فقط پیشرفت ثبت می‌شود.
                break;
        }

        if ($error !== '') {
            set_transient(self::NOTICE_KEY, 'خطا: ' . $error, 60);
            wp_safe_redirect( self::step_url( $step ) );
            exit;
        }

        if ($step === 'finish') {
            set_transient(self::NOTICE_KEY, 'راه‌اندازی تکمیل شد.', 60);
            wp_safe_redirect(admin_url('admin.php?page=' . CpmsDashboard::PAGE_SLUG));
            exit;
        }

        // Verified progression: entity steps advance only when actually
        // complete; informational steps are recorded as completed.
        $entity_steps = ['clinic', 'locations', 'doctors', 'schedules', 'review'];
        if ( ! in_array( $step, $entity_steps, true ) && $step !== 'facility' ) {
            self::mark_step_completed( $settings, $step, (int) get_current_user_id() );
        }
        $active_required = array_column( array_filter( self::active_steps(), static fn ( $s ) => $s['required'] ), 'id' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
        if ( in_array( $step, $active_required, true ) && ! self::step_is_complete( $step, $settings ) ) {
            set_transient( self::NOTICE_KEY, 'خطا: این گام هنوز کامل نیست؛ ابتدا اطلاعات واقعی آن را در صفحهٔ مربوط ثبت و ذخیره کنید، سپس ادامه دهید.', 60 );
            wp_safe_redirect( self::step_url( $step ) );
            exit;
        }
        self::mark_step_completed( $settings, $step, (int) get_current_user_id() );
        self::setCurrentStep(self::nextStepId($step));
        set_transient(self::NOTICE_KEY, 'ذخیره شد.', 60);
        wp_safe_redirect(admin_url('admin.php?page=cpms-wizard'));
        exit;
    }

    /**
     * Progress-only reset (explicit restart): the wizard points to the start
     * again and flow-bound readiness state (completion flag and the
     * outpatient-only acknowledgment) is cleared so a stale acknowledgment
     * can never be reused after a restart. Clinical, financial and tenant
     * data and settings history are never touched — only wizard-scope keys
     * are written.
     */
    public static function reset_progress( Settings $settings, int $uid ): void {
        $settings->set( 'setup.current_step', 'welcome', $uid );
        $settings->set( self::COMPLETE_KEY, false, $uid );
        $settings->set( 'setup.outpatient_only_ack', false, $uid );
    }

    public static function restart(): void
    {
        if (!current_user_can(RolesAndCapabilities::CONFIG)
            || !isset($_POST['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'cpms_wizard_restart')
        ) {
            wp_die('اعتبارسنجی ناموفق', 403);
        }

        if ( ! self::has_clinic_local_config( (int) get_current_user_id() ) ) {
            wp_die( 'برای این کار، عضویت فعال در همین مطب و مجوز پیکربندی لازم است.', 403 );
        }

        $settings = App::settings();
        self::reset_progress( $settings, (int) get_current_user_id() );
        set_transient(self::NOTICE_KEY, 'راه‌اندازی از نو شروع شد.', 60);
        wp_safe_redirect(admin_url('admin.php?page=cpms-wizard'));
        exit;
    }

    // ================= Per-step persistence =================

    /**
     * اعتبارسنجی و ذخیرهٔ گام «اطلاعات کلینیک» — اکنون canonical path.
     *
     * این متد دیگر به setup.clinic.* نمی‌نویسد (second writable canonical حذف شد).
     * منبع canonical: cpms_clinics via ClinicProfileService.
     * Trusted durable Clinic context + CONFIG auth via service.
     * Timezone اینجا مدیریت نمی‌شود (Location timezone حقیقت عملیاتی است).
     *
     * @param array<string, mixed> $post
     *
     * @return string '' = ok، یا متن پیام خطا
     */
    public static function saveClinic(Settings $settings, array $post, int $updatedBy): string
    {
        // Input normalization (same as service validation but early check for UX)
        $name             = trim( (string) ( $post['clinic_name'] ?? '' ) );
        $address          = trim( (string) ( $post['clinic_address'] ?? '' ) );
        $address_province = trim( (string) ( $post['address_province'] ?? '' ) );
        $address_city     = trim( (string) ( $post['address_city'] ?? '' ) );
        $phone            = trim( (string) ( $post['clinic_phone'] ?? '' ) );

        if ( $address_province !== '' || $address_city !== '' ) {
            try {
                $address = (string) ( IranProvinceCityCatalog::resolve_submitted_address( $address, $address_province, $address_city ) ?? '' );
            } catch ( \InvalidArgumentException $e ) {
                return $e->getMessage();
            }
        }

        // Basic pre-validation to keep error messages consistent with old wizard.
        // Facility-aware unit (e.g. "نام بیمارستان الزامی است."); an unknown/
        // empty facility selection keeps the historical generic "کلینیک" wording.
        $unit = self::current_facility_profile()['unit'];
        if ($name === '') {
            return 'نام ' . $unit . ' الزامی است.';
        }
        if (mb_strlen($name) > 190) {
            return 'نام ' . $unit . ' حداکثر ۱۹۰ کاراکتر است.';
        }
        if (mb_strlen($address) > 255) {
            return 'آدرس حداکثر ۲۵۵ کاراکتر است.';
        }
        if (mb_strlen($phone) > 32) {
            return 'تلفن حداکثر ۳۲ کاراکتر است.';
        }

        // Resolve trusted clinicId — no raw payload clinic_id, no fixed IDs, no first-clinic fallback
        try {
            $scope = ScopeContext::tryGet() ?? App::scope();
            $trustedClinicId = (int) $scope->clinicId;
        } catch (\Throwable $e) {
            // Fail-closed: scope required
            return 'زمینه کلینیک معتبر یافت نشد: ' . $e->getMessage();
        }

        if ($trustedClinicId <= 0) {
            return 'زمینه کلینیک نامعتبر است.';
        }

        // Delegate to canonical service (same path as other product paths)
        try {
            App::clinicProfileService()->updateProfile($updatedBy, $trustedClinicId, [
                'name' => $name,
                'address' => $address !== '' ? $address : null,
                'phone' => $phone !== '' ? $phone : null,
            ]);
        } catch (ClinicProfileException $e) {
            $code = $e->getErrorCode();
            $data = $e->getData();
            if ($code === ClinicProfileException::VALIDATION) {
                $fields = $data['fields'] ?? [];
                if (is_array($fields) && $fields !== []) {
                    $first = reset($fields);
                    return is_string($first) ? $first : 'اعتبارسنجی ناموفق';
                }
                return $e->getMessage();
            }
            if ($code === ClinicProfileException::PERMISSION_DENIED) {
                return 'دسترسی لازم را ندارید';
            }
            if ($code === ClinicProfileException::NOT_FOUND) {
                return 'کلینیک یافت نشد';
            }
            if ($code === ClinicProfileException::QUERY_FAILED) {
                return 'خطای پایگاه داده هنگام ذخیره';
            }
            return 'خطا: ' . $e->getMessage();
        } catch (\Throwable $e) {
            return 'خطای غیرمنتظره: ' . $e->getMessage();
        }

        // Intentionally NOT writing to setup.clinic.name/address/phone/timezone
        // to avoid second writable canonical. Historical rows remain but are not updated.

        return '';
    }

    /**
     * اعتبارسنجی (bounded) و ذخیرهٔ گام «تنظیم رزرو» — خالص و قابل تست.
     *
     * @param array<string, mixed> $post
     *
     * @return string '' = ok
     */
    /**
     * Effective Clinic-local CONFIG authorization for wizard writes.
     *
     * The generic WordPress capability alone is NOT Clinic authority: the
     * actor must hold an active membership in exactly one trusted Clinic
     * and pass AuthorizationService for CONFIG in that Clinic (the same
     * engine every other Clinic write path uses). No clinic_id is ever
     * taken from the request.
     */
    private static function has_clinic_local_config( int $actor_user_id ): bool {
        if ( $actor_user_id <= 0 || ! current_user_can( RolesAndCapabilities::CONFIG ) ) {
            return false;
        }

        try {
            $establisher = new TrustedClinicEstablisher( App::db(), new MembershipRepository( App::db() ) );
            $scope       = $establisher->establish( $actor_user_id, null );
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ScopeContext exposes the public domain property as clinicId.
            App::authorization_service()->authorize( $actor_user_id, (int) $scope->clinicId, RolesAndCapabilities::CONFIG );

            return true;
        } catch ( \Throwable ) {
            return false;
        }
    }

    /**
     * Whether the embedded first-practice panel (fresh bootstrap,
     * interrupted-setup recovery, or blocked interrupted setup) applies to
     * the current installation state. Read-only.
     */
    /**
     * Public read-only predicate reused by CpmsActivationRedirect so the
     * one-shot post-activation redirect and the embedded wizard panel share
     * exactly one definition of "this installation genuinely still needs
     * first-run attention" (fresh bootstrap, strictly-eligible interrupted
     * setup recovery, or a blocked interrupted setup). An already fully
     * configured/multi-Clinic installation returns false here, so neither
     * surface forces an unexpected redirect/panel on it.
     */
    public static function onboarding_panel_applies(): bool {
        try {
            $setup_service = App::first_practice_setup_service();

            $actor_id = (int) get_current_user_id();

            return $setup_service->first_setup_candidate() !== null
                || $setup_service->recovery_candidate( $actor_id ) !== null
                || $setup_service->interrupted_setup_blocked( $actor_id ) !== null;
        } catch ( \Throwable ) {
            return false;
        }
    }

    public static function saveBooking(Settings $settings, array $post, int $updatedBy): string
    {
        if ( ! self::has_clinic_local_config( $updatedBy ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- pre-existing public wizard API parameter name
            return 'برای ذخیرهٔ تنظیمات رزرو، عضویت فعال در همین مطب و مجوز پیکربندی لازم است.';
        }

        foreach ( [ 'duration', 'capacity', 'lead', 'future', 'cancel' ] as $required_key ) {
            if ( ! array_key_exists( $required_key, $post )
                || ! is_scalar( $post[ $required_key ] )
                || trim( (string) $post[ $required_key ] ) === ''
            ) {
                return 'تمام گزینه‌های تنظیم رزرو الزامی هستند.';
            }
        }

        $duration = max(5, min(240, (int) ($post['duration'] ?? 20)));
        $capacity = max(1, min(20, (int) ($post['capacity'] ?? 1)));
        $lead = max(1, min(72, (int) ($post['lead'] ?? 2)));
        $future = max(1, min(365, (int) ($post['future'] ?? 60)));
        $cancel = max(0, min(168, (int) ($post['cancel'] ?? 24)));

        $settings->set('booking.duration_default_min', $duration, $updatedBy);
        $settings->set('booking.slot_capacity_default', $capacity, $updatedBy);
        $settings->set('booking.min_lead_hours', $lead, $updatedBy);
        $settings->set('booking.max_future_days', $future, $updatedBy);
        $settings->set('booking.cancel_deadline_hours', $cancel, $updatedBy);

        return '';
    }

    /**
     * ذخیرهٔ گام اختیاری پیامک — خالص و قابل تست.
     *
     * @param array<string, mixed> $post
     *
     * @return string '' = ok
     */
    public static function saveSms(Settings $settings, array $post, int $updatedBy): string
    {
        if ( ! self::has_clinic_local_config( $updatedBy ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- pre-existing public wizard API parameter name
            return 'برای ذخیرهٔ تنظیمات پیامک، عضویت فعال در همین مطب و مجوز پیکربندی لازم است.';
        }

        $provider = trim((string) ($post['sms_provider'] ?? ''));
        $sender = trim((string) ($post['sms_sender'] ?? ''));

        if ($provider !== '') {
            $settings->set('sms.provider', $provider, $updatedBy);
        }
        if ($sender !== '') {
            $settings->set('sms.sender', $sender, $updatedBy);
        }

        return '';
    }

    /**
     * @return string '' = ok
     */
    private static function saveFinish(Settings $settings, int $updatedBy): string
    {
        if ( ! self::has_clinic_local_config( $updatedBy ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- pre-existing public wizard API parameter name
            return 'برای تکمیل راه‌اندازی، عضویت فعال در همین مطب و مجوز پیکربندی لازم است.';
        }

        $facility_selected = self::facility_selected( $settings );
        if ( $facility_selected ) {
            if ( ! FacilityTypeCatalog::flow_supports_provisioning( self::current_flow() ) ) {
                return 'این مرحلهٔ ویزارد هنوز درمانگاه‌های مستقل را به‌صورت تطبیقی راه‌اندازی نمی‌کند. مدیر سازمانِ دارای عضویت صریح می‌تواند درمانگاه را از صفحهٔ «سازمان‌ها و درمانگاه‌ها» ایجاد کند؛ برای ادامهٔ پیکربندی هر درمانگاه، انتخاب صریح محدوده لازم است.';
            }
            $missing = self::missing_required_steps( $settings );
            if ( $missing !== [] ) {
                return 'پیش‌نیازهای الزامی برآورده نشده است: ' . implode( '، ', $missing );
            }
        } elseif ( ! self::isReadyToOperate( $settings ) ) {
            return 'پیش‌نیازهای الزامی برآورده نشده است (اطلاعات کلینیک + یک پزشک فعال).';
        }

        $settings->set(self::COMPLETE_KEY, true, $updatedBy);

        return '';
    }

    /**
     * شرط «آمادهٔ شروع عملیات»: اطلاعات کلینیک + حداقل یک پزشک فعال؛
     * بدون نیاز به مجوز فعال یا بکاپ/پیامک (گام‌های اختیاری هستند).
     * اکنون نام کلینیک از canonical cpms_clinics خوانده می‌شود.
     */
    private static function isReadyToOperate(Settings $settings): bool
    {
        $clinic_name = self::canonical_clinic_name( $settings );
        $doctors = count(App::clinicianRepository()->listAll(App::scope()->clinicId, false));

        return $clinic_name !== '' && $doctors > 0;
    }
}
