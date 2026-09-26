<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;

/**
 * ویرایشگر دست‌خط پزشک (F7) — Full-Screen Canvas (wireframes/doctor.md §3).
 *
 * FR-9.1..9.8 / ADR-0009 (Strokeها gzip JSON) / ADR-0014 (Offline + Revision):
 *  - Canvas با DPR scaling + مختصات منطقی صفحه (1240×1754) — D-24.
 *  - قلم: pointerType pen/mouse (Pressure-Sensitive)؛ touch = pan/pinch
 *    (palm rejection عملی) — D-24 «بدون از دست رفتن Stroke».
 *  - ابزار: قلم/هایلایتر، پاک‌کن سطح-Stroke، Undo/Redo کلاینتی،
 *    Zoom/Pan، Full-Screen، Multi-page ([+ صفحه])، Template، Annotation روی تصویر (E16).
 *  - Auto-save هر `hw.autosave_sec`: همیشه IndexedDB + PUT سرور با
 *    Idempotency-Key تازه هر Save؛ وضعیت: Saving/Saved/Offline/Failed.
 *  - Backoff: 5/30/120/600/1800s؛ resume روی online/focus.
 *  - حذف Local بعد از Sync طبق `hw.local_retain` (T-16).
 *  - Conflict (409 CLINIC_CONFLICT): دیالوگ دو تب «نسخه من / نسخه سرور» —
 *    بدون ادغام خودکار (ADR-0014)؛ بازنویسی = load-then-save با
 *    `conflict_reason` در Audit.
 *  - Preview PNG = Render کلاینتی (ستون preview_png سرور NULL می‌ماند).
 */
