<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\ClinicManagementPage;
use ClinicCore\Admin\ClinicReceptionRecoveryPage;
use ClinicCore\Admin\ClinicianAdminPage;
use ClinicCore\Admin\CpmsAdminMenu;
use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Admin\CustomRoleDefinitionsPage;
use ClinicCore\Admin\DoctorDashboardPage;
use ClinicCore\Admin\DoctorHandwritingPage;
use ClinicCore\Admin\FacilitySetupPage;
use ClinicCore\Admin\FinanceDelegationPage;
use ClinicCore\Admin\FirstPracticeSetupPage;
use ClinicCore\Admin\LocationAdminPage;
use ClinicCore\Admin\OrganizationManagerBootstrapPage;
use ClinicCore\Admin\OrganizationManagerMembershipPage;
use ClinicCore\Admin\OrganizationManagerPage;
use ClinicCore\Admin\PatientAdminPage;
use ClinicCore\Admin\PrescriptionPrintPage;
use ClinicCore\Admin\PublicShortcodeAdminPage;
use ClinicCore\Admin\RoleCapabilitiesPage;
use ClinicCore\Admin\SecretaryFinancePage;
use ClinicCore\Admin\SecretaryQueuePage;
use ClinicCore\Admin\SettingsAdmin;
use ClinicCore\Admin\SmsSettingsPage;
use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Admin\SystemPage;
use ClinicCore\Auth\RolesAndCapabilities;
use WP_UnitTestCase;

/**
 * B-1 — «یک منوی واحد» و رَجیستری صفحات CPMS.
 *
 * قراردادهای تثبیت‌شده:
 *  1. دقیقاً **یک** Top-Level برای CPMS در wp-admin وجود دارد («مدیریت مطب»).
 *  2. هر صفحهٔ ثبت‌شدهٔ CPMS (طبق `CpmsAdminMenu::PAGES`) زیرِ همان والد ثبت
 *     می‌شود؛ هیچ صفحه‌ای در «تنظیمات»/«ابزارها» ثبت نمی‌شود.
 *  3. capability والد (ظرف) هرگز جای capability صفحه را نمی‌گیرد.
 *  4. رَجیستری (برچسب/بخش/capability) با ثبتِ واقعیِ هر صفحه یکی است.
 *  5. زیرمنوی قابل‌مشاهده کوتاه و بخش‌محور است؛ صفحات جزئی ثبت‌شده‌اند اما
 *     از «ناوبری بخش‌ها» و URL مستقیم در دسترس‌اند.
 */
final class AdminUnifiedMenuTest extends WP_UnitTestCase
{
    /**
     * صفحهٔ پورتال مستقل بیمار در wp-admin — عمداً بیرونِ منوی مدیریتی.
     *
     * این تنها اسلاگِ cpms- است که Top-Level می‌ماند چون ورودیِ نقشِ بیمار در
     * wp-admin است (portal، نه صفحهٔ مدیریتی). هر اسلاگِ دیگر باید عضو رَجیستری
     * و زیرِ «مدیریت مطب» باشد.
     *
     * @var list<string>
     */
    private const NON_ADMIN_PORTAL_SLUGS = ['cpms-patient'];

    /** @var array<string, mixed> */
    private array $backup = [];

