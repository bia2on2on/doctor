<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Roles\CustomRoleManagementException;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;

/**
 * «نقش‌های سفارشی» — Clinic-local custom-role definition management
 * (Custom Role & Permission Management, Slice 2).
 *
 * امنیت (Product Decision 2026-10-10 + Slice 2):
 *  - ورود به صفحه (نمایش) با `cpms_config` وردپرس است — مثل سایر صفحات ستادی؛
 *    اما «مجوز Clinic» از این Capability نمی‌آید: هر read/write از
 *    CustomRoleManagementService عبور می‌کند که TrustedClinicEstablisher +
 *    AuthorizationService را روی همان Clinic اجرا می‌کند.
 *  - Administrator وردپرس بدون عضویت فعال در آن Clinic → هیچ دسترسی‌ای ندارد
 *    (هیچ fallback «همهٔ Clinic» یا «Clinic اول» وجود ندارد).
 *  - انتخاب Clinic فقط selector است؛ مقدار ارسالی هرگز مدرک اعتماد نیست.
 *  - چک‌باکس‌ها فقط Capabilityهای «قابل‌تفویض برای همین کاربر» را نشان می‌دهند؛
 *    Backend مستقل از UI همان مجموعه را دوباره اعتبارسنجی می‌کند (all-or-nothing).
 *  - Nonce (CSRF) روی هر action، Sanitize روی هر ورودی، خروجی Escape‌شده.
 *  - کلید فنی نقش immutable است: فرم rename فقط نام نمایشی را تغییر می‌دهد.
 *  - هیچ Assign/Archive/Delete/Status در این صفحه وجود ندارد و هیچ نقش
 *    WordPress-global ثبت نمی‌شود. نام‌های نمایشی Unicode-عمومی‌اند (بدون قاعدهٔ
 *    فارسی‌محور) و هر متن با توابع ترجمهٔ وردپرس رندر می‌شود.
 */
final class CustomRoleDefinitionsPage
{
    public const PAGE_SLUG = 'cpms-custom-roles';

    private const NONCE_CREATE = 'cpms_custom_role_create';
    private const NONCE_RENAME = 'cpms_custom_role_rename';
    private const NONCE_CAPS   = 'cpms_custom_role_caps';
    private const NOTICE_KEY   = 'cpms_custom_roles_notice';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_cpms_custom_role_create', [self::class, 'handleCreate']);
        add_action('admin_post_cpms_custom_role_rename', [self::class, 'handleRename']);
        add_action('admin_post_cpms_custom_role_caps', [self::class, 'handleReplaceCapabilities']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            CpmsAdminMenu::parentSlug(),
            __('نقش‌های سفارشی', 'cpms'),
            __('نقش‌های سفارشی', 'cpms'),
            RolesAndCapabilities::CONFIG,
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can(RolesAndCapabilities::CONFIG)) {
            wp_die(esc_html__('دسترسی ندارید', 'cpms'), 403);
        }

        $actor = (int) get_current_user_id();
        $notice = self::pullNotice();

        // Selector is display context only; every read/write re-establishes the
        // trusted Clinic inside the service.
        $clinicId = self::selectedClinicId();
        $choices = self::manageableClinics($actor);

        ?>
        <div class="wrap" dir="rtl">
            <h1><?php echo esc_html__('نقش‌های سفارشی', 'cpms'); ?></h1>

            <?php self::renderNotice($notice); ?>

            <div class="notice notice-info inline">
                <p>
                    <?php echo esc_html__('نقش سفارشی مخصوص همین Clinic است؛ کلید فنی نقش پس از ساخت هرگز تغییر نمی‌کند و فقط نام نمایشی قابل ویرایش است.', 'cpms'); ?>
                    <?php echo esc_html__('شما فقط می‌توانید مجوزهایی را بدهید که خودتان در همین Clinic دارید؛ مجوزهای حساس قابل واگذاری نیستند.', 'cpms'); ?>
                </p>
            </div>