final class DoctorHandwritingPage
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    }

    /** The wp-admin handwriting screen is the only admin consumer of the shared engine. */
    public static function enqueue_assets( string $hook_suffix ): void {
        if ( ! str_contains( $hook_suffix, 'cpms-handwriting' ) || ! current_user_can( RolesAndCapabilities::NOTE_CREATE ) ) {
            return;
        }
        \ClinicCore\Frontend\DoctorPortalShell::register_handwriting_assets();
        wp_enqueue_style( \ClinicCore\Frontend\DoctorPortalShell::HANDWRITING_HANDLE );
        wp_enqueue_script( \ClinicCore\Frontend\DoctorPortalShell::HANDWRITING_HANDLE );
    }

    public static function menu(): void
    {
        add_submenu_page(
            'cpms-doctor', // زیر «امروز پزشک»
            'دست‌خط',
            'دست‌خط',
            RolesAndCapabilities::NOTE_CREATE,
            'cpms-handwriting',
            [self::class, 'render'],
            1
        );
    }

    public static function render(): void
    {
        if (!current_user_can(RolesAndCapabilities::NOTE_CREATE)) {
            wp_die('دسترسی ندارید', 403);
        }

        $visit_id     = isset( $_GET['visit_id'] ) ? absint( $_GET['visit_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $clinician_id = null;
        $settings     = null;
        $paper_context = null;
        $user_id      = get_current_user_id();
        if ( $visit_id > 0 && $user_id > 0 ) {
            try {
                $db           = App::db();
                $memberships  = new MembershipRepository( $db );
                $clinician_id = $memberships->active_clinician_id_for_wp_user( $user_id );
                $visits       = new VisitRepository( $db );
                $visit        = $clinician_id ? $visits->find( $visit_id ) : null;
                if ( $visit !== null && (int) $visit['clinician_id'] === $clinician_id && (int) $visit['clinic_id'] > 0 ) {
                    $clinic_id   = (int) $visit['clinic_id'];
                    $establisher = new TrustedClinicEstablisher( $db, $memberships );
                    $scope       = $establisher->establish( $user_id, $clinic_id );
                    if ( (int) $scope->clinicId === $clinic_id && App::authorization_service()->can( $user_id, $clinic_id, RolesAndCapabilities::NOTE_CREATE ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope property
                        $settings = App::settingsFactory()->forClinic( $clinic_id );
                        $paper_context = \ClinicCore\Application\Handwriting\PrescriptionPaperContext::forVisit(
                            $db, $visit_id, $clinic_id, (int) $visit['location_id'], $clinician_id
                        );
                    }
                }
            } catch ( \Throwable $e ) {
                unset( $e ); // Missing, ambiguous or untrusted scope never exposes the editor.
            }
        }

        if ( $settings === null ) {
            echo '<div class="wrap" dir="rtl"><h1>دست‌خط</h1>' .
                '<div class="notice notice-warning"><p>این صفحه از طریق دکمه «🖋️ دست‌خط» در صفحه ویزیت باز می‌شود.</p></div></div>';

            return;
        }

        $config = [
            'rest_url' => esc_url_raw(rest_url('clinic/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'visit_id'     => $visit_id,
            'paper_context' => $paper_context,
            'clinician_id' => $clinician_id,
            'autosave_sec' => max( 2, (int) $settings->get( 'hw.autosave_sec', 5 ) ),
            'local_retain' => (string) $settings->get( 'hw.local_retain', 'off' ),
            'back_url'     => admin_url( 'admin.php?page=cpms-doctor&visit_id=' . $visit_id ),
            'can_upload' => current_user_can(RolesAndCapabilities::FILE_UPLOAD),
        ];
        ?>
<div id="cpms-hw-app" dir="rtl" data-visit="<?php echo (int) $visit_id; ?>">
    <div id="cpms-hw-head">
        <a id="cpms-hw-close" href="<?php echo esc_url((string) $config['back_url']); ?>" title="بستن">✕</a>
        <nav id="cpms-hw-pages" aria-label="صفحات"></nav>
        <button type="button" class="cpms-hw-btn" id="cpms-hw-addpage" title="افزودن صفحه">＋ صفحه</button>
        <span id="cpms-hw-sync" class="cpms-hw-sync" data-state="loading">⏳ در حال بارگذاری…</span>
    </div>

    <div id="cpms-hw-body">
        <aside id="cpms-hw-tools">
            <button type="button" class="cpms-hw-tool is-active" data-tool="pen" title="قلم">🖊️</button>
            <button type="button" class="cpms-hw-tool" data-tool="highlighter" title="هایلایتر">🖍️</button>
            <button type="button" class="cpms-hw-tool" data-tool="eraser" title="پاک‌کن (سطح Stroke)">🟥</button>
            <div class="cpms-hw-sep"></div>
            <div class="cpms-hw-sizes" title="اندازه قلم">
                <button type="button" class="cpms-hw-size" data-size="2"><i style="width:4px;height:4px"></i></button>
                <button type="button" class="cpms-hw-size is-active" data-size="4"><i style="width:8px;height:8px"></i></button>
                <button type="button" class="cpms-hw-size" data-size="8"><i style="width:14px;height:14px"></i></button>
                <button type="button" class="cpms-hw-size" data-size="16"><i style="width:20px;height:20px"></i></button>
            </div>
            <div class="cpms-hw-colors" title="رنگ">
                <button type="button" class="cpms-hw-color is-active" data-color="#1a1a2e" style="background:#1a1a2e"></button>
                <button type="button" class="cpms-hw-color" data-color="#c0392b" style="background:#c0392b"></button>
                <button type="button" class="cpms-hw-color" data-color="#1665d8" style="background:#1665d8"></button>
                <button type="button" class="cpms-hw-color" data-color="#2e7d32" style="background:#2e7d32"></button>
            </div>
            <div class="cpms-hw-sep"></div>
            <button type="button" class="cpms-hw-btn" id="cpms-hw-undo" title="Undo">↩️</button>
            <button type="button" class="cpms-hw-btn" id="cpms-hw-redo" title="Redo">↪️</button>
            <button type="button" class="cpms-hw-btn" id="cpms-hw-zout" title="کوچک‌نمایی">➖</button>
            <button type="button" class="cpms-hw-btn" id="cpms-hw-zin" title="بزرگ‌نمایی">➕</button>
            <button type="button" class="cpms-hw-btn" id="cpms-hw-zreset" title="اندازه اصلی">⤢ ۱:۱</button>
            <button type="button" class="cpms-hw-btn" id="cpms-hw-full" title="Full-Screen">⛶</button>
            <div class="cpms-hw-sep"></div>
            <button type="button" class="cpms-hw-btn" id="cpms-hw-image" title="نوشتن روی تصویر (Annotation)">🖼️</button>
            <input type="file" id="cpms-hw-image-input" accept="image/*" hidden>
            <select id="cpms-hw-template" title="قالب صفحه" aria-label="قالب صفحه">
                <option value="lined">خط‌دار</option>
                <option value="blank">ساده</option>
                <option value="prescription">برگه نسخه</option>
                <option value="graph">مربع‌دار</option>
                <option value="form">فرم</option>
            </select>
            <button type="button" class="cpms-hw-btn cpms-hw-save-now" id="cpms-hw-save" title="ذخیره الان">💾 ذخیره</button>
        </aside>

        <div id="cpms-hw-stage"><canvas id="cpms-hw-canvas"></canvas></div>
    </div>
</div>

<div id="cpms-hw-conflict" class="cpms-hw-modal" hidden>
    <div class="cpms-hw-modal-box">
        <h2>⚠️ تضاد نسخه‌ها</h2>
        <p>این صفحه از جای دیگری (مثلاً دستگاه دیگر) تغییر کرده است. کدام نسخه را نگه می‌دارید؟</p>
        <div class="cpms-hw-tabs">
            <button type="button" class="cpms-hw-tab is-active" data-tab="mine">نسخه من</button>
            <button type="button" class="cpms-hw-tab" data-tab="server">نسخه سرور</button>
        </div>
        <div class="cpms-hw-tabpanes">
            <canvas id="cpms-hw-cv-mine" width="310" height="438"></canvas>
            <canvas id="cpms-hw-cv-server" width="310" height="438" hidden></canvas>
        </div>
        <div class="cpms-hw-modal-actions">
            <button type="button" class="button button-primary" id="cpms-hw-keep-mine">بازنویسی با نسخه من</button>
            <button type="button" class="button" id="cpms-hw-keep-server">نگه‌داشتن نسخه سرور</button>
        </div>
    </div>
</div>



<script>
window.CPMS_HW = <?php echo wp_json_encode($config); ?>;
</script>

<?php
    }

}