    private int $admin = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (get_role('no_caps_probe') === null) {
            add_role('no_caps_probe', 'no caps', []);
        }
        foreach (['menu', 'submenu', '_parent_pages', '_registered_pages', '_wp_submenu_nopriv'] as $key) {
            $this->backup[$key] = $GLOBALS[$key] ?? null;
        }
        $this->admin = (int) self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        unset($_GET['page']);
        foreach ($this->backup as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
                continue;
            }
            $GLOBALS[$key] = $value;
        }
        parent::tearDown();
    }

    /**
     * کاربری که **همهٔ** capabilityهای رَجیستری را دارد.
     *
     * WordPress برای هر زیرمنو capability همان آیتم را می‌سنجد و اگر کاربر آن را
     * نداشته باشد هیچ ردیفی ثبت نمی‌کند (`$_wp_submenu_nopriv`). پس برای سنجشِ
     * کاملِ ثبت‌ها، کاربرِ جامع لازم است — نه Administratorِ خالی (که عمداً
     * `cpms_patient_read` و مانند آن را ندارد).
     */
    private function signInWithAllCapabilities(): int
    {
        $capabilities = ['read' => true];
        foreach (CpmsAdminMenu::PAGES as $page) {
            $capabilities[(string) $page[2]] = true;
        }
        $role = 'cpms_all_pages_' . substr(md5((string) wp_rand()), 0, 8);
        add_role($role, 'CPMS all pages', $capabilities);
        $user = (int) self::factory()->user->create(['role' => $role]);
        wp_set_current_user($user);

        return $user;
    }

    private function registerEveryCpmsPage(): void
    {
        $this->signInWithAllCapabilities();

        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        $GLOBALS['_parent_pages'] = [];
        $GLOBALS['_registered_pages'] = [];
        CpmsAdminMenu::reset_hidden_rows();

        CpmsAdminMenu::menu();
        CpmsSetupWizard::menu();
        StaffManagementPage::menu();
        FirstPracticeSetupPage::menu();
        OrganizationManagerPage::menu();
        OrganizationManagerBootstrapPage::menu();
        OrganizationManagerMembershipPage::menu();
        FacilitySetupPage::menu();
        ClinicManagementPage::menu();
        LocationAdminPage::menu();
        SettingsAdmin::menu();
        SystemPage::menu();
        ClinicianAdminPage::menu();
        RoleCapabilitiesPage::menu();
        FinanceDelegationPage::menu();
        CustomRoleDefinitionsPage::menu();
        PrescriptionPrintPage::menu();
        SmsSettingsPage::menu();
        SecretaryQueuePage::menu();
        SecretaryFinancePage::menu();
        DoctorDashboardPage::menu();
        DoctorHandwritingPage::menu();
        PatientAdminPage::menu();
        ClinicReceptionRecoveryPage::menu();
        PublicShortcodeAdminPage::menu();
        CpmsAdminMenu::order_submenu();
        // پنهان‌سازیِ نمایشی در محیطِ واقعی روی `admin_head` انجام می‌شود؛ همان
        // گامِ هستهٔ محصول را عیناً صدا می‌زنیم تا ادعای «زیرمنوی کوتاه» با رفتارِ
        // واقعی سنجیده شود، نه با یک وضعیتِ ساختگیِ تست.
        CpmsAdminMenu::prune_hidden_submenu_rows();
    }

    /** @return list<string> */
    private function cpmsTopLevelSlugs(): array
    {
        $slugs = [];
        foreach ((array) $GLOBALS['menu'] as $item) {
            $slug = is_array($item) ? (string) ($item[2] ?? '') : '';
            if (str_starts_with($slug, 'cpms-')) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /** @return list<string> */
    private function submenuSlugs(string $parent): array
    {
        $slugs = [];
        foreach ((array) ($GLOBALS['submenu'][$parent] ?? []) as $row) {
            $slugs[] = (string) ($row[2] ?? '');
        }

        return $slugs;
    }

    // ------------------------------------------------------------------
    // 1) یک Top-Level
    // ------------------------------------------------------------------

    public function testReceptionRecoveryRegistersItsHooksDuringBootstrap(): void
    {
        self::assertNotFalse(has_action('admin_menu', [ClinicReceptionRecoveryPage::class, 'menu']));
        self::assertNotFalse(has_action('admin_post_cpms_reception_recovery_assign', [ClinicReceptionRecoveryPage::class, 'handleAssign']));
    }

    public function testExactlyOneCpmsTopLevelMenuExists(): void
    {
        $this->registerEveryCpmsPage();

        self::assertSame(
            [CpmsAdminMenu::parent_slug()],
            $this->cpmsTopLevelSlugs(),
            'تنها یک منوی Top-Level مجاز است: «مدیریت مطب»'
        );

        $container = null;
        foreach ((array) $GLOBALS['menu'] as $item) {
            if (is_array($item) && ($item[2] ?? '') === CpmsAdminMenu::parent_slug()) {
                $container = $item;
            }
        }
        self::assertNotNull($container);
        self::assertSame('مدیریت مطب', $container[0], 'عنوان منوی واحد باید «مدیریت مطب» باشد');
        self::assertSame(CpmsAdminMenu::CONTAINER_CAPABILITY, $container[1]);
    }

    public function testNoCpmsPageRegistersUnderToolsOrSettings(): void
    {
        $this->registerEveryCpmsPage();

        foreach (['tools.php', 'options-general.php'] as $parent) {
            foreach ($this->submenuSlugs($parent) as $slug) {
                self::assertStringStartsNotWith(
                    'cpms-',
                    $slug,
                    "هیچ صفحهٔ CPMS نباید در {$parent} ثبت شود"
                );
            }
        }

        // و ثبت‌کننده‌های قدیمی هم واقعاً حذف شده‌اند (نه فقط پنهان).
        $root = dirname(__DIR__, 2) . '/src/Admin';
        foreach (glob($root . '/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('add_management_page', $source, basename($file));
            self::assertStringNotContainsString('add_options_page', $source, basename($file));
        }
    }

    // ------------------------------------------------------------------
    // 2) همهٔ صفحات زیرِ همان والد
    // ------------------------------------------------------------------

    public function testEveryRegisteredCpmsPageSitsBeneathTheUnifiedMenu(): void
    {
        $this->registerEveryCpmsPage();
        $parent = CpmsAdminMenu::parent_slug();

        foreach (array_keys(CpmsAdminMenu::PAGES) as $slug) {
            self::assertSame(
                $parent,
                $GLOBALS['_parent_pages'][$slug] ?? null,
                "صفحهٔ {$slug} باید زیر «مدیریت مطب» ثبت شود"
            );
            self::assertArrayHasKey(
                get_plugin_page_hookname( $slug, $parent ),
                $GLOBALS['_registered_pages'],
                "صفحهٔ {$slug} باید واقعاً ثبت شده باشد (دسترسی از URL مستقیم حفظ می‌شود)"
            );
        }
    }

    public function testHiddenPagesAreRegisteredButNotCrowdingTheSubmenu(): void
    {
        $this->registerEveryCpmsPage();
        $visible = $this->submenuSlugs(CpmsAdminMenu::parent_slug());

        self::assertSame(
            CpmsAdminMenu::visible_slugs(),
            array_values(array_filter(
                $visible,
                static fn (string $slug): bool => isset(CpmsAdminMenu::PAGES[$slug])
            )),
            'ترتیب و مجموعهٔ زیرمنوی CPMS باید دقیقاً همان بخش‌های رَجیستری باشد'
        );

        foreach (['cpms-handwriting', 'cpms-prescription-print', 'cpms-first-practice-setup', 'cpms-finance-delegation', 'cpms-custom-roles', 'cpms-shortcodes', 'cpms-organization-manager-bootstrap', 'cpms-organization-managers', 'cpms-facility-setup', 'cpms-clinic-management'] as $hidden) {
            self::assertNotContains($hidden, $visible, "صفحهٔ جزئی {$hidden} نباید زیرمنوی تخت بسازد");
            self::assertArrayHasKey(
                get_plugin_page_hookname( $hidden, CpmsAdminMenu::parent_slug() ),
                $GLOBALS['_registered_pages'],
                "صفحهٔ {$hidden} باید ثبت‌شده و از URL مستقیم در دسترس بماند"
            );
        }

        self::assertLessThanOrEqual(
            16,
            count($visible),
            'زیرمنو باید بخش‌محور و کوتاه بماند (نه یک فهرست تخت بیست‌وچندآیتمی)'
        );
    }

    /**
     * رگرسیونِ واقعیِ کشف‌شده در Runtime: حذفِ زودهنگامِ ردیفِ زیرمنو، والدِ صفحه را
     * از `get_admin_page_parent()` پاک می‌کند و WordPress در `user_can_access_admin_page()`
     * دسترسیِ URL مستقیم را 403 می‌کند. پس ردیف باید تا `admin_head` باقی بماند.
     */
    public function testHiddenPagesKeepTheirRegisteredParentUntilTheAdminHeadStep(): void
    {
        $this->registerEveryCpmsPage();
        $parent = CpmsAdminMenu::parent_slug();

        // ۱) هوکِ پنهان‌سازی واقعاً به admin_head وصل است (نه یک تابعِ بی‌مصرف).
        self::assertNotFalse(
            has_action('admin_head', [CpmsAdminMenu::class, 'prune_hidden_submenu_rows']),
            'پنهان‌سازیِ نمایشی باید روی admin_head وصل باشد'
        );

        // ۲) در گامِ admin_head ردیف‌ها حذف شده‌اند، اما هر صفحهٔ پنهان همچنان
        //    با hooknameِ والدِ خودش ثبت‌شده است — یعنی «ثبت‌شده، نامرئی».
        foreach (['cpms-handwriting', 'cpms-first-practice-setup', 'cpms-finance-delegation'] as $slug) {
            self::assertNotContains($slug, $this->submenuSlugs($parent), "{$slug} باید از نمایش حذف شود");
            self::assertArrayHasKey(
                get_plugin_page_hookname($slug, $parent),
                $GLOBALS['_registered_pages'],
                "{$slug} باید با hooknameِ والدِ واحد ثبت‌شده بماند (وگرنه 403)"
            );
            self::assertSame($parent, $GLOBALS['_parent_pages'][$slug] ?? null, "والدِ {$slug}");
        }

        // ۳) پیش از admin_head، ردیف‌ها باید هنوز موجود باشند تا هسته والد را پیدا کند.
        CpmsAdminMenu::reset_hidden_rows();
        $GLOBALS['submenu'] = [];
        $GLOBALS['submenu'][$parent] = [];
        FinanceDelegationPage::menu();
        self::assertContains(
            FinanceDelegationPage::PAGE_SLUG,
            $this->submenuSlugs($parent),
            'پیش از admin_head ردیف باید در آرایهٔ زیرمنو بماند تا user_can_access_admin_page() والد را بیابد'
        );
    }

    // ------------------------------------------------------------------
    // 3) capability والد ≠ مجوز فرزند
    // ------------------------------------------------------------------

    public function testContainerCapabilityNeverSubstitutesForAChildPagesCapability(): void
    {
        // کاربری که فقط `read` دارد (بدونِ هیچ capability اختصاصیِ CPMS).
        $role = 'cpms_container_only_' . substr(md5((string) wp_rand()), 0, 6);
        add_role($role, 'container only', ['read' => true]);
        wp_set_current_user((int) self::factory()->user->create(['role' => $role]));

        self::assertTrue((bool) current_user_can(CpmsAdminMenu::CONTAINER_CAPABILITY));

        // دقیقاً همان صفحاتی باز می‌شوند که capability خودشان `read` است؛ هیچ صفحهٔ
        // دیگری با capability ظرف باز نمی‌شود.
        $read_gated = [];
        foreach (CpmsAdminMenu::PAGES as $slug => $page) {
            if ('read' === (string) $page[2]) {
                $read_gated[] = $slug;
                continue;
            }
            self::assertFalse(
                (bool) current_user_can((string) $page[2]),
                "صفحهٔ {$slug} با capability ظرف باز شد — والد نباید مجوز بدهد"
            );
        }

        self::assertContains('cpms-finance-delegation', $read_gated);
        self::assertContains('cpms-organizations', $read_gated);
        self::assertNotContains('cpms-patients', $read_gated);
        self::assertNotContains('cpms-finance', $read_gated);
        self::assertNotContains('cpms-system', $read_gated);
        self::assertNotContains('cpms-staff', $read_gated);

        // کنترلِ مثبت: همان ظرف برای این کاربر «دسترسی‌پذیر» است چون خودِ این
        // صفحات `read`-gated هستند — یعنی منو دیده می‌شود اما فقط تا همین حد.
        self::assertTrue(CpmsAdminMenu::has_any_accessible_page());
        $sections = CpmsAdminMenu::accessible_sections();
        $reachable = [];
        foreach ($sections as $pages) {
            foreach (array_keys($pages) as $slug) {
                $reachable[] = $slug;
            }
        }
        sort($reachable);
        sort($read_gated);
        self::assertSame($read_gated, $reachable, 'مجموعهٔ صفحاتِ قابلِ‌دسترس باید دقیقاً همان صفحاتِ read-gated باشد');
    }

    public function testRoleScopedVisibilityFollowsTheRegistryCapabilities(): void
    {
        foreach (
            [
                RolesAndCapabilities::ROLE_DOCTOR,
                RolesAndCapabilities::ROLE_SECRETARY,
                RolesAndCapabilities::ROLE_ACCOUNTANT,
                RolesAndCapabilities::ROLE_MANAGER,
            ] as $role_key
        ) {
            wp_set_current_user((int) self::factory()->user->create(['role' => $role_key]));

            $expected = [];
            foreach (CpmsAdminMenu::PAGES as $slug => $page) {
                if (current_user_can((string) $page[2])) {
                    $expected[] = $slug;
                }
            }
            sort($expected);

            $observed = [];
            foreach (CpmsAdminMenu::accessible_sections() as $pages) {
                foreach (array_keys($pages) as $slug) {
                    $observed[] = $slug;
                }
            }
            sort($observed);

            self::assertSame($expected, $observed, "ناوبریِ بخش‌ها برای نقش {$role_key} باید دقیقاً از capabilityها پیروی کند");
            self::assertSame([] !== $expected, CpmsAdminMenu::has_any_accessible_page(), "نقش {$role_key}");
        }

        // هر کاربرِ واردشده `read` دارد و چند صفحه از رَجیستری عمداً `read`-gated
        // هستند (مدیرِ Clinic-scoped بدونِ capability وردپرسیِ CONFIG). پس ظرفِ منو
        // برای این کاربران دیده می‌شود — اما هیچ capability اختصاصی‌ای باز نمی‌شود و
        // مجوزِ هر صفحه همان capability خودش است (Clinic-scoped هم سمتِ سرویس‌ها).
        wp_set_current_user((int) self::factory()->user->create(['role' => 'subscriber']));
        self::assertTrue(CpmsAdminMenu::has_any_accessible_page());
        $read_only_reachable = [];
        foreach (CpmsAdminMenu::accessible_sections() as $pages) {
            foreach (array_keys($pages) as $slug) {
                $read_only_reachable[] = $slug;
            }
        }
        sort($read_only_reachable);
        $read_gated_only = [];
        foreach (CpmsAdminMenu::PAGES as $slug => $page) {
            if ('read' === (string) $page[2]) {
                $read_gated_only[] = $slug;
            }
        }
        sort($read_gated_only);
        self::assertSame($read_gated_only, $read_only_reachable, 'کاربر با read تنها همان صفحات read-gated را می‌بیند');

        // کاربرِ بدونِ `read` (نقشِ بدونِ هیچ capability): ظرف باید حذف شود.
        wp_set_current_user((int) self::factory()->user->create(['role' => 'no_caps_probe']));
        self::assertFalse(current_user_can('read'));
        self::assertFalse(CpmsAdminMenu::has_any_accessible_page());
        $GLOBALS['menu'] = [];
        CpmsAdminMenu::menu();
        CpmsAdminMenu::hide_when_unreachable();
        self::assertSame([], $this->cpmsTopLevelSlugs(), 'کاربر بی‌دسترسی نباید منوی «مدیریت مطب» را ببیند');
    }

    /**
     * مسیرهای قدیمیِ Tools/Settings همچنان باید کار کنند و **کوئریِ باقی‌مانده** را
     * از دست ندهند: فرمِ «کدهای کوتاه» با GET ارسال می‌شود و `clinic_id` را در URL
     * می‌آورد. حذفِ آن، کاربر را به صفحهٔ خالی برمی‌گرداند (رگرسیونِ observable در
     * Real WP Acceptance: admin.cpms-shortcodes.generated_registered_tag).
     */
    public function testLegacyRedirectKeepsTheRemainingQueryArguments(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/CpmsAdminMenu.php');

        self::assertStringContainsString(
            "add_query_arg( \$args, admin_url( 'admin.php?page=' . \$page ) )",
            $source,
            'legacy redirect must forward the remaining query arguments'
        );
        self::assertStringContainsString(
            'unset( $forward[\'page\'] )',
            $source,
            'the page selector itself must not be duplicated'
        );
        foreach (['cpms-system', 'cpms-settings', 'cpms-shortcodes', 'cpms-clinicians', 'cpms-roles'] as $legacy) {
            self::assertArrayHasKey(
                $legacy,
                \ClinicCore\Admin\CpmsAdminMenu::PAGES,
                "{$legacy} must stay registered after the legacy re-home"
            );
        }
    }

    // ------------------------------------------------------------------
    // 4) رَجیستری ↔ ثبت واقعی (بدون drift)
    // ------------------------------------------------------------------

    public function testRegistryLabelsAndSectionsAreComplete(): void
    {
        foreach (CpmsAdminMenu::PAGES as $slug => $page) {
            self::assertMatchesRegularExpression('/^cpms-[a-z0-9-]+$/', (string) $slug);
            self::assertNotSame('', (string) $page[0], "برچسب {$slug} خالی است");
            self::assertArrayHasKey((string) $page[1], CpmsAdminMenu::SECTIONS, "بخش {$slug} ناشناخته است");
            self::assertNotSame('', (string) $page[2], "capability {$slug} خالی است");
        }
    }

    /**
     * capabilityِ هر صفحه در رَجیستری باید با capabilityِ ثبتِ واقعیِ همان صفحه
     * یکی باشد — از دلِ سورسِ add_submenu_page/add_menu_page استخراج می‌شود.
     */
    public function testRegistryCapabilitiesMatchTheRealRegistrations(): void
    {
        $observed = $this->capabilitiesFromSource();

        foreach (array_keys(CpmsAdminMenu::PAGES) as $slug) {
            self::assertArrayHasKey($slug, $observed, "صفحهٔ {$slug} هیچ ثبت واقعی نداشت (رَجیستری از ثبت جدا شده)");
            self::assertSame(
                (string) CpmsAdminMenu::PAGES[$slug][2],
                $observed[$slug],
                "capability رَجیستری برای {$slug} با ثبت واقعی یکی نیست"
            );
        }

        foreach (array_keys($observed) as $slug) {
            if (in_array($slug, self::NON_ADMIN_PORTAL_SLUGS, true)) {
                continue;
            }
            self::assertArrayHasKey($slug, CpmsAdminMenu::PAGES, "صفحهٔ ثبت‌شدهٔ {$slug} در رَجیستری نیست");
        }

        self::assertSame(
            [],
            array_values(array_diff(self::NON_ADMIN_PORTAL_SLUGS, ['cpms-patient'])),
            'فهرست استثناهای پورتال نباید گسترش یابد'
        );
        self::assertArrayNotHasKey('cpms-patient', CpmsAdminMenu::PAGES, 'پورتال بیمار صفحهٔ مدیریتی نیست');
    }

    /**
     * capability هر صفحه را از دلِ سورسِ ثبت واقعی استخراج می‌کند.
     *
     * چرا این‌جا سورس و نه `$GLOBALS['submenu']`: صفحاتِ «جزئی» عمداً از آرایهٔ
     * زیرمنو حذف می‌شوند (ثبت‌شده اما نامرئی)، پس آرایه نمی‌تواند مرجعِ parity
     * باشد. آرگومان‌ها با شمارشِ عمقِ پرانتز جدا می‌شوند تا comma داخلِ
     * `array( self::class, 'render' )` آرگومان‌ها را نشکند.
     *
     * @return array<string, string> slug => capability
     */
    private function capabilitiesFromSource(): array
    {
        $root = dirname(__DIR__, 2) . '/src/Admin';
        $observed = [];

        foreach (glob($root . '/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (!preg_match('/namespace\s+([^;]+);/', $source, $ns)) {
                continue;
            }
            if (!preg_match('/(?:final\s+)?class\s+(\w+)/', $source, $class_match)) {
                continue;
            }
            $class = trim($ns[1]) . '\\' . $class_match[1];
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);

            foreach (['add_menu_page' => 2, 'add_submenu_page' => 3] as $function => $cap_index) {
                foreach ($this->callArguments($source, $function) as $args) {
                    if (!isset($args[$cap_index + 1])) {
                        continue;
                    }
                    $capability = $this->resolveCapability($args[$cap_index]);
                    $slug = $this->resolveSlug($reflection, $args[$cap_index + 1]);
                    if ($capability === null || $slug === null || !str_starts_with($slug, 'cpms-')) {
                        continue;
                    }
                    $observed[$slug] = $capability;
                }
            }
        }

        return $observed;
    }

    /**
     * @return list<list<string>> آرگومان‌های هر فراخوانی
     */
    private function callArguments(string $source, string $function): array
    {
        $calls = [];
        $offset = 0;
        $needle = $function . '(';

        while (($position = strpos($source, $needle, $offset)) !== false) {
            // اطمینان از اینکه بخشی از نامِ تابعِ دیگری نیست.
            $before = $position > 0 ? $source[$position - 1] : ' ';
            if (ctype_alnum($before) || $before === '_') {
                $offset = $position + 1;
                continue;
            }

            $start = $position + strlen($needle);
            $depth = 1;
            $argument = '';
            $arguments = [];
            $length = strlen($source);

            for ($i = $start; $i < $length; $i++) {
                $char = $source[$i];
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($char === ',' && $depth === 1) {
                    $arguments[] = trim($argument);
                    $argument = '';
                    continue;
                }
                $argument .= $char;
            }
            $arguments[] = trim($argument);

            $calls[] = $arguments;
            $offset = $i + 1;
        }

        return $calls;
    }

    private function resolveCapability(string $argument): ?string
    {
        if (preg_match('/^RolesAndCapabilities::(\w+)$/', $argument, $match)) {
            return (string) constant(RolesAndCapabilities::class . '::' . $match[1]);
        }
        if (preg_match('/^[\'"]([a-z_]+)[\'"]$/', $argument, $match)) {
            return $match[1];
        }

        return null;
    }

    private function resolveSlug(\ReflectionClass $reflection, string $argument): ?string
    {
        if (preg_match('/^self::(PAGE|PAGE_SLUG)$/', $argument, $match)) {
            return $reflection->hasConstant($match[1]) ? (string) $reflection->getConstant($match[1]) : null;
        }
        if (preg_match('/^[\'"]([\w-]+)[\'"]$/', $argument, $match)) {
            return $match[1];
        }

        return null;
    }

    // ------------------------------------------------------------------
    // 5) ناوبری بخش‌ها (کشفِ صفحات جزئی)
    // ------------------------------------------------------------------

    public function testSectionNavigationLinksEveryAccessiblePageInRtlOnce(): void
    {
        $this->signInWithAllCapabilities();
        $_GET['page'] = 'cpms-dashboard';
        ob_start();
        CpmsAdminMenu::render_section_nav();
        $html = (string) ob_get_clean();
        unset($_GET['page']);

        self::assertStringContainsString('dir="rtl"', $html, 'ناوبری باید RTL باشد');
        self::assertStringContainsString('aria-label=', $html, 'ناوبری باید accessible باشد');
        self::assertStringContainsString('aria-current="page"', $html, 'صفحهٔ جاری باید علامت‌گذاری شود');

        foreach (array_keys(CpmsAdminMenu::PAGES) as $slug) {
            self::assertSame(
                1,
                substr_count($html, 'admin.php?page=' . $slug . '"'),
                "صفحهٔ {$slug} باید دقیقاً یک لینک در ناوبری بخش‌ها داشته باشد"
            );
        }
    }

    public function testSectionNavigationRespectsCapabilities(): void
    {
        $secretary = (int) self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_SECRETARY]);
        wp_set_current_user($secretary);

        $_GET['page'] = 'cpms-queue';
        ob_start();
        CpmsAdminMenu::render_section_nav();
        $html = (string) ob_get_clean();
        unset($_GET['page']);

        self::assertStringContainsString('admin.php?page=cpms-queue', $html, 'منشی صف را می‌بیند');
        self::assertStringContainsString('admin.php?page=cpms-patients', $html, 'منشی بیماران را می‌بیند');
        self::assertStringNotContainsString('admin.php?page=cpms-system', $html, 'منشی سیستم را نمی‌بیند');
        self::assertStringNotContainsString('admin.php?page=cpms-settings', $html, 'منشی تنظیمات فنی را نمی‌بیند');
    }
}
