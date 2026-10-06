<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Clinic\ClinicProfileException;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
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
            ['id' => 'welcome', 'title' => 'خوش‌آمدید', 'icon' => 'dashicons-heart', 'required' => true],
            ['id' => 'clinic', 'title' => 'اطلاعات کلینیک', 'icon' => 'dashicons-building', 'required' => true],
            ['id' => 'booking', 'title' => 'تنظیم رزرو نوبت', 'icon' => 'dashicons-calendar-alt', 'required' => true],
            ['id' => 'users', 'title' => 'کاربران و نقش‌ها', 'icon' => 'dashicons-admin-users', 'required' => true],
            ['id' => 'doctors', 'title' => 'پزشکان', 'icon' => 'dashicons-stethoscope', 'required' => true],
            ['id' => 'schedules', 'title' => 'برنامه کاری', 'icon' => 'dashicons-backup', 'required' => true],
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

        $settings = App::settings();
        $steps = self::steps();
        $index = self::currentStepIndex();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selector; values are restricted to registered steps.
        if ( isset( $_GET['step'] ) && is_string( $_GET['step'] ) ) {
            $requested_step = sanitize_key( wp_unslash( $_GET['step'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, registry-whitelisted navigation value.
            if ( in_array( $requested_step, array_column( $steps, 'id' ), true ) ) {
                $index = self::stepIndexById( $requested_step );
            }
        }
        $step = $steps[$index];
        $isComplete = (bool) $settings->get(self::COMPLETE_KEY, false);

        $startedAt = (string) ($settings->get('setup.started_at', '') ?? '');
        if ($startedAt === '') {
            $settings->set('setup.started_at', gmdate('c'), (int) get_current_user_id());
        }
        ?>
        <div class="wrap" dir="rtl">
            <h1>راه‌اندازی کلینیک — قدم <?php echo esc_html((string) ($index + 1)); ?> از <?php echo esc_html((string) count($steps)); ?></h1>
            <p class="description">
                این راهنما به شما کمک می‌کند بدون نیاز به دانش فنی، مطب را راه بیندازید.
                پیشرفت شما ذخیره می‌شود و هر زمان می‌توانید ادامه دهید.
            </p>

            <?php if (is_string($notice) && $notice !== '') : ?>
                <div class="notice <?php echo str_starts_with($notice, 'خطا') ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <?php if ($isComplete) : ?>
                <div class="notice notice-success"><p>✅ راه‌اندازی تکمیل شده است — کلینیک برای شروع عملیات آماده است.</p></div>
            <?php endif; ?>

            <nav aria-label="گام‌های راه‌اندازی" style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0;">
                <?php foreach ($steps as $i => $s) : ?>
                    <?php
                    $current = $i === $index;
                    $done = $i < $index;
                    $icon_class = $current ? 'dashicons-star-filled' : ( $done ? 'dashicons-yes' : 'dashicons-marker' );
                    $icon_style = $current ? 'background:#2271b1;color:#fff;padding:2px 8px;border-radius:3px;' : ( $done ? 'color:#00a32a;' : 'color:#787c82;' );
                    ?>
                    <a
                        data-cpms-wizard-step="<?php echo esc_attr( $s['id'] ); ?>"
                        href="<?php echo esc_url( self::step_url( $s['id'] ) ); ?>"
                        aria-current="<?php echo $current ? 'step' : 'false'; ?>"
                        class="dashicons-before <?php echo esc_attr( $icon_class ); ?>"
                        style="display:inline-block;text-decoration:none;<?php echo esc_attr( $icon_style ); ?>">
                        <?php echo esc_html( (string) ( $i + 1 ) . '. ' . $s['title'] ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php self::renderStep($step, $settings); ?>
        </div>
        <?php
    }

    // ================= Getters / helpers =================

    private static function currentStepIndex(): int
    {
        $settings = App::settings();
        $store = (string) ($settings->get('setup.current_step', 'welcome') ?? 'welcome');
        foreach (self::steps() as $i => $s) {
            if ($s['id'] === $store) {
                return $i;
            }
        }

        return 0;
    }

    private static function stepIndexById(string $id): int
    {
        foreach (self::steps() as $i => $s) {
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
        $steps = self::steps();
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
        // Canonical source: cpms_clinics — no longer setup.clinic.* as second writable canonical.
        // Historical setup.clinic.* rows are not deleted, but we do not read them for display as primary;
        // we show canonical values. Fallback to old settings only if canonical unavailable (e.g. pre-migration).
        $canonical = null;
        try {
            $scope = ScopeContext::tryGet() ?? App::scope();
            $canonical = App::clinicRepository()->find((int) $scope->clinicId);
        } catch (\Throwable) {
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
        <div class="card">
            <h2>مشخصات کلینیک</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="cname">نام کلینیک</label></th>
                    <td><input type="text" id="cname" name="clinic_name" class="regular-text" required value="<?php echo esc_attr($name); ?>"></td>
                </tr>
                <tr>
                    <th><label for="caddr">آدرس</label></th>
                    <td><input type="text" id="caddr" name="clinic_address" class="regular-text" value="<?php echo esc_attr($address); ?>"></td>
                </tr>
                <tr>
                    <th><label for="cphone">تلفن</label></th>
                    <td><input type="text" id="cphone" name="clinic_phone" class="regular-text" value="<?php echo esc_attr($phone); ?>"></td>
                </tr>
            </table>
            <p class="description">این اطلاعات فقط برای شناسایی کلینیک است و هیچ دادهٔ پزشکی در آن نیست. منبع canonical: cpms_clinics — timezone عملیاتی از Location است و اینجا ویرایش نمی‌شود.</p>
            <?php self::submit_step_form( 'clinic', 'ذخیره و ادامه' ); ?>
        </div>
        <?php self::close_step_form();
    }

    private static function renderBooking(Settings $settings): void
    {
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
        <?php self::close_step_form();
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
        $count = count(App::clinicianRepository()->listAll(App::scope()->clinicId, false));
        $can_manage_clinicians = ClinicianAdminPage::can_current_user_access_page();
        ?>
        <div class="card">
            <h2>پزشکان</h2>
            <p>پزشکان را اضافه و به کاربر و برنامهٔ کاری متصل کنید. افزودن پزشک بدون حذف تاریخچه انجام می‌شود.</p>
            <p>تعداد پزشکان فعال: <strong><?php echo esc_html((string) $count); ?></strong></p>
            <?php if ( $can_manage_clinicians ) : ?>
                <p><a class="button" data-cpms-wizard-action="clinicians" href="<?php echo esc_url( admin_url( 'admin.php?page=cpms-clinicians' ) ); ?>">رفتن به «پزشکان و برنامه کاری»</a></p>
            <?php endif; ?>
        </div>
        <?php
        self::form_tag( 'doctors', 'ادامه' );
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
        self::form_tag( 'health', 'ادامه' );
    }

    private static function canonical_clinic_name( Settings $settings ): string {
        try {
            $scope = ScopeContext::tryGet() ?? App::scope();
            $row = App::clinicRepository()->find( (int) $scope->clinicId );
            if ( is_array( $row ) ) {
                // An empty canonical value is a real blocker; never mask it with stale settings.
                return trim( (string) ( $row['name'] ?? '' ) );
            }
        } catch (\Throwable) {
            // Fallback is limited to legacy installs where the canonical row is unavailable.
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
        ?>
        <ul class="cpms-wizard-prerequisite-actions">
            <?php if ( $clinic_missing ) : ?>
                <li>
                    اطلاعات کلینیک ثبت نشده است.
                    <?php if ( $can_manage_scoped_clinic ) : ?>
                        <a data-cpms-wizard-action="clinic" href="<?php echo esc_url( self::step_url( 'clinic' ) ); ?>">اصلاح مشخصات کلینیک</a>
                    <?php else : ?>
                        <span>برای اصلاح، عضویت فعال در کلینیک و مجوز پیکربندی لازم است.</span>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
            <?php if ( $clinicians_missing ) : ?>
                <li>
                    پزشک فعالی ثبت نشده است.
                    <?php if ( $can_manage_scoped_clinic ) : ?>
                        <a data-cpms-wizard-action="clinicians" href="<?php echo esc_url( admin_url( 'admin.php?page=cpms-clinicians' ) ); ?>">افزودن/مدیریت پزشک</a>
                    <?php else : ?>
                        <span>برای مدیریت پزشکان، عضویت فعال در کلینیک و مجوز پیکربندی لازم است.</span>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
        </ul>
        <?php
    }

    private static function render_review( Settings $settings ): void {
        $clinic_name  = self::canonical_clinic_name( $settings );
        $doctor_count = count( App::clinicianRepository()->listAll( App::scope()->clinicId, false ) );
        ?>
        <div class="card">
            <h2>بازبینی پیش از شروع</h2>
            <table class="form-table" role="presentation">
                <tr><th>نام کلینیک</th><td><?php echo esc_html( $clinic_name !== '' ? $clinic_name : '—' ); ?></td></tr>
                <tr><th>پزشکان فعال</th><td><?php echo esc_html( (string) $doctor_count ); ?></td></tr>
                <tr><th>دسترسی مدیریت</th><td>cpms_config (مدیر)</td></tr>
            </table>
            <p>برای تکمیل، اطلاعات کلینیک و دست‌کم یک پزشک فعال لازم است.</p>
            <?php self::render_prerequisite_links( $clinic_name, $doctor_count ); ?>
        </div>
        <?php
        self::form_tag( 'review', 'ذخیره و ادامه' );
    }

    private static function renderFinish(Settings $settings): void
    {
        $clinic_name  = self::canonical_clinic_name( $settings );
        $doctor_count = count( App::clinicianRepository()->listAll( App::scope()->clinicId, false ) );
        $ready        = $clinic_name !== '' && $doctor_count > 0;
        ?>
        <div class="card">
            <h2>شروع عملیات کلینیک</h2>
            <?php if ( $ready ) : ?>
                <p>🎉 همهٔ پیش‌نیازهای الزامی برآورده شده‌اند. با فشردن دکمهٔ بالا، راه‌اندازی تکمیل و وضعیت «آمادهٔ شروع عملیات» ثبت می‌شود.</p>
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

    // ================= Save / actions =================

    public static function save(): void
    {
        if (!current_user_can(RolesAndCapabilities::CONFIG)
            || !isset($_POST['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'cpms_wizard_save')
        ) {
            wp_die('اعتبارسنجی ناموفق', 403);
        }

        $settings = App::settings();
        $step = trim((string) sanitize_key(wp_unslash($_POST['step'] ?? 'welcome'))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $valid = array_column(self::steps(), 'id');
        if (!in_array($step, $valid, true)) {
            wp_die('گام نامعتبر', 400);
        }

        $error = '';

        switch ($step) {
            case 'clinic':
                $error = self::saveClinic($settings, (array) wp_unslash($_POST), (int) get_current_user_id());
                break;
            case 'booking':
                $error = self::saveBooking($settings, (array) wp_unslash($_POST), (int) get_current_user_id());
                break;
            case 'sms':
                $error = self::saveSms($settings, (array) wp_unslash($_POST), (int) get_current_user_id());
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

        self::setCurrentStep(self::nextStepId($step));
        set_transient(self::NOTICE_KEY, 'ذخیره شد.', 60);
        wp_safe_redirect(admin_url('admin.php?page=cpms-wizard'));
        exit;
    }

    public static function restart(): void
    {
        if (!current_user_can(RolesAndCapabilities::CONFIG)
            || !isset($_POST['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'cpms_wizard_restart')
        ) {
            wp_die('اعتبارسنجی ناموفق', 403);
        }

        $settings = App::settings();
        $settings->set('setup.current_step', 'welcome', (int) get_current_user_id());
        $settings->set(self::COMPLETE_KEY, false, (int) get_current_user_id());
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
        $name = trim((string) ($post['clinic_name'] ?? ''));
        $address = trim((string) ($post['clinic_address'] ?? ''));
        $phone = trim((string) ($post['clinic_phone'] ?? ''));

        // Basic pre-validation to keep error messages consistent with old wizard
        if ($name === '') {
            return 'نام کلینیک الزامی است.';
        }
        if (mb_strlen($name) > 190) {
            return 'نام کلینیک حداکثر ۱۹۰ کاراکتر است.';
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
    public static function saveBooking(Settings $settings, array $post, int $updatedBy): string
    {
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
        if (!self::isReadyToOperate($settings)) {
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