            <?php if ($choices === []) : ?>
                <div class="notice notice-warning inline">
                    <p><?php echo esc_html__('برای مدیریت نقش سفارشی، ابتدا باید عضویت فعالِ همین Clinic با مجوز پیکربندی (cpms_config) داشته باشید. وضعیت Administrator وردپرس به‌تنهایی مجوز Clinic ایجاد نمی‌کند.', 'cpms'); ?></p>
                </div>
            <?php else : ?>

                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin:12px 0">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                    <label for="cpms-custom-role-clinic"><?php echo esc_html__('Clinic', 'cpms'); ?></label>
                    <select name="cpms_clinic_id" id="cpms-custom-role-clinic">
                        <?php foreach ($choices as $choice) : ?>
                            <option value="<?php echo esc_attr((string) $choice['clinic_id']); ?>" <?php selected($clinicId, $choice['clinic_id']); ?>>
                                <?php echo esc_html($choice['clinic_name'] . ' (#' . $choice['clinic_id'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button"><?php echo esc_html__('نمایش', 'cpms'); ?></button>
                </form>

                <?php
                if ($clinicId <= 0) {
                    echo '<p>' . esc_html__('Clinic معتبری انتخاب نشده است.', 'cpms') . '</p>';
                    return;
                }

                try {
                    $listing      = App::custom_role_management_service()->list_definitions($actor, $clinicId);
                    $delegable    = App::custom_role_management_service()->delegable_capabilities($actor, $clinicId);
                } catch (ScopeRequiredException|AuthorizationException|CustomRoleManagementException $e) {
                    echo '<div class="notice notice-error inline"><p>' . esc_html__('دسترسی مدیریت نقش سفارشی برای این Clinic مجاز نیست.', 'cpms') . '</p></div>';
                    return;
                }

                self::renderList($listing['definitions']);

                if ($delegable === []) {
                    ?>
                    <div class="notice notice-warning inline"><p>
                        <?php echo esc_html__('در حال حاضر هیچ مجوز قابل واگذاری ندارید؛ بنابراین ساخت یا ویرایش نقش سفارشی ممکن نیست.', 'cpms'); ?>
                    </p></div>
                    <?php
                } else {
                    self::renderCreateForm($clinicId, $delegable);
                    foreach ($listing['definitions'] as $definition) {
                        self::renderEditForms($clinicId, $definition, $delegable);
                    }
                }
                ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handleCreate(): void
    {
        self::assertEntry();
        check_admin_referer(self::NONCE_CREATE);

        $roleKey = isset($_POST['role_key']) && is_string($_POST['role_key'])
            ? sanitize_key(wp_unslash($_POST['role_key']))
            : '';
        $displayName = isset($_POST['display_name']) && is_string($_POST['display_name'])
            ? sanitize_text_field(wp_unslash($_POST['display_name']))
            : '';
        $clinicId = self::postedClinicId();
        $capabilities = self::postedCapabilities();

        try {
            $created = App::custom_role_management_service()->create(
                (int) get_current_user_id(),
                $clinicId,
                $roleKey,
                $displayName,
                $capabilities
            );
            self::setNotice('success', sprintf(
                /* translators: 1: immutable role key, 2: custom role display name */
                __('نقش سفارشی «%2$s» با کلید فنی %1$s ساخته شد.', 'cpms'),
                $created['role_key'],
                $created['display_name']
            ));
        } catch (CustomRoleManagementException $e) {
            self::setNotice('error', self::describeFailure($e));
        } catch (ScopeRequiredException|AuthorizationException $e) {
            self::setNotice('error', __('دسترسی مدیریت نقش سفارشی برای این Clinic مجاز نیست.', 'cpms'));
        }

        self::redirectBack($clinicId);
    }

    public static function handleRename(): void
    {
        self::assertEntry();
        check_admin_referer(self::NONCE_RENAME);

        $roleKey = isset($_POST['role_key']) && is_string($_POST['role_key'])
            ? sanitize_key(wp_unslash($_POST['role_key']))
            : '';
        $displayName = isset($_POST['display_name']) && is_string($_POST['display_name'])
            ? sanitize_text_field(wp_unslash($_POST['display_name']))
            : '';
        $clinicId = self::postedClinicId();

        try {
            $renamed = App::custom_role_management_service()->rename(
                (int) get_current_user_id(),
                $clinicId,
                $roleKey,
                $displayName
            );
            self::setNotice('success', sprintf(
                /* translators: 1: immutable role key, 2: new custom role display name */
                __('نام نمایشی نقش %1$s به «%2$s» تغییر کرد (کلید فنی بدون تغییر).', 'cpms'),
                $renamed['role_key'],
                $renamed['display_name']
            ));
        } catch (CustomRoleManagementException $e) {
            self::setNotice('error', self::describeFailure($e));
        } catch (ScopeRequiredException|AuthorizationException $e) {
            self::setNotice('error', __('دسترسی مدیریت نقش سفارشی برای این Clinic مجاز نیست.', 'cpms'));
        }

        self::redirectBack($clinicId);
    }

    public static function handleReplaceCapabilities(): void
    {
        self::assertEntry();
        check_admin_referer(self::NONCE_CAPS);

        $roleKey = isset($_POST['role_key']) && is_string($_POST['role_key'])
            ? sanitize_key(wp_unslash($_POST['role_key']))
            : '';
        $clinicId = self::postedClinicId();
        $capabilities = self::postedCapabilities();

        try {
            $updated = App::custom_role_management_service()->replace_capabilities(
                (int) get_current_user_id(),
                $clinicId,
                $roleKey,
                $capabilities
            );
            self::setNotice('success', sprintf(
                /* translators: 1: immutable role key, 2: revision number */
                __('مجوزهای نقش %1$s ذخیره شد (نسخه %2$d).', 'cpms'),
                $updated['role_key'],
                $updated['version']
            ));
        } catch (CustomRoleManagementException $e) {
            self::setNotice('error', self::describeFailure($e));
        } catch (ScopeRequiredException|AuthorizationException $e) {
            self::setNotice('error', __('دسترسی مدیریت نقش سفارشی برای این Clinic مجاز نیست.', 'cpms'));
        }

        self::redirectBack($clinicId);
    }

    // ================= view helpers =================

    /**
     * @param list<array{clinic_id:int, id:int, role_key:string, display_name:string|null, status:string, version:int, updated_at:string, capabilities:list<string>}> $definitions
     */
    private static function renderList(array $definitions): void
    {
        ?>
        <h2 class="title"><?php echo esc_html__('نقش‌های سفارشی این Clinic', 'cpms'); ?></h2>
        <?php if ($definitions === []) : ?>
            <p><?php echo esc_html__('هنوز نقش سفارشی‌ای برای این Clinic تعریف نشده است.', 'cpms'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__('نام نمایشی', 'cpms'); ?></th>
                    <th scope="col"><?php echo esc_html__('کلید فنی (تغییرناپذیر)', 'cpms'); ?></th>
                    <th scope="col"><?php echo esc_html__('وضعیت', 'cpms'); ?></th>
                    <th scope="col"><?php echo esc_html__('نسخه', 'cpms'); ?></th>
                    <th scope="col"><?php echo esc_html__('مجوزها', 'cpms'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($definitions as $definition) : ?>
                    <tr>
                        <td>
                            <?php
                            echo esc_html(
                                $definition['display_name'] !== null && $definition['display_name'] !== ''
                                    ? $definition['display_name']
                                    : $definition['role_key']
                            );
                            ?>
                        </td>
                        <td><code dir="ltr"><?php echo esc_html($definition['role_key']); ?></code></td>
                        <td><?php echo esc_html($definition['status']); ?></td>
                        <td><?php echo esc_html((string) $definition['version']); ?></td>
                        <td>
                            <?php
                            echo $definition['capabilities'] === []
                                ? esc_html__('بدون مجوز', 'cpms')
                                : esc_html(implode('، ', $definition['capabilities']));
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    /**
     * @param list<string> $delegable
     */
    private static function renderCreateForm(int $clinicId, array $delegable): void
    {
        ?>
        <h2 class="title"><?php echo esc_html__('تعریف نقش سفارشی جدید', 'cpms'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="cpms_custom_role_create">
            <input type="hidden" name="cpms_clinic_id" value="<?php echo esc_attr((string) $clinicId); ?>">
            <?php wp_nonce_field(self::NONCE_CREATE); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="cpms-custom-role-key"><?php echo esc_html__('کلید فنی نقش', 'cpms'); ?></label>
                    </th>
                    <td>
                        <input name="role_key" id="cpms-custom-role-key" type="text" dir="ltr" required
                               pattern="[a-z][a-z0-9_]{1,63}"
                               value="" class="regular-text">
                        <p class="description">
                            <?php echo esc_html__('حروف کوچک لاتین، رقم و زیرخط؛ با حرف شروع می‌شود (۲ تا ۶۴ کاراکتر). این کلید پس از ساخت تغییر نمی‌کند و با نقش‌های داخلی CPMS تداخل ندارد.', 'cpms'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cpms-custom-role-name"><?php echo esc_html__('نام نمایشی', 'cpms'); ?></label>
                    </th>
                    <td>
                        <input name="display_name" id="cpms-custom-role-name" type="text" required
                               maxlength="<?php echo esc_attr((string) CustomRolePolicy::DISPLAY_NAME_MAX_CODE_POINTS); ?>"
                               value="" class="regular-text">
                        <p class="description">
                            <?php echo esc_html__('هر زبانی مجاز است (فارسی، لاتین، عربی، CJK و …). حداکثر ۱۲۰ کاراکتر.', 'cpms'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php self::renderCapabilityCheckboxes('capabilities', [], $delegable); ?>

            <p><button type="submit" class="button button-primary"><?php echo esc_html__('ساخت نقش سفارشی', 'cpms'); ?></button></p>
        </form>
        <?php
    }

    /**
     * @param array{clinic_id:int, id:int, role_key:string, display_name:string|null, status:string, version:int, capabilities:list<string>} $definition
     * @param list<string> $delegable
     */
    private static function renderEditForms(int $clinicId, array $definition, array $delegable): void
    {
        $roleKey = $definition['role_key'];
        ?>
        <h2 class="title">
            <?php echo esc_html(
                $definition['display_name'] !== null && $definition['display_name'] !== ''
                    ? $definition['display_name']
                    : $roleKey
            ); ?>
            <code dir="ltr"><?php echo esc_html($roleKey); ?></code>
        </h2>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px">
            <input type="hidden" name="action" value="cpms_custom_role_rename">
            <input type="hidden" name="cpms_clinic_id" value="<?php echo esc_attr((string) $clinicId); ?>">
            <input type="hidden" name="role_key" value="<?php echo esc_attr($roleKey); ?>">
            <?php wp_nonce_field(self::NONCE_RENAME); ?>
            <label for="cpms-rename-<?php echo esc_attr($roleKey); ?>"><?php echo esc_html__('ویرایش نام نمایشی', 'cpms'); ?></label>
            <input name="display_name" id="cpms-rename-<?php echo esc_attr($roleKey); ?>" type="text" required
                   maxlength="<?php echo esc_attr((string) CustomRolePolicy::DISPLAY_NAME_MAX_CODE_POINTS); ?>"
                   value="<?php echo esc_attr((string) ($definition['display_name'] ?? '')); ?>" class="regular-text">
            <button type="submit" class="button"><?php echo esc_html__('ذخیره نام', 'cpms'); ?></button>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="cpms_custom_role_caps">
            <input type="hidden" name="cpms_clinic_id" value="<?php echo esc_attr((string) $clinicId); ?>">
            <input type="hidden" name="role_key" value="<?php echo esc_attr($roleKey); ?>">
            <?php wp_nonce_field(self::NONCE_CAPS); ?>

            <?php self::renderCapabilityCheckboxes('capabilities', $definition['capabilities'], $delegable); ?>

            <p>
                <button type="submit" class="button button-secondary"><?php echo esc_html__('ذخیره مجوزهای مجاز', 'cpms'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Renders ONLY the capabilities the current actor may delegate in this
     * Clinic. The backend re-validates the same set independently, so tampering
     * with the form cannot widen it.
     *
     * @param list<string> $selected
     * @param list<string> $delegable
     */
    private static function renderCapabilityCheckboxes(string $fieldName, array $selected, array $delegable): void
    {
        ?>
        <fieldset>
            <legend><?php echo esc_html__('مجوزهای قابل واگذاری (بر پایه اختیار مؤثر شما در این Clinic)', 'cpms'); ?></legend>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:4px 16px">
                <?php foreach ($delegable as $capability) : ?>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($fieldName); ?>[]"
                               value="<?php echo esc_attr($capability); ?>"
                            <?php checked(in_array($capability, $selected, true)); ?>>
                        <code dir="ltr"><?php echo esc_html($capability); ?></code>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Non-verbose failure description. Domain reason codes are
     * mapped to translated messages; raw request values are never echoed back.
     */
    private static function describeFailure(CustomRoleManagementException $e): string
    {
        switch ($e->reason_code()) {
            case CustomRoleManagementException::CODE_INVALID_ROLE_KEY:
                return __('کلید فنی نقش معتبر نیست (حروف کوچک لاتین، شروع با حرف، ۲ تا ۶۴ کاراکتر، بدون تداخل با نقش‌های داخلی).', 'cpms');
            case CustomRoleManagementException::CODE_INVALID_DISPLAY_NAME:
                return __('نام نمایشی معتبر نیست: نباید خالی باشد، باید حداکثر ۱۲۰ کاراکتر باشد و حداقل یک حرف یا رقم داشته باشد.', 'cpms');
            case CustomRoleManagementException::CODE_CAPABILITY_NOT_ALLOWED:
                return __('یکی از مجوزهای انتخابی در فهرست مجاز نیست (مجوز حساس، وردپرس، ووکامرس یا ناشناخته حذف شد و ذخیره انجام نشد).', 'cpms');
            case CustomRoleManagementException::CODE_CAPABILITY_NOT_DELEGABLE:
                return __('یکی از مجوزهای انتخابی خارج از اختیار مؤثر شما در این Clinic است؛ هیچ تغییری ذخیره نشد.', 'cpms');
            case CustomRoleManagementException::CODE_KEY_CONFLICT:
                return __('این کلید فنی در همین Clinic قبلاً تعریف شده است.', 'cpms');
            case CustomRoleManagementException::CODE_NOT_FOUND:
            default:
                return __('نقش سفارشی موردنظر در این Clinic یافت نشد.', 'cpms');
        }
    }

    // ================= request helpers =================

    private static function assertEntry(): void
    {
        if (!current_user_can(RolesAndCapabilities::CONFIG)) {
            wp_die(esc_html__('دسترسی ندارید', 'cpms'), 403);
        }
    }

    /**
     * @return list<array{clinic_id:int, clinic_name:string}>
     */
    private static function manageableClinics(int $actorUserId): array
    {
        if ($actorUserId <= 0) {
            return [];
        }

        $service = App::custom_role_management_service();
        $choices = [];
        foreach (App::membership_service()->active_memberships_for_user($actorUserId) as $membership) {
            $id = (int) ($membership['clinic_id'] ?? 0);
            if ($id <= 0 || !$service->can_manage($actorUserId, $id)) {
                continue;
            }
            $choices[] = [
                'clinic_id'   => $id,
                'clinic_name' => (string) ($membership['clinic_name'] ?? ('Clinic #' . $id)),
            ];
        }

        return $choices;
    }

    /**
     * Display-only selector value: restricted to Clinics the actor may actually
     * manage, so an arbitrary value cannot even be selected in the UI. The
     * service still re-validates it against persistence on every use.
     */
    private static function selectedClinicId(): int
    {
        $requested = self::requestedClinicId();
        $choices = self::manageableClinics((int) get_current_user_id());
        if ($choices === []) {
            return 0;
        }
        foreach ($choices as $choice) {
            if ($choice['clinic_id'] === $requested) {
                return $requested;
            }
        }

        return $choices[0]['clinic_id'];
    }

    private static function requestedClinicId(): int
    {
        // Read-only selector; nonce is not applicable to a GET filter.
        if (!isset($_GET['cpms_clinic_id']) || !is_scalar($_GET['cpms_clinic_id'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return 0;
        }

        return absint(wp_unslash((string) $_GET['cpms_clinic_id'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    private static function postedClinicId(): int
    {
        if (!isset($_POST['cpms_clinic_id']) || !is_scalar($_POST['cpms_clinic_id'])) {
            return 0;
        }

        return absint(wp_unslash((string) $_POST['cpms_clinic_id']));
    }

    /**
     * @return list<string>
     */
    private static function postedCapabilities(): array
    {
        if (!isset($_POST['capabilities']) || !is_array($_POST['capabilities'])) {
            return [];
        }

        $raw = wp_unslash($_POST['capabilities']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitize_key()d below and strictly allowlist-validated in the service.
        $capabilities = [];
        foreach ((array) $raw as $candidate) {
            if (is_string($candidate)) {
                $capabilities[] = sanitize_key($candidate);
            }
        }

        return array_values(array_unique($capabilities));
    }

    private static function redirectBack(int $clinicId): void
    {
        $args = ['page' => self::PAGE_SLUG];
        if ($clinicId > 0) {
            $args['cpms_clinic_id'] = $clinicId;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private static function setNotice(string $type, string $message): void
    {
        set_transient(self::NOTICE_KEY . '_' . (int) get_current_user_id(), ['type' => $type, 'message' => $message], 60);
    }

    /**
     * @return array{type:string, message:string}|null
     */
    private static function pullNotice(): ?array
    {
        $key = self::NOTICE_KEY . '_' . (int) get_current_user_id();
        $notice = get_transient($key);
        if ($notice === false) {
            return null;
        }
        delete_transient($key);
        if (!is_array($notice) || !isset($notice['message']) || !is_string($notice['message'])) {
            return null;
        }

        return [
            'type'    => (isset($notice['type']) && $notice['type'] === 'success') ? 'success' : 'error',
            'message' => $notice['message'],
        ];
    }

    /**
     * @param array{type:string, message:string}|null $notice
     */
    private static function renderNotice(?array $notice): void
    {
        if ($notice === null) {
            return;
        }
        $class = $notice['type'] === 'success' ? 'notice notice-success is-dismissible' : 'notice notice-error';
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($notice['message']) . '</p></div>';
    }
}
