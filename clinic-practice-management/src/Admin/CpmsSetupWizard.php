<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

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
 */
final class CpmsSetupWizard
{
    public const PAGE_SLUG = 'cpms-wizard';

    /** تعداد کل گام‌ها (برابر count(self::steps())) — برای نوار پیشرفت. */
    public const TOTAL_STEPS = 12;

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

            <div style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0;">
                <?php foreach ($steps as $i => $s) : ?>
                    <?php $current = $i === $index; $done = $i < $index; ?>
                    <span class="dashicons-before <?php echo esc_attr($current ? 'dashicons-star-filled' : ($done ? 'dashicons-yes' : 'dashicons-marker')); ?>"
                          style="<?php echo $current ? 'background:#2271b1;color:#fff;padding:2px 8px;border-radius:3px;' : ($done ? 'color:#00a32a;' : 'color:#787c82;'); ?>">
                        <?php echo esc_html((string) ($i + 1) . '. ' . $s['title']); ?>
                    </span>
                <?php endforeach; ?>
            </div>

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
                self::renderReview($settings);
                break;
            case 'finish':
                self::renderFinish($settings);
                break;
            default:
                self::renderWelcome();
        }
    }

    private static function formTag(string $stepId, string $submitLabel, string $extraNotice = ''): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('cpms_wizard_save', '_wpnonce'); ?>
            <input type="hidden" name="action" value="cpms_wizard_save">
            <input type="hidden" name="step" value="<?php echo esc_attr($stepId); ?>">
            <?php echo $extraNotice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML از کد داخل همین کلاس ساخته می‌شود ?>
            <p class="submit">
                <button type="submit" class="button button-primary button-large"><?php echo esc_html($submitLabel); ?></button>
                <?php if ($stepId !== 'finish') : ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-wizard&jump=review')); ?>">پرش به بازبینی</a>
                <?php endif; ?>
            </p>
        </form>
        <?php
    }

    private static function renderWelcome(): void
    {
        ?>
        <div class="card">
            <h2>چه کاری انجام می‌شود؟</h2>
            <p>این راهنما ۱۲ قدم را با شما برمی‌دارد تا مطب به‌صورت امن راه بیفتد:
                اطلاعات کلینیک، تنظیم رزرو، کاربران و نقش‌ها، پزشکان، برنامه کاری، پیامک (اختیاری)،
                پشتیبان‌گیری، مجوز، بررسی سلامت، و در نهایت «شروع عملیات».</p>
            <p>همهٔ مراحل ذخیره می‌شوند؛ اگر وسط کار خارج شوید، از همان‌جا ادامه می‌دهید.</p>
            <p><strong>توجه:</strong> این صفحه هیچ دادهٔ پزشکی/حساس بیمار را نمایش یا ذخیره نمی‌کند.</p>
        </div>
        <?php
        self::formTag('welcome', 'شروع');
    }

    private static function renderClinic(Settings $settings): void
    {
        $name = (string) ($settings->get('setup.clinic.name', '') ?? '');
        $address = (string) ($settings->get('setup.clinic.address', '') ?? '');
        $phone = (string) ($settings->get('setup.clinic.phone', '') ?? '');
        $timezone = (string) ($settings->get('setup.clinic.timezone', 'Asia/Tehran') ?? 'Asia/Tehran');
        ?>
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
                <tr>
                    <th><label for="ctz">منطقهٔ زمانی</label></th>
                    <td>
                        <select id="ctz" name="clinic_timezone">
                            <?php foreach (['Asia/Tehran', 'Asia/Kabul', 'Asia/Baghdad', 'UTC'] as $tz) : ?>
                                <option value="<?php echo esc_attr($tz); ?>" <?php selected($timezone, $tz); ?>><?php echo esc_html($tz); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <p class="description">این اطلاعات فقط برای شناسایی کلینیک است و هیچ دادهٔ پزشکی در آن نیست.</p>
        </div>
        <?php
        self::formTag('clinic', 'ذخیره و ادامه');
    }

    private static function renderBooking(Settings $settings): void
    {
        $duration = (int) $settings->get('booking.duration_default_min', 20);
        $capacity = (int) $settings->get('booking.slot_capacity_default', 1);
        $lead = (int) $settings->get('booking.min_lead_hours', 2);
        $future = (int) $settings->get('booking.max_future_days', 60);
        $cancel = (int) $settings->get('booking.cancel_deadline_hours', 24);
        ?>
        <div class="card">
            <h2>تنظیم پیش‌فرض رزرو</h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="b1">مدت هر نوبت (دقیقه)</label></th><td><input type="number" id="b1" name="duration" min="5" max="240" value="<?php echo esc_attr((string) $duration); ?>"></td></tr>
                <tr><th><label for="b2">ظرفیت هر زمان‌بازه</label></th><td><input type="number" id="b2" name="capacity" min="1" max="20" value="<?php echo esc_attr((string) $capacity); ?>"></td></tr>
                <tr><th><label for="b3">حداقل فاصلهٔ رزرو (ساعت)</label></th><td><input type="number" id="b3" name="lead" min="1" max="72" value="<?php echo esc_attr((string) $lead); ?>"></td></tr>
                <tr><th><label for="b4">سقف رزرو آینده (روز)</label></th><td><input type="number" id="b4" name="future" min="1" max="365" value="<?php echo esc_attr((string) $future); ?>"></td></tr>
                <tr><th><label for="b5">مهلت لغو (ساعت)</label></th><td><input type="number" id="b5" name="cancel" min="0" max="168" value="<?php echo esc_attr((string) $cancel); ?>"></td></tr>
            </table>
            <p class="description">پس از ذخیره، همین مقادیر به‌عنوان پیش‌فرض در رزرو نوبت استفاده می‌شوند.</p>
        </div>
        <?php
        self::formTag('booking', 'ذخیره و ادامه');
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
                <li><strong>نقش‌های پیش‌فرض:</strong> مدیر کلینیک (cpms_config)، منشی، پزشک، حسابدار، بیمار.</li>
                <li>مجوزهای پیش‌فرض انسانی و قابل‌فهم هستند؛ مجوزهای پیشرفته به‌صورت جمع‌شده و با توضیح فارسی است.</li>
                <li>هیچ تغییر مجوزی بدون ثبت در Audit انجام نمی‌شود و امکان ارتقاء غیرمجاز وجود ندارد.</li>
            </ul>
            <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-roles')); ?>">رفتن به «کاربران و دسترسی‌ها»</a></p>
        </div>
        <?php
        self::formTag('users', 'ادامه');
    }

    private static function renderDoctors(): void
    {
        $count = count(App::clinicianRepository()->listAll(false));
        ?>
        <div class="card">
            <h2>پزشکان</h2>
            <p>پزشکان را اضافه و به کاربر و برنامهٔ کاری متصل کنید. افزودن پزشک بدون حذف تاریخچه انجام می‌شود.</p>
            <p>تعداد پزشکان فعال: <strong><?php echo esc_html((string) $count); ?></strong></p>
            <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-clinicians')); ?>">رفتن به «پزشکان و برنامه کاری»</a></p>
        </div>
        <?php
        self::formTag('doctors', 'ادامه');
    }

    private static function renderSchedules(): void
    {
        ?>
        <div class="card">
            <h2>برنامه کاری هفتگی</h2>
            <p>برنامهٔ هفتگی هر پزشک (۷ روز) و استثناها (تعطیلی/مرخصی) از صفحهٔ «پزشکان و برنامه کاری» تنظیم می‌شود.</p>
            <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-clinicians')); ?>">رفتن به تنظیم برنامه</a></p>
        </div>
        <?php
        self::formTag('schedules', 'ادامه');
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
        $backupEnabled = (bool) App::settings()->get('backup.enabled', false);
        ?>
        <div class="card">
            <h2>پشتیبان‌گیری (اختیاری)</h2>
            <p>پشتیبان‌گیری خودکار از پایگاه‌داده (بدون دادهٔ پزشکی خام در شبکهٔ عمومی) از صفحهٔ «فنی و سلامت» فعال می‌شود.</p>
            <p>وضعیت فعلی: <strong><?php echo $backupEnabled ? 'فعال' : 'غیرفعال'; ?></strong></p>
            <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-settings')); ?>">رفتن به تنظیمات بکاپ</a></p>
        </div>
        <?php
        self::formTag('backup', 'ادامه');
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
            <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=cpms-system')); ?>">رفتن به سلامت/مجوز</a></p>
        </div>
        <?php
        self::formTag('license', 'ادامه');
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
        </div>
        <?php
        self::formTag('health', 'ادامه');
    }

    private static function renderReview(Settings $settings): void
    {
        $clinic = (string) ($settings->get('setup.clinic.name', '') ?? '');
        $doctorCount = count(App::clinicianRepository()->listAll(false));
        ?>
        <div class="card">
            <h2>بازبینی پیش از شروع</h2>
            <table class="form-table" role="presentation">
                <tr><th>نام کلینیک</th><td><?php echo esc_html($clinic !== '' ? $clinic : '—'); ?></td></tr>
                <tr><th>پزشکان فعال</th><td><?php echo esc_html((string) $doctorCount); ?></td></tr>
                <tr><th>دسترسی مدیریت</th><td>cpms_config (مدیر)</td></tr>
            </table>
            <p>برای تکمیل، باید حداقل یک پزشک فعال و اطلاعات کلینیک (۴) ثبت شده باشد.</p>
        </div>
        <?php
        self::formTag('review', 'ذخیره و ادامه');
    }

    private static function renderFinish(Settings $settings): void
    {
        $ready = self::isReadyToOperate($settings);
        ?>
        <div class="card">
            <h2>شروع عملیات کلینیک</h2>
            <?php if ($ready) : ?>
                <p>🎉 همهٔ پیش‌نیازهای الزامی برآورده شده‌اند. با فشردن دکمهٔ بالا، راه‌اندازی تکمیل و وضعیت «آمادهٔ شروع عملیات» ثبت می‌شود.</p>
            <?php else : ?>
                <p style="color:#b26b00;">⚠️ هنوز همهٔ پیش‌نیازهای الزامی (اطلاعات کلینیک + یک پزشک فعال) برآورده نشده است. لطفاً گام‌های «اطلاعات کلینیک» و «پزشکان» را کامل کنید.</p>
            <?php endif; ?>
        </div>
        <?php
        self::formTag('finish', 'تکمیل راه‌اندازی');
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
            wp_safe_redirect(admin_url('admin.php?page=cpms-wizard'));
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
     * اعتبارسنجی و ذخیرهٔ گام «اطلاعات کلینیک» — خالص و قابل تست.
     *
     * @param array<string, mixed> $post
     *
     * @return string '' = ok، یا متن پیام خطا
     */
    public static function saveClinic(Settings $settings, array $post, int $updatedBy): string
    {
        $name = trim((string) ($post['clinic_name'] ?? ''));
        $address = trim((string) ($post['clinic_address'] ?? ''));
        $phone = trim((string) ($post['clinic_phone'] ?? ''));
        $timezone = (string) ($post['clinic_timezone'] ?? 'Asia/Tehran');

        if ($name === '') {
            return 'نام کلینیک الزامی است.';
        }
        if (mb_strlen($name) > 190) {
            return 'نام کلینیک حداکثر ۱۹۰ کاراکتر است.';
        }
        if (!in_array($timezone, ['Asia/Tehran', 'Asia/Kabul', 'Asia/Baghdad', 'UTC'], true)) {
            $timezone = 'Asia/Tehran';
        }

        $settings->set('setup.clinic.name', $name, $updatedBy);
        $settings->set('setup.clinic.address', $address, $updatedBy);
        $settings->set('setup.clinic.phone', $phone, $updatedBy);
        $settings->set('setup.clinic.timezone', $timezone, $updatedBy);

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
     */
    private static function isReadyToOperate(Settings $settings): bool
    {
        $name = trim((string) ($settings->get('setup.clinic.name', '') ?? ''));
        $doctors = count(App::clinicianRepository()->listAll(false));

        return $name !== '' && $doctors > 0;
    }
}
