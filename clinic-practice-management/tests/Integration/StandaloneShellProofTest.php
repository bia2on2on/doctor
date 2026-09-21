<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Phase 9 Slice 3 — technical proof: theme-independent standalone CPMS shell
 * (PROOF ONLY — this is NOT the Patient Portal implementation).
 *
 * ============================================================================
 * WHAT THIS SUITE PROVES (in-process WordPress lifecycle evidence)
 * ============================================================================
 *
 *  A. request reaches WordPress normally — the proof page resolves through the
 *     ordinary core Page machinery (`go_to()` + main query + `is_page()`), in
 *     BOTH Plain and Pretty permalink modes, with zero custom rewrite code;
 *  B. WordPress user/session remains available — the shell template reads
 *     `wp_get_current_user()` and mints a `wp_rest` nonce on the same request;
 *  C. CPMS controls the entire HTML document — the rendered buffer starts at
 *     `<!DOCTYPE html>` and ends at `</html>`, produced solely by the
 *     plugin-owned template;
 *  D. active Theme header/footer are absent — the two fixture control themes
 *     (Alpha/Beta) emit greppable markers when they render (positive control);
 *     none of those markers appear in the shell document;
 *  E. wp-admin chrome is absent — no admin-bar/admin-menu/admin-footer markers;
 *  F. CPMS shell marker is present;
 *  G. switching between two materially different test themes does not change
 *     the shell contract (identical contract fingerprints);
 *  H. Plain and Pretty permalink modes both resolve and intercept (the browser
 *     authority for H lives in Real WordPress Acceptance `bin/rwp-shell-proof.py`).
 *
 * The interceptor/template under test are TEST FIXTURES
 * (`tests/Fixtures/standalone-shell/`) — never production code, never inside
 * the release ZIP. No Patient Portal content is implemented here.
 *
 * The chosen mechanism — a CPMS-owned WordPress Page + `template_include`
 * interception + a plugin-owned standalone template — is recorded as the final
 * routing/presentation decision in
 * `docs/decisions/2026-09-21-phase9-patient-portal-owner-policy.md`.
 */
final class StandaloneShellProofTest extends WP_UnitTestCase
{
    /** slug ثابت صفحهٔ fixture. */
    private const PROOF_SLUG = 'cpms-shell-proof';

    /** صفحهٔ شاهدِ غیر-fixture — برای اثبات passthrough رهگیری. */
    private const CONTROL_SLUG = 'cpms-shell-normal-page';

    /** baseline قراردادیِ template_include (قالبِ فرضیِ تمِ فعال). */
    private const THEME_BASELINE = '/active-theme/page.php';

    private int $proofPageId = 0;
    private int $controlPageId = 0;
    private string $originalTheme = '';
    private string $originalPermalinkStructure = '';

