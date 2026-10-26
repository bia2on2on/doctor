<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\ClinicManagementPage;
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

    /** ثبت همهٔ صفحات CPMS — همان مجموعه‌ای که App::boot() ثبت می‌کند. */
    private function registerEveryCpmsPage(): void
    {
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        $GLOBALS['_parent_pages'] = [];
        $GLOBALS['_registered_pages'] = [];

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
        PublicShortcodeAdminPage::menu();
        CpmsAdminMenu::order_submenu();
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
                'cpms-dashboard_page_' . $slug,
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
                'cpms-dashboard_page_' . $hidden,
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

    // ------------------------------------------------------------------
    // 3) capability والد ≠ مجوز فرزند
    // ------------------------------------------------------------------

    public function testContainerCapabilityNeverSubstitutesForAChildPagesCapability(): void
    {
        $role = 'cpms_container_only_' . substr(md5((string) wp_rand()), 0, 6);
        add_role($role, 'container only', ['read' => true]);
        $user = (int) self::factory()->user->create(['role' => $role]);
        wp_set_current_user($user);

        self::assertTrue((bool) current_user_can(CpmsAdminMenu::CONTAINER_CAPABILITY));
        self::assertFalse(
            CpmsAdminMenu::has_any_accessible_page(),
            'داشتنِ capability ظرف به‌تنهایی نباید هیچ صفحهٔ CPMS را در دسترس کند'
        );

        foreach (['cpms-patients', 'cpms-finance', 'cpms-system', 'cpms-staff', 'cpms-sms', 'cpms-queue', 'cpms-doctor', 'cpms-roles'] as $slug) {
            $capability = (string) CpmsAdminMenu::PAGES[$slug][2];
            self::assertNotSame(CpmsAdminMenu::CONTAINER_CAPABILITY, $capability, "capability صفحهٔ {$slug} نباید همان capability ظرف باشد");
            self::assertFalse((bool) current_user_can($capability), "{$slug} نباید با capability ظرف باز شود");
        }
    }

    public function testRoleScopedVisibilityIsUnchangedByTheUnifiedMenu(): void
    {
        $doctor = (int) self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_DOCTOR]);
        wp_set_current_user($doctor);
        self::assertTrue(CpmsAdminMenu::has_any_accessible_page());
        self::assertTrue((bool) current_user_can((string) CpmsAdminMenu::PAGES['cpms-patients'][2]), 'پزشک patients را می‌بیند');
        self::assertFalse((bool) current_user_can((string) CpmsAdminMenu::PAGES['cpms-system'][2]), 'پزشک سیستم را نمی‌بیند');
        self::assertFalse((bool) current_user_can((string) CpmsAdminMenu::PAGES['cpms-sms'][2]), 'پزشک پیامک را نمی‌بیند');

        $accountant = (int) self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_ACCOUNTANT]);
        wp_set_current_user($accountant);
        self::assertTrue((bool) current_user_can((string) CpmsAdminMenu::PAGES['cpms-finance'][2]), 'حسابدار مالی را می‌بیند');
        self::assertFalse((bool) current_user_can((string) CpmsAdminMenu::PAGES['cpms-patients'][2]), 'حسابدار بیماران را نمی‌بیند');

        // کاربرِ بدونِ هیچ صفحهٔ CPMS: ظرف منو باید حذف شود.
        $subscriber = (int) self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        self::assertFalse(CpmsAdminMenu::has_any_accessible_page());
        $GLOBALS['menu'] = [];
        CpmsAdminMenu::menu();
        CpmsAdminMenu::hide_when_unreachable();
        self::assertSame([], $this->cpmsTopLevelSlugs(), 'کاربر بی‌دسترسی نباید منوی «مدیریت مطب» را ببیند');
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