    protected function setUp(): void
    {
        parent::setUp();

        // فیکسچرِ رهگیر بارگذاری و self-register می‌شود (require_once + register در هر تست،
        // چون WP_UnitTestCase هوک‌ها را بین تست‌ها restore می‌کند).
        require_once self::fixtureDir() . '/cpms-standalone-shell-proof.php';
        \cpms_shell_proof_register();

        $this->installFixtureThemes();
        $this->originalTheme             = (string) get_stylesheet();
        $this->originalPermalinkStructure = (string) get_option('permalink_structure');

        $this->proofPageId = (int) self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'CPMS Shell Proof Fixture',
            'post_name'   => self::PROOF_SLUG,
        ]);
        $this->controlPageId = (int) self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'CPMS Shell Proof Control Page',
            'post_name'   => self::CONTROL_SLUG,
        ]);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        if ('' !== $this->originalTheme) {
            switch_theme($this->originalTheme);
        }
        update_option('permalink_structure', $this->originalPermalinkStructure);
        $GLOBALS['wp_rewrite']->init();
        parent::tearDown();
    }

    // ==================== A + scoping ====================

    /**
     * رهگیری فقط برای صفحهٔ proof — درخواست‌های دیگر دست‌نخورده عبور می‌کنند
     * (بدون سربار/اثر روی صفحات غیرمرتبط).
     */
    public function test_template_include_intercepts_only_the_proof_page(): void
    {
        $this->go_to('/?page_id=' . $this->proofPageId);
        $this->assertTrue(is_page(self::PROOF_SLUG));
        $template = (string) apply_filters('template_include', self::THEME_BASELINE);
        $this->assertSame(\cpms_shell_proof_template_path(), $template);
        $this->assertFileExists($template);

        // شکلِ query-var دوم (معتبر در هر دو حالت permalink — بدون هیچ rewrite سفارشی).
        $this->go_to('/?pagename=' . self::PROOF_SLUG);
        $this->assertTrue(is_page(self::PROOF_SLUG));
        $template = (string) apply_filters('template_include', self::THEME_BASELINE);
        $this->assertSame(\cpms_shell_proof_template_path(), $template);

        // صفحهٔ کنترلی: passthrough کامل.
        $this->go_to('/?page_id=' . $this->controlPageId);
        $this->assertTrue(is_page(self::CONTROL_SLUG));
        $this->assertSame(self::THEME_BASELINE, (string) apply_filters('template_include', self::THEME_BASELINE));
    }

    // ==================== C + D + E + F + G ====================

    /**
     * سندِ کاملِ متعلق به CPMS + نبودِ header/footer تم + نبودِ chromeِ wp-admin +
     * ثابت بودنِ قراردادِ پوسته زیر جابه‌جاییِ دو تمِ کنترلیِ متفاوت (با positive control).
     */
    public function test_shell_owns_full_document_and_is_theme_independent_across_two_themes(): void
    {
        $fingerprints = [];
        foreach (['cpms-proof-theme-alpha', 'cpms-proof-theme-beta'] as $theme) {
            switch_theme($theme);
            $this->assertSame($theme, (string) get_stylesheet());

            // Positive control: اگر تم رندر شود، نشانگرهایش دیده می‌شوند — پس
            // «نبودِ نشانگر» در سندِ پوسته، vacuous نیست.
            $themeDir   = (string) get_theme_root() . '/' . $theme;
            $marker     = ('cpms-proof-theme-alpha' === $theme)
                ? 'CPMS-PROOF-THEME-ALPHA-HEADER'
                : 'CPMS-PROOF-THEME-BETA-HEADER';
            ob_start();
            include $themeDir . '/header.php';
            $headerHtml = (string) ob_get_clean();
            $this->assertStringContainsString($marker, $headerHtml, "positive control: theme {$theme} header marker must render");

            $html = $this->renderShell('/?page_id=' . $this->proofPageId);
            $fp   = $this->contractFingerprint($html);
            foreach ($fp as $claim => $holds) {
                $this->assertTrue($holds, "shell contract violated under theme {$theme}: {$claim}");
            }
            // ردِّ پایِ نشست (anonymous در این تست) عمداً خارج از fingerprint است.
            $this->assertStringContainsString('CPMS-SHELL-PROOF-USER:anonymous', $html);
            $fingerprints[$theme] = $fp;
        }

        // G: قراردادِ پوسته زیر دو تمِ «مادتاً متفاوت» یکسان است.
        $this->assertSame($fingerprints['cpms-proof-theme-alpha'], $fingerprints['cpms-proof-theme-beta']);
    }

    // ==================== B ====================

    /** نشست/کاربرِ وردپرس روی همان درخواستِ پوسته در دسترس است + nonce `wp_rest`. */
    public function test_wordpress_session_identity_available_to_shell(): void
    {
        $uid = (int) self::factory()->user->create(['user_login' => 'shellproof_user_1']);
        wp_set_current_user($uid);

        $html = $this->renderShell('/?page_id=' . $this->proofPageId);
        $this->assertStringContainsString('CPMS-SHELL-PROOF-USER:shellproof_user_1', $html);
        $this->assertStringContainsString('CPMS-SHELL-PROOF-RUNTIME-LOADED', $html, 'runtime افزونهٔ CPMS باید روی همین درخواست حاضر باشد');
        $this->assertTrue(class_exists(App::class));
        $this->assertSame(1, preg_match('/"nonce":"[0-9a-f]+"/', $html), 'nonce `wp_rest` باید در پیکربندی JSON منتشر شود');

        // نشستِ نداشته = anonymous (کوکی/نشست وردپرس مرجع است — نه state محلی).
        wp_set_current_user(0);
        $html = $this->renderShell('/?page_id=' . $this->proofPageId);
        $this->assertStringContainsString('CPMS-SHELL-PROOF-USER:anonymous', $html);
    }

    // ==================== H ====================

    /**
     * Plain و Pretty هر دو صفحهٔ proof را resolve و رهگیری می‌کنند. مرجعِ نهاییِ
     * این ادعا شواهدِ مرورگریِ exact-head در Real WordPress Acceptance است
     * (`bin/rwp-shell-proof.py`)؛ اینجا شواهدِ in-processِ سطح query است.
     */
    public function test_plain_and_pretty_permalink_modes_resolve_and_intercept(): void
    {
        // ----- Plain: هر دو شکلِ query-var عمومی (بدون هیچ rewrite سفارشی) -----
        update_option('permalink_structure', '');
        $GLOBALS['wp_rewrite']->init();
        foreach (['/?page_id=' . $this->proofPageId, '/?pagename=' . self::PROOF_SLUG] as $request) {
            $this->go_to($request);
            $this->assertTrue(is_page(self::PROOF_SLUG), "Plain: {$request} باید صفحهٔ proof را resolve کند");
            $this->assertSame(\cpms_shell_proof_template_path(), (string) apply_filters('template_include', self::THEME_BASELINE));
        }

        // ----- Pretty: مسیرِ زیبا از همان Pageِ core (rules استانداردِ وردپرس) -----
        update_option('permalink_structure', '/%postname%/');
        delete_option('rewrite_rules');
        $GLOBALS['wp_rewrite']->init();
        $GLOBALS['wp_rewrite']->wp_rewrite_rules();
        $this->go_to('/' . self::PROOF_SLUG . '/');
        $this->assertTrue(is_page(self::PROOF_SLUG), 'Pretty: مسیر زیبا باید صفحهٔ proof را resolve کند');
        $this->assertSame(\cpms_shell_proof_template_path(), (string) apply_filters('template_include', self::THEME_BASELINE));

        // بازگشت به حالت اولیه (تعهد تستِ خوب — هرچند rollback تراکنش هم options را برمی‌گرداند).
        update_option('permalink_structure', $this->originalPermalinkStructure);
        $GLOBALS['wp_rewrite']->init();
    }

    // ==================== Performance / global footprint ====================

    /**
     * اثرِ هوکیِ سراسریِ فیکسچر دقیقاً دو فیلترِ شرطی است — بدون asset سراسری،
     * بدون `wp_head`/`wp_footer`، بدون هوکِ چاپ/بارگذاری جهانی.
     */
    public function test_hook_footprint_is_two_conditional_filters_only(): void
    {
        $this->assertNotFalse(has_filter('template_include', 'cpms_shell_proof_template_include'));
        $this->assertNotFalse(has_filter('show_admin_bar', 'cpms_shell_proof_hide_admin_bar'));

        $globalHooks = ['wp_enqueue_scripts', 'wp_head', 'wp_footer', 'init', 'wp_loaded', 'wp', 'template_redirect', 'rest_api_init', 'admin_enqueue_scripts'];
        foreach ($globalHooks as $hook) {
            $this->assertFalse(has_filter($hook, 'cpms_shell_proof_template_include'), "template_include-callback نباید روی {$hook} باشد");
            $this->assertFalse(has_filter($hook, 'cpms_shell_proof_hide_admin_bar'), "admin-bar-callback نباید روی {$hook} باشد");
        }
    }

    // ==================== Helpers ====================

    private static function fixtureDir(): string
    {
        return dirname(__DIR__) . '/Fixtures/standalone-shell';
    }

    private function installFixtureThemes(): void
    {
        $themesRoot = (string) get_theme_root();
        foreach (['cpms-proof-theme-alpha', 'cpms-proof-theme-beta'] as $slug) {
            $dest = $themesRoot . '/' . $slug;
            if (!is_dir($dest)) {
                $this->copyDir(self::fixtureDir() . '/themes/' . $slug, $dest);
            }
        }
        wp_clean_themes_cache(true);
    }

    private function copyDir(string $src, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0775, true);
        }
        foreach (scandir($src) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $from = $src . '/' . $entry;
            $to   = $dest . '/' . $entry;
            if (is_dir($from)) {
                $this->copyDir($from, $to);
            } else {
                copy($from, $to);
            }
        }
    }

    /** رندر واقعیِ سندِ پوسته از مسیرِ همان lifecycle (go_to → template_include → include). */
    private function renderShell(string $request): string
    {
        $this->go_to($request);
        $this->assertTrue(is_page(self::PROOF_SLUG));
        $template = (string) apply_filters('template_include', self::THEME_BASELINE);
        $this->assertSame(\cpms_shell_proof_template_path(), $template);
        ob_start();
        include $template;

        return (string) ob_get_clean();
    }

    /**
     * قراردادِ پوسته = بردارِ presence/absence ثابت (بدون مقادیرِ وابسته به نشست).
     * برابریِ این بردار زیر جابه‌جاییِ تم = اثبات G.
     */
    private function contractFingerprint(string $html): array
    {
        $body = ltrim($html);

        return [
            'doctype_document_start' => str_starts_with($body, '<!DOCTYPE html>'),
            'document_end'           => str_contains($html, '</html>'),
            'shell_marker_present'   => str_contains($html, 'CPMS-STANDALONE-SHELL-PROOF'),
            'shell_owned_attr'       => str_contains($html, 'data-cpms-standalone-shell="proof-fixture"'),
            'shell_contract_attr'    => str_contains($html, 'data-shell-contract="v1"'),
            'config_published'       => str_contains($html, 'cpms-shell-proof__config'),
            'runtime_loaded'         => str_contains($html, 'CPMS-SHELL-PROOF-RUNTIME-LOADED'),
            'theme_alpha_absent'     => !str_contains($html, 'CPMS-PROOF-THEME-ALPHA'),
            'theme_beta_absent'      => !str_contains($html, 'CPMS-PROOF-THEME-BETA'),
            'admin_bar_absent'       => !str_contains($html, 'id="wpadminbar"') && !str_contains($html, 'wp-admin-bar-'),
            'admin_menu_absent'      => !str_contains($html, 'id="adminmenu"'),
            'admin_footer_absent'    => !str_contains($html, 'id="wpfooter"'),
        ];
    }
}
