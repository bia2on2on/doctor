<?php
/**
 * Phase 11 Slice 1 — Staff Portal reception module (Arrival Board), embed mode.
 *
 * Mounted by templates/staff-portal-shell.php inside the canonical shared
 * Staff Portal shell. One trusted Clinic + one operational Location, today's
 * booked patients for that Location, ONE arrival action ("arrived / ready")
 * that runs the EXISTING check-in + enqueue transitions in valid order, and a
 * read-only queue status board (waiting / called / in_consultation / skipped)
 * reflecting the EXISTING doctor call/start/recall/skip actions.
 *
 * Phase 11 Slice 2 adds a read-only Clinic patient search panel: it calls the
 * reception search boundary (a thin adapter over the ESTABLISHED
 * PatientService::search contract), renders the bounded search presentation
 * (masked national ID) and lets the secretary mark ONE result as selected.
 * The selection is presentation-only: it is never posted anywhere, creates no
 * authority, and is not coupled to the arrival action. Location never filters
 * this Clinic-scoped search.
 *
 * Phase 11 Slice 3 adds a SMALL create-patient form on the same panel. Create
 * posts to the reception adapter over PatientService::create, then places the
 * new patient into the EXISTING read-only selected presentation. No walk-in,
 * appointment, check-in, queue or Visit is started.
 *
 * Embed contract (same as the doctor module): the shell owns the document,
 * header and navigation; this module contributes its <main>, ONE inline style
 * block, ONE runtime config JSON and ONE inline script, and closes with
 * </main></div> exactly like the doctor module. No theme calls, no footer.
 *
 * No frontend framework, no build step, no CDN, no external assets.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use ClinicCore\Frontend\StaffPortalShell;

if ( empty( $cpms_staff_embed ) ) {
    // Standalone access is fail-closed: reception only lives inside the shell.
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>پورتال کارکنان</title></head>
<body>
<p>این صفحه فقط از طریق پورتال کارکنان در دسترس است.</p>
</body>
</html>
    <?php
    return;
}

if ( ! class_exists( 'ClinicCore\Frontend\StaffPortalShell' ) ) {
    require_once __DIR__ . '/../src/Frontend/StaffPortalShell.php';
}

$cpms_reception_cfg = [
    'rest_root'    => esc_url_raw( untrailingslashit( rest_url( 'clinic/v1' ) ) ),
    'nonce'        => esc_attr( wp_create_nonce( 'wp_rest' ) ),
    'portal_url'   => esc_url_raw( StaffPortalShell::portal_url() ),
    'is_reception' => true,
];
?>
<style>
.cpms-staff-reception { max-width: 1100px; }
.cpms-staff-reception .cpms-sr-panel { background: #fff; border: 1px solid var(--cpms-border); border-radius: 10px; padding: 12px; margin: 0 0 12px; }
.cpms-staff-reception .cpms-sr-top { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; }
.cpms-staff-reception .cpms-sr-chip { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 4px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #f7fafb; font-size: 0.86rem; max-width: 100%; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-chip--primary { background: var(--cpms-primary); border-color: var(--cpms-primary); color: #fff; }
.cpms-staff-reception .cpms-sr-select { min-height: 38px; max-width: 100%; padding: 4px 8px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font-size: 0.9rem; }
.cpms-staff-reception .cpms-sr-required { font-size: 0.82rem; color: var(--cpms-muted); }
.cpms-staff-reception .cpms-sr-board { display: grid; gap: 12px; }
.cpms-staff-reception .cpms-sr-stats { display: flex; flex-wrap: wrap; gap: 8px; }
.cpms-staff-reception .cpms-sr-stat { flex: 1 1 140px; min-width: 0; background: #f7fafb; border: 1px solid var(--cpms-border); border-radius: 8px; padding: 8px 10px; }
.cpms-staff-reception .cpms-sr-stat b { display: block; font-size: 1.1rem; }
.cpms-staff-reception .cpms-sr-table { width: 100%; border-collapse: collapse; }
.cpms-staff-reception .cpms-sr-table th, .cpms-staff-reception .cpms-sr-table td { padding: 8px 6px; border-bottom: 1px solid var(--cpms-border); text-align: right; font-size: 0.92rem; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-table tr:last-child td { border-bottom: 0; }
.cpms-staff-reception .cpms-sr-name { font-weight: bold; }
.cpms-staff-reception .cpms-sr-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--cpms-border); font-size: 0.78rem; white-space: nowrap; }
.cpms-staff-reception .cpms-sr-badge--waiting { background: #fff7e6; border-color: #e8c37e; }
.cpms-staff-reception .cpms-sr-badge--called { background: #e8f1ff; border-color: #8db4e8; }
.cpms-staff-reception .cpms-sr-badge--in_consultation { background: #e7f6ec; border-color: #7dc494; }
.cpms-staff-reception .cpms-sr-badge--checked_in { background: #f0eefe; border-color: #a89ee8; }
.cpms-staff-reception .cpms-sr-badge--skipped { background: #fbecec; border-color: #e09a9a; }
.cpms-staff-reception .cpms-sr-badge--express { background: #fdf1e3; border-color: #e2b184; }
.cpms-staff-reception .cpms-sr-btn { min-height: 40px; min-width: 96px; padding: 6px 14px; border: 1px solid var(--cpms-primary); border-radius: 8px; background: var(--cpms-primary); color: #fff; font-size: 0.9rem; cursor: pointer; }
.cpms-staff-reception .cpms-sr-btn[disabled] { opacity: 0.55; cursor: default; }
.cpms-staff-reception .cpms-sr-btn--ghost { background: #fff; color: var(--cpms-primary); }
.cpms-staff-reception .cpms-sr-actions { display: flex; flex-wrap: wrap; gap: 6px; }
.cpms-staff-reception .cpms-sr-cancel { display: grid; gap: 8px; padding: 8px 2px; }
.cpms-staff-reception .cpms-sr-cancel[hidden], .cpms-staff-reception tr[data-role="sr-row-cancel"][hidden] { display: none; }
.cpms-staff-reception .cpms-sr-cancel-hint { margin: 0; color: var(--cpms-muted); font-size: 0.88rem; }
.cpms-staff-reception .cpms-sr-cancel-field { display: grid; gap: 4px; font-size: 0.88rem; }
.cpms-staff-reception .cpms-sr-cancel-field input { min-height: 40px; padding: 6px 10px; border: 1px solid #c9ccd6; border-radius: 8px; }
.cpms-staff-reception .cpms-sr-cancel-actions { display: flex; flex-wrap: wrap; gap: 6px; }
.cpms-staff-reception .cpms-sr-status { font-size: 0.92rem; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-status--error { color: #a12828; }
.cpms-staff-reception .cpms-sr-status--ok { color: var(--cpms-primary); }
.cpms-staff-reception .cpms-sr-empty { color: var(--cpms-muted); padding: 10px 4px; }
.cpms-staff-reception .cpms-sr-live { min-height: 1.4em; }
.cpms-staff-reception .cpms-sr-search h2 { margin: 0 0 8px; font-size: 1rem; }
.cpms-staff-reception .cpms-sr-search-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
.cpms-staff-reception .cpms-sr-search-field { display: flex; flex-direction: column; gap: 4px; flex: 1 1 260px; min-width: 0; font-size: 0.86rem; }
.cpms-staff-reception .cpms-sr-search-input { min-height: 40px; width: 100%; box-sizing: border-box; padding: 6px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font-size: 0.95rem; }
.cpms-staff-reception .cpms-sr-search-input:focus-visible, .cpms-staff-reception .cpms-sr-search-result:focus-visible { outline: 2px solid var(--cpms-primary); outline-offset: 1px; }
.cpms-staff-reception .cpms-sr-search-state { margin: 8px 0 0; font-size: 0.86rem; color: var(--cpms-muted); min-height: 1.3em; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-search-state--error { color: #a12828; }
.cpms-staff-reception .cpms-sr-search-results { list-style: none; margin: 8px 0 0; padding: 0; display: grid; gap: 6px; max-height: 360px; overflow-y: auto; }
.cpms-staff-reception .cpms-sr-search-result { display: flex; flex-wrap: wrap; gap: 4px 12px; align-items: baseline; width: 100%; min-height: 44px; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; color: inherit; font: inherit; font-size: 0.9rem; text-align: right; cursor: pointer; }
.cpms-staff-reception .cpms-sr-search-result[aria-pressed="true"] { border-color: var(--cpms-primary); background: #eef7f6; box-shadow: inset 3px 0 0 var(--cpms-primary); }
.cpms-staff-reception .cpms-sr-search-meta { color: var(--cpms-muted); font-size: 0.84rem; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-search-meta bdi { unicode-bidi: isolate; }
.cpms-staff-reception .cpms-sr-search-selected { margin: 10px 0 0; padding: 10px; border: 1px solid var(--cpms-primary); border-radius: 8px; background: #f3faf9; display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; justify-content: space-between; }
.cpms-staff-reception .cpms-sr-search-selected-body { min-width: 0; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-search-selected-note { display: block; font-size: 0.8rem; color: var(--cpms-muted); }
.cpms-staff-reception .cpms-sr-search-selected-note--ok { color: var(--cpms-primary); }
.cpms-staff-reception .cpms-sr-create-open { margin: 8px 0 0; }
.cpms-staff-reception .cpms-sr-create { margin: 10px 0 0; padding: 10px; border: 1px dashed var(--cpms-border); border-radius: 8px; background: #fbfcfd; }
.cpms-staff-reception .cpms-sr-create h3 { margin: 0 0 8px; font-size: 0.95rem; }
.cpms-staff-reception .cpms-sr-create-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.cpms-staff-reception .cpms-sr-create-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; font-size: 0.86rem; }
.cpms-staff-reception .cpms-sr-create-field--wide { grid-column: 1 / -1; }
.cpms-staff-reception .cpms-sr-create-optional { margin: 10px 0 0; padding-top: 8px; border-top: 1px solid var(--cpms-border); }
.cpms-staff-reception .cpms-sr-create-optional-legend { display: block; margin: 0 0 6px; font-size: 0.78rem; color: var(--cpms-muted); }
.cpms-staff-reception .cpms-sr-create-req { color: #a12828; }
.cpms-staff-reception .cpms-sr-create input, .cpms-staff-reception .cpms-sr-create select { min-height: 40px; width: 100%; box-sizing: border-box; padding: 6px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font-size: 0.95rem; }
.cpms-staff-reception .cpms-sr-create input:focus-visible, .cpms-staff-reception .cpms-sr-create select:focus-visible { outline: 2px solid var(--cpms-primary); outline-offset: 1px; }
.cpms-staff-reception .cpms-sr-create-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 0; }
.cpms-staff-reception .cpms-sr-create-error { margin: 8px 0 0; font-size: 0.86rem; color: #a12828; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-walkin { display: grid; gap: 8px; }
.cpms-staff-reception .cpms-sr-walkin h2 { margin: 0; font-size: 1rem; }
.cpms-staff-reception .cpms-sr-walkin-meta { margin: 0; font-size: 0.86rem; color: var(--cpms-muted); overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-walkin-field { display: flex; flex-direction: column; gap: 4px; max-width: 420px; font-size: 0.86rem; }
.cpms-staff-reception .cpms-sr-walkin-field select { min-height: 40px; width: 100%; box-sizing: border-box; padding: 6px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font-size: 0.95rem; }
.cpms-staff-reception .cpms-sr-walkin-field select:focus-visible { outline: 2px solid var(--cpms-primary); outline-offset: 1px; }
.cpms-staff-reception .cpms-sr-walkin-doctor { margin: 0; font-size: 0.92rem; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-walkin-state { margin: 0; min-height: 1.3em; font-size: 0.88rem; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-walkin-state--error { color: #a12828; }
.cpms-staff-reception .cpms-sr-walkin-state--ok { color: var(--cpms-primary); font-weight: bold; }
.cpms-staff-reception .cpms-sr-walkin-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.cpms-staff-reception .cpms-sr-book { display: grid; gap: 8px; }
.cpms-staff-reception .cpms-sr-book h2 { margin: 0; font-size: 1rem; }
.cpms-staff-reception .cpms-sr-book-meta { margin: 0; font-size: 0.86rem; color: var(--cpms-muted); overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-book-fields { display: flex; flex-wrap: wrap; gap: 10px; }
.cpms-staff-reception .cpms-sr-book-field { display: flex; flex-direction: column; gap: 4px; flex: 1 1 180px; min-width: 160px; max-width: 420px; font-size: 0.86rem; }
.cpms-staff-reception .cpms-sr-book-field select, .cpms-staff-reception .cpms-sr-book-field input { min-height: 40px; width: 100%; box-sizing: border-box; padding: 6px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font-size: 0.95rem; }
.cpms-staff-reception .cpms-sr-book-field select:focus-visible, .cpms-staff-reception .cpms-sr-book-field input:focus-visible { outline: 2px solid var(--cpms-primary); outline-offset: 1px; }
.cpms-staff-reception .cpms-sr-book-doctor { margin: 0; font-size: 0.92rem; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-book-slots { margin: 0; padding: 8px; border: 1px solid var(--cpms-border); border-radius: 8px; }
.cpms-staff-reception .cpms-sr-book-slots legend { padding: 0 4px; font-size: 0.86rem; color: var(--cpms-muted); }
.cpms-staff-reception .cpms-sr-book-slots-list { display: flex; flex-wrap: wrap; gap: 6px; }
.cpms-staff-reception .cpms-sr-book-slot { min-height: 40px; padding: 6px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font-size: 0.92rem; cursor: pointer; }
.cpms-staff-reception .cpms-sr-book-slot[aria-pressed="true"] { border-color: var(--cpms-primary); background: #f3faf9; font-weight: bold; }
.cpms-staff-reception .cpms-sr-book-slot:focus-visible { outline: 2px solid var(--cpms-primary); outline-offset: 1px; }
.cpms-staff-reception .cpms-sr-book-slot:disabled { opacity: 0.6; cursor: not-allowed; }
.cpms-staff-reception .cpms-sr-book-slot-cap { display: block; font-size: 0.75rem; color: var(--cpms-muted); font-weight: normal; }
.cpms-staff-reception .cpms-sr-book-empty { margin: 0; font-size: 0.88rem; color: var(--cpms-muted); }
.cpms-staff-reception .cpms-sr-book-state { margin: 0; min-height: 1.3em; font-size: 0.88rem; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-book-state--error { color: #a12828; }
.cpms-staff-reception .cpms-sr-book-state--ok { color: var(--cpms-primary); font-weight: bold; }
.cpms-staff-reception .cpms-sr-book-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.cpms-staff-reception .cpms-sr-btn--reschedule { background: #fff; color: #2b5aa8; border-color: #2b5aa8; }
.cpms-staff-reception .cpms-sr-reschedule[hidden] { display: none; }
.cpms-staff-reception .cpms-sr-reschedule { display: grid; gap: 8px; }
.cpms-staff-reception .cpms-sr-reschedule h2 { margin: 0; font-size: 1rem; }
.cpms-staff-reception .cpms-sr-reschedule-meta { margin: 0; font-size: 0.86rem; color: var(--cpms-muted); overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-reschedule-context { margin: 0; padding: 8px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fafbfe; font-size: 0.9rem; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-reschedule-fields { display: flex; flex-wrap: wrap; gap: 10px; }
.cpms-staff-reception .cpms-sr-reschedule-field { display: flex; flex-direction: column; gap: 4px; flex: 1 1 180px; min-width: 160px; max-width: 420px; font-size: 0.86rem; }
.cpms-staff-reception .cpms-sr-reschedule-field select { min-height: 40px; width: 100%; box-sizing: border-box; padding: 6px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font-size: 0.95rem; }
.cpms-staff-reception .cpms-sr-reschedule-field select:focus-visible { outline: 2px solid var(--cpms-primary); outline-offset: 1px; }
.cpms-staff-reception .cpms-sr-reschedule-doctor { margin: 0; font-size: 0.92rem; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-reschedule-slots { margin: 0; padding: 8px; border: 1px solid var(--cpms-border); border-radius: 8px; }
.cpms-staff-reception .cpms-sr-reschedule-slots legend { padding: 0 4px; font-size: 0.86rem; color: var(--cpms-muted); }
.cpms-staff-reception .cpms-sr-reschedule-slots-list { display: flex; flex-wrap: wrap; gap: 6px; }
.cpms-staff-reception .cpms-sr-reschedule-slot { min-height: 40px; padding: 6px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font-size: 0.92rem; cursor: pointer; }
.cpms-staff-reception .cpms-sr-reschedule-slot[aria-pressed="true"] { border-color: #2b5aa8; background: #eff4fc; font-weight: bold; }
.cpms-staff-reception .cpms-sr-reschedule-slot:focus-visible { outline: 2px solid var(--cpms-primary); outline-offset: 1px; }
.cpms-staff-reception .cpms-sr-reschedule-slot:disabled { opacity: 0.6; cursor: not-allowed; }
.cpms-staff-reception .cpms-sr-reschedule-slot-cap { display: block; font-size: 0.75rem; color: var(--cpms-muted); font-weight: normal; }
.cpms-staff-reception .cpms-sr-reschedule-empty { margin: 0; font-size: 0.88rem; color: var(--cpms-muted); }
.cpms-staff-reception .cpms-sr-reschedule-selected { margin: 0; min-height: 1.3em; font-size: 0.88rem; color: var(--cpms-primary); }
.cpms-staff-reception .cpms-sr-reschedule-state { margin: 0; min-height: 1.3em; font-size: 0.88rem; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-reschedule-state--error { color: #a12828; }
.cpms-staff-reception .cpms-sr-reschedule-state--ok { color: var(--cpms-primary); font-weight: bold; }
.cpms-staff-reception .cpms-sr-reschedule-actions { display: flex; flex-wrap: wrap; gap: 8px; }
@media (max-width: 768px) {
    .cpms-staff-reception .cpms-sr-top { flex-direction: column; align-items: stretch; }
    .cpms-staff-reception .cpms-sr-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cpms-staff-reception .cpms-sr-table thead { display: none; }
    .cpms-staff-reception .cpms-sr-table, .cpms-staff-reception .cpms-sr-table tbody, .cpms-staff-reception .cpms-sr-table tr, .cpms-staff-reception .cpms-sr-table td { display: block; width: 100%; }
    .cpms-staff-reception .cpms-sr-table tr { border-bottom: 1px solid var(--cpms-border); padding: 8px 0; }
    .cpms-staff-reception .cpms-sr-table td { border-bottom: 0; padding: 3px 4px; }
    .cpms-staff-reception .cpms-sr-create-grid { grid-template-columns: 1fr; }
}
</style>
<div class="cpms-staff-reception" data-role="reception-app">
<main id="cpms-staff-reception-main" class="cpms-staff-reception-main" role="main" data-cpms-staff-module="reception">
    <section class="cpms-sr-panel cpms-sr-top" data-role="sr-top">
        <div class="cpms-sr-chips" data-role="sr-scope">
            <span class="cpms-sr-chip cpms-sr-chip--primary" data-role="sr-clinic" title="کلینیک مورد اعتماد">—</span>
            <label class="cpms-sr-chip" data-role="sr-location-wrap">
                <span>موقعیت:</span>
                <select class="cpms-sr-select" data-role="sr-location-select" aria-label="انتخاب موقعیت عملیاتی" hidden></select>
                <span class="cpms-sr-chip" data-role="sr-location" hidden>—</span>
            </label>
        </div>
        <div class="cpms-sr-chip" data-role="sr-date">—</div>
    </section>

    <section class="cpms-sr-panel cpms-sr-status cpms-sr-live" data-role="sr-status" role="status" aria-live="polite">در حال بارگذاری…</section>

    <section class="cpms-sr-panel cpms-sr-search" data-role="sr-search" aria-labelledby="cpms-sr-search-title">
        <h2 id="cpms-sr-search-title">جستجوی بیمار در کلینیک</h2>
        <form class="cpms-sr-search-form" data-role="sr-search-form" role="search" novalidate>
            <label class="cpms-sr-search-field" for="cpms-sr-search-input">
                <span>نام، نام خانوادگی، موبایل، کد ملی یا شمارهٔ پرونده</span>
                <input type="search" id="cpms-sr-search-input" class="cpms-sr-search-input" data-role="sr-search-input" maxlength="64" autocomplete="off" spellcheck="false" enterkeyhint="search" aria-describedby="cpms-sr-search-state">
            </label>
            <button type="submit" class="cpms-sr-btn cpms-sr-btn--ghost" data-role="sr-search-submit">جستجو</button>
        </form>
        <p class="cpms-sr-search-state" id="cpms-sr-search-state" data-role="sr-search-state" role="status" aria-live="polite">برای جستجو دست‌کم ۲ نویسه وارد کنید.</p>
        <button type="button" class="cpms-sr-btn cpms-sr-btn--ghost cpms-sr-create-open" data-role="sr-create-open" hidden>ثبت بیمار جدید</button>
        <form class="cpms-sr-create" data-role="sr-create" hidden novalidate>
            <h3>ثبت بیمار تازه</h3>
            <div class="cpms-sr-create-grid">
                <label class="cpms-sr-create-field" for="cpms-sr-create-first-name">
                    <span>نام <span class="cpms-sr-create-req">*</span></span>
                    <input type="text" id="cpms-sr-create-first-name" name="first_name" data-role="sr-create-first-name" maxlength="120" autocomplete="off" required>
                </label>
                <label class="cpms-sr-create-field" for="cpms-sr-create-last-name">
                    <span>نام خانوادگی <span class="cpms-sr-create-req">*</span></span>
                    <input type="text" id="cpms-sr-create-last-name" name="last_name" data-role="sr-create-last-name" maxlength="120" autocomplete="off" required>
                </label>
                <label class="cpms-sr-create-field cpms-sr-create-field--wide" for="cpms-sr-create-mobile">
                    <span>موبایل <span class="cpms-sr-create-req">*</span></span>
                    <input type="tel" id="cpms-sr-create-mobile" name="mobile" data-role="sr-create-mobile" maxlength="20" inputmode="tel" autocomplete="off" required>
                </label>
            </div>
            <div class="cpms-sr-create-optional">
                <span class="cpms-sr-create-optional-legend">اختیاری</span>
                <div class="cpms-sr-create-grid">
                    <label class="cpms-sr-create-field" for="cpms-sr-create-national-id">
                        <span>کد ملی</span>
                        <input type="text" id="cpms-sr-create-national-id" name="national_id" data-role="sr-create-national-id" maxlength="10" inputmode="numeric" autocomplete="off">
                    </label>
                    <label class="cpms-sr-create-field" for="cpms-sr-create-birth-date">
                        <span>تاریخ تولد</span>
                        <input type="date" id="cpms-sr-create-birth-date" name="birth_date" data-role="sr-create-birth-date" autocomplete="off">
                    </label>
                    <label class="cpms-sr-create-field" for="cpms-sr-create-gender">
                        <span>جنسیت</span>
                        <select id="cpms-sr-create-gender" name="gender" data-role="sr-create-gender">
                            <option value="">—</option>
                            <option value="female">زن</option>
                            <option value="male">مرد</option>
                            <option value="other">دیگر</option>
                            <option value="unknown">نامشخص</option>
                        </select>
                    </label>
                </div>
            </div>
            <p class="cpms-sr-create-error" data-role="sr-create-error" role="alert" hidden></p>
            <div class="cpms-sr-create-actions">
                <button type="submit" class="cpms-sr-btn" data-role="sr-create-submit">ثبت بیمار</button>
                <button type="button" class="cpms-sr-btn cpms-sr-btn--ghost" data-role="sr-create-cancel">انصراف</button>
            </div>
        </form>
        <ul class="cpms-sr-search-results" data-role="sr-search-results" aria-label="نتایج جستجوی بیمار"></ul>
        <div class="cpms-sr-search-selected" data-role="sr-search-selected" hidden>
            <div class="cpms-sr-search-selected-body">
                <strong>بیمار انتخاب‌شده:</strong> <span data-role="sr-search-selected-text"></span>
                <span class="cpms-sr-search-selected-note" data-role="sr-search-selected-note">فقط برای شناسایی — هیچ تغییری در پرونده یا نوبت ایجاد نمی‌شود.</span>
            </div>
            <button type="button" class="cpms-sr-btn cpms-sr-btn--ghost" data-role="sr-search-clear">لغو انتخاب</button>
        </div>
    </section>

    <section class="cpms-sr-panel cpms-sr-walkin" data-role="sr-walkin" aria-labelledby="cpms-sr-walkin-title" hidden>
        <h2 id="cpms-sr-walkin-title">ورود حضوری بدون نوبت</h2>
        <p class="cpms-sr-walkin-meta">بیمار انتخاب‌شده، بدون ساخت نوبت، مستقیم وارد صف انتظار پزشک می‌شود. موقعیت: <strong data-role="sr-walkin-location">—</strong></p>
        <label class="cpms-sr-walkin-field" for="cpms-sr-walkin-clinician" data-role="sr-walkin-clinician-wrap" hidden>
            <span>پزشک <span class="cpms-sr-create-req">*</span></span>
            <select id="cpms-sr-walkin-clinician" data-role="sr-walkin-clinician"></select>
        </label>
        <p class="cpms-sr-walkin-doctor" data-role="sr-walkin-doctor" hidden></p>
        <p class="cpms-sr-walkin-state" data-role="sr-walkin-state" role="status" aria-live="polite"></p>
        <div class="cpms-sr-walkin-actions">
            <button type="button" class="cpms-sr-btn" data-role="sr-walkin-submit" disabled>ثبت ورود حضوری و افزودن به صف</button>
            <button type="button" class="cpms-sr-btn" data-role="sr-walkin-recover" hidden>تکمیل ورود به صف</button>
        </div>
    </section>

    <section class="cpms-sr-panel cpms-sr-book" data-role="sr-book" aria-labelledby="cpms-sr-book-title" hidden>
        <h2 id="cpms-sr-book-title">ثبت نوبت برای بیمار انتخاب‌شده</h2>
        <p class="cpms-sr-book-meta">نوبت فقط از ساعت‌های آزادِ از پیش ساخته‌شدهٔ همین موقعیت انتخاب می‌شود و هیچ ویزیت، صف، ورود حضوری یا پرداختی ایجاد نمی‌کند. موقعیت: <strong data-role="sr-book-location">—</strong></p>
        <div class="cpms-sr-book-fields">
            <label class="cpms-sr-book-field" for="cpms-sr-book-clinician" data-role="sr-book-clinician-wrap" hidden>
                <span>پزشک <span class="cpms-sr-create-req">*</span></span>
                <select id="cpms-sr-book-clinician" data-role="sr-book-clinician"></select>
            </label>
            <label class="cpms-sr-book-field" for="cpms-sr-book-date" data-role="sr-book-date-wrap" hidden>
                <span>تاریخ <span class="cpms-sr-create-req">*</span></span>
                <select id="cpms-sr-book-date" data-role="sr-book-date"></select>
            </label>
            <label class="cpms-sr-book-field" for="cpms-sr-book-reason">
                <span>دلیل مراجعه (اختیاری)</span>
                <input type="text" id="cpms-sr-book-reason" data-role="sr-book-reason" maxlength="255" autocomplete="off">
            </label>
        </div>
        <p class="cpms-sr-book-doctor" data-role="sr-book-doctor" hidden></p>
        <fieldset class="cpms-sr-book-slots">
            <legend>ساعت آزاد</legend>
            <div class="cpms-sr-book-slots-list" data-role="sr-book-slots" role="group" aria-label="ساعت‌های آزادِ قابل انتخاب"></div>
        </fieldset>
        <p class="cpms-sr-book-state" data-role="sr-book-state" role="status" aria-live="polite"></p>
        <div class="cpms-sr-book-actions">
            <button type="button" class="cpms-sr-btn" data-role="sr-book-submit" disabled>ثبت نوبت</button>
        </div>
    </section>

    <section class="cpms-sr-panel cpms-sr-reschedule" data-section="sr-reschedule" aria-labelledby="cpms-sr-reschedule-title" hidden>
        <div class="cpms-sr-reschedule" data-role="sr-reschedule-form">
            <h2 id="cpms-sr-reschedule-title">جابه‌جایی نوبت</h2>
            <p class="cpms-sr-reschedule-meta">جابه‌جایی فقط داخل همین موقعیت عملیاتی انجام می‌شود؛ اسلات مقصد باید از پیش ساخته‌شده و آزاد باشد و هیچ ویزیت، صف، ورود حضوری یا پرداختی ایجاد نمی‌شود. همین موقعیتِ فعال: <strong data-role="sr-reschedule-context-scope">—</strong></p>
            <p class="cpms-sr-reschedule-context" data-role="sr-reschedule-context" role="note">—</p>
            <div class="cpms-sr-reschedule-fields">
                <label class="cpms-sr-reschedule-field" for="cpms-sr-reschedule-clinician" data-role="sr-reschedule-clinician-wrap" hidden>
                    <span>پزشک مقصد</span>
                    <select id="cpms-sr-reschedule-clinician" data-role="sr-reschedule-clinician"></select>
                </label>
                <label class="cpms-sr-reschedule-field" for="cpms-sr-reschedule-date" data-role="sr-reschedule-date-wrap" hidden>
                    <span>تاریخ</span>
                    <select id="cpms-sr-reschedule-date" data-role="sr-reschedule-date"></select>
                </label>
            </div>
            <p class="cpms-sr-reschedule-doctor" data-role="sr-reschedule-doctor" hidden></p>
            <fieldset class="cpms-sr-reschedule-slots">
                <legend>ساعت آزادِ مقصد — فقط اسلات‌های از پیش ساخته‌شدهٔ همین موقعیت</legend>
                <div class="cpms-sr-reschedule-slots-list" data-role="sr-reschedule-slots" role="group" aria-label="ساعت‌های آزادِ قابل انتخاب"></div>
            </fieldset>
            <p class="cpms-sr-reschedule-selected" data-role="sr-reschedule-selected"></p>
            <p class="cpms-sr-reschedule-state" data-role="sr-reschedule-state" role="status" aria-live="polite"></p>
            <div class="cpms-sr-reschedule-actions">
                <button type="button" class="cpms-sr-btn" data-role="sr-reschedule-confirm" disabled>تأیید جابه‌جایی نوبت</button>
                <button type="button" class="cpms-sr-btn cpms-sr-btn--ghost" data-role="sr-reschedule-abort">انصراف</button>
            </div>
        </div>
    </section>

    <section class="cpms-sr-board" data-role="sr-board">
        <div class="cpms-sr-stats" data-role="sr-stats" hidden>
            <div class="cpms-sr-stat" data-role="sr-stat-waiting"><b>—</b><span>در انتظار</span></div>
            <div class="cpms-sr-stat" data-role="sr-stat-called"><b>—</b><span>فراخوانی‌شده</span></div>
            <div class="cpms-sr-stat" data-role="sr-stat-in_consultation"><b>—</b><span>در ویزیت</span></div>
            <div class="cpms-sr-stat" data-role="sr-stat-skipped"><b>—</b><span>لغو امروز</span></div>
        </div>

        <div class="cpms-sr-panel" data-role="sr-appointments-panel">
            <table class="cpms-sr-table" data-role="sr-appointments">
                <thead>
                    <tr>
                        <th scope="col">ساعت</th>
                        <th scope="col">بیمار</th>
                        <th scope="col">وضعیت</th>
                        <th scope="col">اقدام</th>
                    </tr>
                </thead>
                <tbody data-role="sr-appointments-body">
                    <tr><td colspan="4" class="cpms-sr-empty" data-role="sr-appointments-empty">در حال بارگذاری…</td></tr>
                </tbody>
            </table>
        </div>

        <div class="cpms-sr-panel" data-role="sr-upcoming-panel">
            <h2>نوبت‌های آینده</h2>
            <button type="button" class="cpms-sr-btn cpms-sr-btn--ghost" data-role="sr-upcoming-refresh">تازه‌سازی نوبت‌های آینده</button>
            <div data-role="sr-upcoming-state" role="status">در حال بارگذاری…</div>
            <div data-role="sr-upcoming-rows"></div>
        </div>

        <div class="cpms-sr-panel" data-role="sr-queue-panel">
            <table class="cpms-sr-table" data-role="sr-queue">
                <thead>
                    <tr>
                        <th scope="col">صف امروز</th>
                        <th scope="col">بیمار</th>
                        <th scope="col">وضعیت</th>
                    </tr>
                </thead>
                <tbody data-role="sr-queue-body">
                    <tr><td colspan="3" class="cpms-sr-empty" data-role="sr-queue-empty">—</td></tr>
                </tbody>
            </table>
        </div>
    </section>
</main>
</div>
<script type="application/json" id="cpms-staff-reception-config"><?php echo wp_json_encode( $cpms_reception_cfg ); ?></script>
<script>
(function () {
    'use strict';

    var CONFIG = { restRoot: '', nonce: '', portalUrl: '' };
    try {
        var raw = document.getElementById('cpms-staff-reception-config').textContent;
        var parsed = JSON.parse(raw);
        CONFIG.restRoot = String(parsed.rest_root || '').replace(/\/$/, '');
        CONFIG.nonce = String(parsed.nonce || '');
        CONFIG.portalUrl = String(parsed.portal_url || '');
    } catch (e) {
        return;
    }

    var POLL_MS = 5000;

    var state = {
        clinicId: null,
        clinicName: '',
        locationId: null,
        locationName: '',
        locations: [],
        date: null,
        busy: false
    };

    function el(role) {
        return document.querySelector('[data-role="' + role + '"]');
    }

    var statusKind = null;
    var statusSticky = false;

    function setStatus(text, kind, sticky) {
        var node = el('sr-status');
        statusKind = text ? kind : null;
        statusSticky = Boolean(text && sticky);
        if (!node) {
            return;
        }
        node.textContent = text || '';
        node.className = 'cpms-sr-panel cpms-sr-status cpms-sr-live' + (kind === 'error' ? ' cpms-sr-status--error' : (kind === 'ok' ? ' cpms-sr-status--ok' : ''));
    }

    function scopeHeaders() {
        var headers = {};
        if (state.clinicId) {
            headers['X-CPMS-Clinic-Id'] = String(state.clinicId);
        }
        if (state.locationId) {
            headers['X-CPMS-Location-Id'] = String(state.locationId);
        }
        return headers;
    }

    function api(path, options) {
        options = options || {};
        var headers = Object.assign({ 'X-WP-Nonce': CONFIG.nonce, 'Accept': 'application/json' }, scopeHeaders(), options.headers || {});
        var init = { method: options.method || 'GET', headers: headers, credentials: 'same-origin', cache: 'no-store' };
        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }
        return fetch(CONFIG.restRoot + path, init).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                return { status: response.status, ok: response.ok, body: body };
            });
        });
    }

    function payloadOf(body) {
        return (body && typeof body === 'object' && body.data && typeof body.data === 'object') ? body.data : (body || {});
    }

    function errorCodeOf(body) {
        return String((body && body.code) || '');
    }

    function errorMessageOf(body, fallback) {
        return String((body && body.message) || fallback);
    }

    function statusLabel(status) {
        var labels = {
            waiting: 'در انتظار',
            called: 'فراخوانی‌شده',
            in_consultation: 'در ویزیت',
            checked_in: 'حضوریافته',
            skipped: 'لغو امروز',
            completed: 'پایان‌یافته'
        };
        return labels[status] || status || '—';
    }

    function statusBadge(status, extra) {
        return '<span class="cpms-sr-badge cpms-sr-badge--' + (status || 'none') + (extra ? ' ' + extra : '') + '">' + escapeHtml(statusLabel(status)) + '</span>';
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function renderScope() {
        var clinicNode = el('sr-clinic');
        if (clinicNode) {
            clinicNode.textContent = state.clinicName || 'کلینیک';
        }
        var select = el('sr-location-select');
        var chip = el('sr-location');
        if (select && chip) {
            if (state.locations.length > 1) {
                select.hidden = false;
                chip.hidden = true;
                var current = String(state.locationId || '');
                if (select.options.length !== state.locations.length) {
                    select.innerHTML = '';
                    for (var i = 0; i < state.locations.length; i += 1) {
                        var option = document.createElement('option');
                        option.value = String(state.locations[i].id);
                        option.textContent = String(state.locations[i].name || ('موقعیت ' + state.locations[i].id));
                        select.appendChild(option);
                    }
                }
                if (current) {
                    select.value = current;
                }
            } else {
                select.hidden = true;
                chip.hidden = false;
                chip.textContent = state.locationName || '—';
            }
        }
        var dateNode = el('sr-date');
        if (dateNode) {
            dateNode.textContent = state.date ? ('تاریخ عملیاتی: ' + state.date) : 'تاریخ عملیاتی: —';
        }
    }

    function renderStats(stats) {
        var wrap = el('sr-stats');
        if (!wrap) {
            return;
        }
        wrap.hidden = false;
        var map = { waiting: 'sr-stat-waiting', called: 'sr-stat-called', in_consultation: 'sr-stat-in_consultation', skipped: 'sr-stat-skipped' };
        Object.keys(map).forEach(function (key) {
            var node = el(map[key]);
            if (node && node.firstElementChild) {
                node.firstElementChild.textContent = String((stats && stats[key]) || 0);
            }
        });
    }

    function renderAppointments(rows) {
        var body = el('sr-appointments-body');
        if (!body) {
            return;
        }
        if (!rows || rows.length === 0) {
            body.innerHTML = '<tr><td colspan="4" class="cpms-sr-empty">نوبتی برای امروز در این موقعیت ثبت نشده است.</td></tr>';
            return;
        }
            var html = '';
            for (var i = 0; i < rows.length; i += 1) {
                var row = rows[i];
                var canArrive = !row.visit_status && (row.status === 'pending' || row.status === 'confirmed');
                var canRecover = row.visit_status === 'checked_in';
                // Presentation rule (not a second state machine): a booked row
                // that has NOT entered the Visit/queue workflow yet is the only
                // row offered cancellation. Rows already received / waiting /
                // called / in consultation expose no cancel action — the
                // backend stays authoritative for every refusal.
                var canCancel = canArrive;
            // Presentation-only clarity: the real appointment state badge is
            // kept as-is; a booked row without a visit also shows that the
            // patient has NOT yet been received (no state semantics change).
            var badge = row.visit_status ? statusBadge(row.visit_status) : '<span class="cpms-sr-badge">' + escapeHtml(row.status === 'confirmed' ? 'تاییدشده' : 'رزرو شده') + '</span> <span class="cpms-sr-badge cpms-sr-badge--not-received">هنوز پذیرش نشده</span>';
            var express = row.express ? ' <span class="cpms-sr-badge cpms-sr-badge--express">فوری</span>' : '';
            // Recoverable partial (checked_in) keeps a clear completion action;
            // queued/in-service rows expose NO retry action at all.
            var action = '';
            if (canRecover) {
                action = '<button type="button" class="cpms-sr-btn cpms-sr-btn--recover" data-role="sr-recover" data-patient-id="' + escapeHtml(row.patient_id) + '" data-appointment-id="' + escapeHtml(row.id) + '" title="حضور ثبت شده است؛ قرارگیری در صف انتظار هنوز کامل نشده">تکمیل ورود به صف</button>';
            } else if (canArrive) {
                action = '<button type="button" class="cpms-sr-btn" data-role="sr-arrive" data-patient-id="' + escapeHtml(row.patient_id) + '" data-appointment-id="' + escapeHtml(row.id) + '">حضوریافت / آماده شد</button>';
            }
            var cancelAction = canCancel
                ? '<button type="button" class="cpms-sr-btn cpms-sr-btn--ghost" data-role="sr-cancel-open" data-appointment-id="' + escapeHtml(row.id) + '">لغو نوبت</button>'
                : '';
            // Phase 11 Slice 7 — presentation rule only (never a second state
            // machine): the existing T7 machine reschedules a CONFIRMED row and
            // the I-3 guard blocks a row with an active Visit, so the explicit
            // reschedule action is offered ONLY on a confirmed, not-yet-received
            // row. `doctor` carries the current clinician SELECTOR (id + name)
            // from the bounded day projection so the panel can show the current
            // context and pre-select that doctor when still eligible — the server
            // re-resolves every clinician on every mutation.
            var doctor = doctorsOf(row.id);
            var canReschedule = !row.visit_status && row.status === 'confirmed';
            var rescheduleAction = canReschedule
                ? '<button type="button" class="cpms-sr-btn cpms-sr-btn--reschedule" data-role="sr-reschedule-open" data-appointment-id="' + escapeHtml(row.id) + '" data-patient-id="' + escapeHtml(row.patient_id) + '" data-patient-name="' + escapeHtml(row.patient_name) + '" data-appointment-time="' + escapeHtml(row.time) + '" data-clinician-id="' + escapeHtml(doctor.id) + '" data-clinician-name="' + escapeHtml(doctor.name) + '">جابه‌جایی نوبت</button>'
                : '';
            var cancelRow = canCancel
                ? '<tr data-role="sr-row-cancel" data-appointment-id="' + escapeHtml(row.id) + '" hidden><td colspan="4">' +
                    '<div class="cpms-sr-cancel" data-role="sr-cancel-form" data-appointment-id="' + escapeHtml(row.id) + '">' +
                        '<p class="cpms-sr-cancel-hint">لغو این نوبت آن را از تختهٔ پذیرش خارج می‌کند و اسلات آزاد می‌شود. دلیل اختیاری است.</p>' +
                        '<label class="cpms-sr-cancel-field">دلیل لغو (اختیاری)' +
                            '<input type="text" data-role="sr-cancel-reason" autocomplete="off" placeholder="مثلاً: درخواست بیمار">' +
                        '</label>' +
                        '<div class="cpms-sr-cancel-actions">' +
                            '<button type="button" class="cpms-sr-btn" data-role="sr-cancel-confirm" data-appointment-id="' + escapeHtml(row.id) + '">تأیید لغو نوبت</button>' +
                            '<button type="button" class="cpms-sr-btn cpms-sr-btn--ghost" data-role="sr-cancel-abort" data-appointment-id="' + escapeHtml(row.id) + '">انصراف</button>' +
                        '</div>' +
                    '</div>' +
                '</td></tr>'
                : '';
            html += '<tr data-role="sr-row" data-appointment-id="' + escapeHtml(row.id) + '">' +
                '<td data-role="sr-row-time">' + escapeHtml(row.time) + '</td>' +
                '<td class="cpms-sr-name" data-role="sr-row-name">' + escapeHtml(row.patient_name) + express + ' · پزشک: ' + escapeHtml(doctor.name) + '</td>' +
                '<td data-role="sr-row-status">' + badge + '</td>' +
                '<td data-role="sr-row-action"><div class="cpms-sr-actions">' + action + rescheduleAction + cancelAction + '</div></td>' +
                '</tr>' + cancelRow;
        }
        body.innerHTML = html;
    }

    var upcomingSeq = 0;
    function loadUpcoming() {
        var seq = ++upcomingSeq;
        var container = document.querySelector('[data-role="sr-upcoming-rows"]');
        var message = document.querySelector('[data-role="sr-upcoming-state"]');
        if (!container || !message) { return; }
        container.innerHTML = '';
        message.textContent = 'در حال بارگذاری…';
        if (!state.locationId) {
            message.textContent = 'ابتدا موقعیت عملیاتی را انتخاب کنید.';
            return;
        }
        api('/staff/portal/reception/upcoming').then(function (result) {
            if (seq !== upcomingSeq) { return; }
            if (!result.ok) { message.textContent = 'خطا در دریافت نوبت‌های آینده: ' + errorMessageOf(result.body, 'دوباره تلاش کنید.'); return; }
            var rows = payloadOf(result.body).appointments || [];
            message.textContent = rows.length ? '' : 'نوبت آینده‌ای در بازهٔ رزرو این موقعیت ثبت نشده است.';
            var html = '';
            rows.forEach(function (row) {
                var id = escapeHtml(row.id);
                var name = escapeHtml(row.patient_name);
                var doctor = escapeHtml(row.clinician_name);
                html += '<div class="cpms-sr-panel" data-role="sr-upcoming-row" data-appointment-id="' + id + '">' +
                    '<p><strong>' + name + '</strong> · پزشک: ' + doctor + ' · ' + escapeHtml(row.jalali) + ' · ' + escapeHtml(row.time) + ' · ' + (row.status === 'confirmed' ? 'تاییدشده' : 'رزرو شده') + '</p>' +
                    '<div class="cpms-sr-actions"><button type="button" class="cpms-sr-btn cpms-sr-btn--ghost" data-role="sr-cancel-open" data-appointment-id="' + id + '">لغو نوبت</button>' +
                    (row.status === 'confirmed' ? '<button type="button" class="cpms-sr-btn cpms-sr-btn--reschedule" data-role="sr-reschedule-open" data-appointment-id="' + id + '" data-patient-id="' + escapeHtml(row.patient_id) + '" data-patient-name="' + name + '" data-appointment-time="' + escapeHtml(row.time) + '" data-clinician-id="' + escapeHtml(row.clinician_id) + '" data-clinician-name="' + doctor + '">جابه‌جایی نوبت</button>' : '') + '</div>' +
                    '<div data-role="sr-row-cancel" data-appointment-id="' + id + '" hidden><label>دلیل لغو (اختیاری) <input type="text" data-role="sr-cancel-reason"></label><button type="button" class="cpms-sr-btn" data-role="sr-cancel-confirm" data-appointment-id="' + id + '">تأیید لغو نوبت</button><button type="button" class="cpms-sr-btn cpms-sr-btn--ghost" data-role="sr-cancel-abort" data-appointment-id="' + id + '">انصراف</button></div></div>';
            });
            container.innerHTML = html;
        }).catch(function () { if (seq === upcomingSeq) { message.textContent = 'خطای شبکه هنگام دریافت نوبت‌های آینده.'; } });
    }
    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-role="sr-upcoming-refresh"]')) { loadUpcoming(); }
    });

    function renderQueue(rows) {
        var body = el('sr-queue-body');
        if (!body) {
            return;
        }
        if (!rows || rows.length === 0) {
            body.innerHTML = '<tr><td colspan="3" class="cpms-sr-empty">صف امروز خالی است.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < rows.length; i += 1) {
            var row = rows[i];
            html += '<tr data-role="sr-queue-row">' +
                '<td data-role="sr-queue-pos">' + escapeHtml(row.queue_position !== null && row.queue_position !== undefined ? row.queue_position : (i + 1)) + '</td>' +
                '<td class="cpms-sr-name">' + escapeHtml(row.patient_name || ('بیمار ' + (row.patient_id || ''))) + '</td>' +
                '<td>' + statusBadge(row.status) + '</td>' +
                '</tr>';
        }
        body.innerHTML = html;
    }

    // Phase 11 Slice 7 — bounded appointment→clinician labels shipped with the
    // EXISTING board read (one projection, no extra request): used only as a
    // display selector for the reschedule panel context.
    var dayDoctors = {};

    function doctorsIndex(data) {
        dayDoctors = {};
        var rows = data && Array.isArray(data.clinicians) ? data.clinicians : [];
        for (var i = 0; i < rows.length; i += 1) {
            var row = rows[i] || {};
            dayDoctors[String(row.appointment_id)] = {
                id: row.clinician_id ? Number(row.clinician_id) : null,
                name: String(row.clinician_name || '')
            };
        }
    }

    function doctorsOf(appointmentId) {
        return dayDoctors[String(appointmentId)] || { id: null, name: '' };
    }

    function renderBoard(data) {
        doctorsIndex(data);
        state.date = data.date || null;
        if (data.location_id) {
            state.locationId = Number(data.location_id);
        }
        if (data.location_name) {
            state.locationName = String(data.location_name);
        }
        renderScope();
        renderStats(data.stats);
        renderAppointments(data.appointments || []);
        renderQueue(data.queue || []);
    }

    function loadContext() {
        return api('/staff/portal/reception/context').then(function (result) {
            var data = payloadOf(result.body);
            if (!result.ok) {
                setStatus('خطا در دریافت محدودهٔ کاری: ' + errorMessageOf(result.body, ''), 'error');
                return;
            }
            state.locations = (data.eligible_locations || []).map(function (loc) {
                return { id: Number(loc.id), name: String(loc.name || '') };
            });
            if (data.current_clinic) {
                state.clinicId = Number(data.current_clinic.id);
                state.clinicName = String(data.current_clinic.name || '');
            } else if (data.selected_clinic_id) {
                state.clinicId = Number(data.selected_clinic_id);
            }
            if (data.current_location) {
                state.locationId = Number(data.current_location.id);
                state.locationName = String(data.current_location.name || '');
            } else if (data.selected_location_id) {
                state.locationId = Number(data.selected_location_id);
            }
            renderScope();
        }).catch(function () {
            setStatus('خطای شبکه هنگام دریافت محدودهٔ کاری.', 'error');
        });
    }

    function loadBoard(silent) {
        if (state.locations.length > 1 && !state.locationId) {
            renderScope();
            setStatus('بیش از یک موقعیت واجد شرایط موجود است — انتخاب موقعیت الزامی است.', 'error');
            return Promise.resolve();
        }
        return api('/staff/portal/reception/board').then(function (result) {
            if (!result.ok) {
                var code = errorCodeOf(result.body);
                if (code === 'CLINIC_SCOPE_REQUIRED') {
                    setStatus('انتخاب موقعیت عملیاتی الزامی است.', 'error');
                    return;
                }
                if (code === 'CLINIC_SCOPE_UNAVAILABLE') {
                    setStatus('امکان تعیین محدودهٔ کلینیک معتبر نیست.', 'error');
                    return;
                }
                if (!silent) {
                    setStatus('خطا در دریافت تختهٔ پذیرش: ' + errorMessageOf(result.body, ''), 'error');
                }
                return;
            }
            renderBoard(payloadOf(result.body));
            // Keep durable success/status messages across silent refreshes. A
            // silent success may clear only a NON-sticky error (e.g. a network
            // hiccup on refresh) — the actionable partial-arrival message is
            // sticky until an explicit/new action or a successful recovery.
            if (!silent || (statusKind === 'error' && !statusSticky)) {
                setStatus('', null);
            }
        }).catch(function () {
            setStatus('خطای شبکه هنگام دریافت تختهٔ پذیرش.', 'error');
        });
    }

    function arrive(button) {
        if (state.busy) {
            return;
        }
        state.busy = true;
        button.disabled = true;
        var patientId = Number(button.getAttribute('data-patient-id') || 0);
        var appointmentId = Number(button.getAttribute('data-appointment-id') || 0);
        var isRecovery = button.getAttribute('data-role') === 'sr-recover';
        setStatus(isRecovery ? 'در حال تکمیل ورود به صف…' : 'در حال ثبت حضور…', null);
        api('/staff/portal/reception/arrivals', { method: 'POST', body: { patient_id: patientId, appointment_id: appointmentId } })
            .then(function (result) {
                var data = payloadOf(result.body);
                var arrival = data.arrival || {};
                var code = (result.body && result.body.code) || (data && data.code) || '';
                if (!result.ok) {
                    if (code === 'CLINIC_ARRIVAL_INCOMPLETE' || arrival.complete === false) {
                        // Actionable partial: arrival recorded, queue placement
                        // still needs completion. Sticky — a silent board
                        // refresh must not erase it; only a new action or a
                        // successful recovery clears it.
                        var partialErr = (arrival.enqueue_error && arrival.enqueue_error.message) ? arrival.enqueue_error.message : 'افزودن به صف انجام نشد';
                        setStatus('حضور ثبت شد، اما قرارگیری در صف انجام نشد (' + partialErr + ') — برای تکمیل، «تکمیل ورود به صف» را بزنید.', 'error', true);
                    } else {
                        setStatus((isRecovery ? 'تکمیل ورود به صف انجام نشد: ' : 'ثبت حضور انجام نشد: ') + errorMessageOf(result.body, ''), 'error');
                    }
                    return loadBoard(true);
                }
                if (arrival.complete) {
                    setStatus('حضور ثبت شد و بیمار در صف انتظار قرار گرفت.', 'ok');
                } else {
                    // Defensive: never claim success over an incomplete arrival.
                    var partial = (arrival.enqueue_error && arrival.enqueue_error.message) ? arrival.enqueue_error.message : 'افزودن به صف انجام نشد';
                    setStatus('حضور ثبت شد، اما قرارگیری در صف انجام نشد (' + partial + ') — برای تکمیل، «تکمیل ورود به صف» را بزنید.', 'error', true);
                }
                return loadBoard(true);
            })
            .catch(function () {
                setStatus('خطای شبکه هنگام ثبت حضور.', 'error');
            })
            .then(function () {
                state.busy = false;
            });
    }

    // ---- Phase 11 Slice 6: cancel a booked appointment (trusted Location) ----
    // One explicit mutation plus the EXISTING board refresh. The cancellation
    // itself is the established staff-cancel service behind the Reception
    // adapter — nothing about transitions, slot release, audit, notification or
    // the active-Visit guard is re-implemented here. The reason is OPTIONAL and
    // is sent as typed (server-side length policy stays authoritative).
    var cancelBusy = false;

    function cancelRowNode(appointmentId) {
        return document.querySelector('[data-role="sr-row-cancel"][data-appointment-id="' + String(appointmentId) + '"]');
    }

    function cancelClose(appointmentId) {
        var wrap = cancelRowNode(appointmentId);
        if (wrap) {
            wrap.hidden = true;
            var reason = wrap.querySelector('[data-role="sr-cancel-reason"]');
            if (reason) {
                reason.value = '';
            }
        }
    }

    function cancelOpen(target) {
        if (state.busy || cancelBusy) {
            return;
        }
        var id = target.getAttribute('data-appointment-id');
        if (!id) {
            return;
        }
        // Only one open confirmation at a time.
        var wrappers = document.querySelectorAll('[data-role="sr-row-cancel"]');
        for (var i = 0; i < wrappers.length; i += 1) {
            wrappers[i].hidden = true;
        }
        var wrap = cancelRowNode(id);
        if (!wrap) {
            return;
        }
        wrap.hidden = false;
        setStatus('برای لغو، دلیل اختیاری را وارد کنید و «تأیید لغو نوبت» را بزنید.', null);
        var reason = wrap.querySelector('[data-role="sr-cancel-reason"]');
        if (reason) {
            reason.focus();
        }
    }

    function cancelSubmit(target) {
        if (cancelBusy) {
            return;
        }
        var id = target.getAttribute('data-appointment-id');
        if (!id) {
            return;
        }
        var wrap = cancelRowNode(id);
        if (!wrap) {
            return;
        }
        var reasonNode = wrap.querySelector('[data-role="sr-cancel-reason"]');
        var reason = reasonNode ? String(reasonNode.value || '').trim() : '';
        // Prevent double submit: no second request can leave this window, and
        // the confirm button is disabled for the duration.
        cancelBusy = true;
        target.disabled = true;
        setStatus('در حال لغو نوبت…', null);
        var refreshed = false;
        api('/staff/portal/reception/appointments/' + encodeURIComponent(id) + '/cancel', { method: 'POST', body: { reason: reason } })
            .then(function (result) {
                var data = payloadOf(result.body);
                if (result.ok && data.appointment) {
                    cancelClose(id);
                    setStatus('نوبت لغو شد و از تختهٔ پذیرش خارج شد. اسلات آزاد شد؛ هیچ ویزیت یا صفی ایجاد نشد.', 'ok');
                    refreshed = true;
                    return loadBoard(true).then(loadUpcoming);
                }
                // Honest bounded error surface: an active Visit / already
                // cancelled / out-of-scope row keeps its row and shows the
                // server message — never a fake success.
                setStatus('لغو نوبت انجام نشد: ' + errorMessageOf(result.body, ''), 'error', true);
                refreshed = true;
                return loadBoard(true);
            })
            .catch(function () {
                setStatus('خطای شبکه هنگام لغو نوبت.', 'error');
            })
            .then(function () {
                cancelBusy = false;
                target.disabled = false;
                if (!refreshed) {
                    loadBoard(true);
                }
            });
    }

    // ---- Phase 11 Slice 7: reschedule a booked appointment (trusted Location) ----
    // ONE explicit mutation plus the EXISTING board refresh. The reschedule is
    // the established staff contract behind this Reception adapter — the machine
    // transition, replacement appointment, slot locking/release/claim, capacity,
    // Clinic horizon, audit, reminder cancellation, notification/SMS and
    // idempotency storage all stay server-side. The destination is ALWAYS a
    // persisted slot of the CURRENT trusted operational Location picked from the
    // existing Slice 5 read; the panel never lists another Location, never keeps
    // a stale slot after the doctor/date changes, and never synthesizes state.
    var reschedule = {
        appointmentId: null,
        patientName: '',
        appointmentTime: '',
        currentClinicianId: null,
        currentClinicianName: '',
        locationId: null,
        clinicians: [],
        clinicianId: null,
        days: [],
        date: null,
        slots: [],
        slotId: null,
        seq: 0,
        busy: false,
        done: false,
        idemKey: null
    };

    function rescheduleSection() {
        return document.querySelector('[data-section="sr-reschedule"]');
    }

    function setRescheduleState(text, kind) {
        var node = el('sr-reschedule-state');
        if (!node) {
            return;
        }
        node.textContent = text || '';
        node.className = 'cpms-sr-reschedule-state' + (kind === 'error' ? ' cpms-sr-reschedule-state--error' : (kind === 'ok' ? ' cpms-sr-reschedule-state--ok' : ''));
    }

    /** استثنای کدپیگیری: یک کلید UUID برای هر تلاشِ صریح؛ همان کلید در تکرارِ
     * همان تلاش (خطای شبکه) دوباره فرستاده می‌شود و سرور مالک PENDING/Replay است. */
    function rescheduleIdemKey() {
        if (!reschedule.idemKey) {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                reschedule.idemKey = String(window.crypto.randomUUID());
            } else {
                var bytes = new Uint8Array(16);
                window.crypto.getRandomValues(bytes);
                bytes[6] = (bytes[6] & 0x0f) | 0x40;
                bytes[8] = (bytes[8] & 0x3f) | 0x80;
                var hex = '';
                for (var i = 0; i < 16; i += 1) {
                    hex += (bytes[i] + 0x100).toString(16).slice(1);
                }
                reschedule.idemKey = hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
            }
        }
        return reschedule.idemKey;
    }

    function rescheduleSync() {
        var confirm = el('sr-reschedule-confirm');
        if (confirm) {
            confirm.disabled = reschedule.busy || reschedule.done || !reschedule.appointmentId || !reschedule.clinicianId || !reschedule.slotId ||
                !reschedule.locationId || String(reschedule.locationId) !== String(state.locationId || reschedule.locationId);
        }
        var clinician = el('sr-reschedule-clinician');
        var date = el('sr-reschedule-date');
        var abort = el('sr-reschedule-abort');
        if (clinician) {
            clinician.disabled = reschedule.busy;
        }
        if (date) {
            date.disabled = reschedule.busy;
        }
        if (abort) {
            abort.disabled = reschedule.busy && !reschedule.done;
        }
        var buttons = document.querySelectorAll('[data-role="sr-reschedule-slot"]');
        for (var i = 0; i < buttons.length; i += 1) {
            buttons[i].disabled = reschedule.busy || reschedule.done;
        }
    }

    function rescheduleContext(scopeName) {
        var parts = [];
        if (reschedule.patientName) {
            parts.push('بیمار: ' + reschedule.patientName);
        }
        if (reschedule.appointmentTime) {
            parts.push('ساعت فعلی: ' + reschedule.appointmentTime);
        }
        if (reschedule.currentClinicianName) {
            parts.push('پزشک فعلی: ' + reschedule.currentClinicianName);
        } else if (reschedule.currentClinicianId) {
            parts.push('پزشک فعلی: #' + reschedule.currentClinicianId);
        }
        var node = el('sr-reschedule-context');
        if (node) {
            node.textContent = parts.length ? parts.join(' — ') : '—';
        }
        var scope = el('sr-reschedule-context-scope');
        if (scope) {
            scope.textContent = String(scopeName || state.locationName || '—');
        }
    }

    function rescheduleRenderSlots() {
        var list = el('sr-reschedule-slots');
        if (!list) {
            return;
        }
        if (!reschedule.slots.length) {
            list.innerHTML = '<p class="cpms-sr-reschedule-empty" data-role="sr-reschedule-slots-empty">برای این پزشک در این تاریخ ساعت آزادی در همین موقعیت ثبت نشده است.</p>';
            return;
        }
        var html = '';
        for (var i = 0; i < reschedule.slots.length; i += 1) {
            var slot = reschedule.slots[i];
            var pressed = reschedule.slotId && String(reschedule.slotId) === String(slot.slot_id) ? 'true' : 'false';
            html += '<button type="button" class="cpms-sr-reschedule-slot" data-role="sr-reschedule-slot" data-slot-id="' + escapeHtml(slot.slot_id) + '" aria-pressed="' + pressed + '">' +
                escapeHtml(slot.time) +
                '<span class="cpms-sr-reschedule-slot-cap">ظرفیت باقی‌مانده ' + faDigits(slot.capacity_left) + ' · ' + faDigits(slot.duration_min) + ' دقیقه</span>' +
                '</button>';
        }
        list.innerHTML = html;
    }

    function rescheduleRenderDays() {
        var select = el('sr-reschedule-date');
        var wrap = el('sr-reschedule-date-wrap');
        if (!select || !wrap) {
            return;
        }
        if (!reschedule.days.length) {
            select.innerHTML = '';
            wrap.hidden = true;
            return;
        }
        var html = '';
        for (var i = 0; i < reschedule.days.length; i += 1) {
            var day = reschedule.days[i];
            html += '<option value="' + escapeHtml(day.date) + '"' + (String(day.date) === String(reschedule.date) ? ' selected' : '') + '>' + escapeHtml(day.jalali) + '</option>';
        }
        select.innerHTML = html;
        select.value = String(reschedule.date || reschedule.days[0].date);
        wrap.hidden = false;
    }

    function rescheduleRenderSelected() {
        var node = el('sr-reschedule-selected');
        if (!node) {
            return;
        }
        var chosen = null;
        for (var i = 0; i < reschedule.slots.length; i += 1) {
            if (Number(reschedule.slots[i].slot_id) === Number(reschedule.slotId)) {
                chosen = reschedule.slots[i];
            }
        }
        node.textContent = chosen ? ('اسلات مقصد انتخاب شد: ' + reschedule.date + ' ساعت ' + chosen.time + ' — برای ثبت، «تأیید جابه‌جایی نوبت» را بزنید.') : '';
    }

    function rescheduleClearSlots() {
        reschedule.seq += 1;
        reschedule.slots = [];
        reschedule.slotId = null;
        reschedule.idemKey = null;
        rescheduleRenderSlots();
        rescheduleRenderSelected();
        rescheduleSync();
    }

    function rescheduleLoadSlots(preserveMessage) {
        preserveMessage = Boolean(preserveMessage);
        if (!reschedule.appointmentId || !reschedule.clinicianId) {
            return;
        }
        reschedule.seq += 1;
        var seq = reschedule.seq;
        reschedule.slots = [];
        reschedule.slotId = null;
        rescheduleRenderSlots();
        rescheduleRenderSelected();
        rescheduleSync();
        if (!preserveMessage) {
            setRescheduleState('در حال بارگذاری ساعت‌های آزاد همین موقعیت…', null);
        }
        var path = '/staff/portal/reception/slots' + (CONFIG.restRoot.indexOf('?') === -1 ? '?' : '&') +
            'clinician_id=' + encodeURIComponent(String(reschedule.clinicianId)) +
            (reschedule.date ? '&date=' + encodeURIComponent(String(reschedule.date)) : '');
        api(path).then(function (result) {
            if (seq !== reschedule.seq) {
                return;
            }
            if (!result.ok) {
                setRescheduleState(errorCodeOf(result.body) === 'CLINIC_SCOPE_REQUIRED' ? 'ابتدا موقعیت عملیاتی را انتخاب کنید.' : errorMessageOf(result.body, 'بارگذاری ساعت‌های آزاد انجام نشد.'), 'error');
                return;
            }
            var data = payloadOf(result.body);
            reschedule.locationId = data.location_id ? Number(data.location_id) : null;
            reschedule.days = Array.isArray(data.days) ? data.days : [];
            reschedule.slots = Array.isArray(data.slots) ? data.slots : [];
            reschedule.date = data.date ? String(data.date) : null;
            rescheduleRenderDays();
            rescheduleRenderSlots();
            rescheduleRenderSelected();
            if (!reschedule.locationId) {
                setRescheduleState('موقعیت عملیاتی فعالی در دسترس نیست — جابه‌جایی ممکن نیست.', 'error');
            } else if (!preserveMessage && !reschedule.slots.length) {
                setRescheduleState('برای این پزشک در این تاریخ ساعت آزادی ثبت نشده است — پزشک یا تاریخ دیگری را انتخاب کنید.', null);
            } else if (!preserveMessage) {
                setRescheduleState('یک ساعت آزاد را انتخاب کنید و سپس «تأیید جابه‌جایی نوبت» را بزنید.', null);
            }
            rescheduleSync();
        }).catch(function () {
            if (seq === reschedule.seq) {
                setRescheduleState('خطای شبکه هنگام بارگذاری ساعت‌های آزاد.', 'error');
            }
        });
    }

    function rescheduleRenderClinicians() {
        var wrap = el('sr-reschedule-clinician-wrap');
        var select = el('sr-reschedule-clinician');
        var doctor = el('sr-reschedule-doctor');
        reschedule.clinicianId = null;
        if (!reschedule.clinicians.length) {
            if (select) {
                select.innerHTML = '';
            }
            if (wrap) {
                wrap.hidden = true;
            }
            if (doctor) {
                doctor.hidden = true;
            }
            setRescheduleState('پزشکی برای این موقعیت در دسترس نیست — جابه‌جایی ممکن نیست.', 'error');
            rescheduleSync();
            return;
        }
        if (reschedule.clinicians.length === 1) {
            reschedule.clinicianId = Number(reschedule.clinicians[0].id);
            if (select) {
                select.innerHTML = '';
            }
            if (wrap) {
                wrap.hidden = true;
            }
            if (doctor) {
                doctor.textContent = 'پزشک مقصد: ' + String(reschedule.clinicians[0].name || '') + ' (تنها پزشک در دسترس این موقعیت — خودکار انتخاب شد)';
                doctor.hidden = false;
            }
            setRescheduleState('', null);
            rescheduleSync();
            rescheduleLoadSlots();
            return;
        }
        var selected = '';
        var html = '<option value="">— انتخاب پزشک —</option>';
        for (var i = 0; i < reschedule.clinicians.length; i += 1) {
            var id = Number(reschedule.clinicians[i].id);
            // پزشک فعلیِ همین نوبت اگر هنوز واجد شرایط همین موقعیت باشد، از ابتدا
            // در فهرست قابل انتخاب است (انتخاب صریح، بدون تغییر خودکار مقصد).
            if (reschedule.currentClinicianId && id === Number(reschedule.currentClinicianId)) {
                selected = String(id);
            }
            html += '<option value="' + escapeHtml(id) + '">' + escapeHtml(reschedule.clinicians[i].name) + '</option>';
        }
        if (select) {
            select.innerHTML = html;
            select.value = selected;
        }
        if (wrap) {
            wrap.hidden = false;
        }
        if (selected) {
            reschedule.clinicianId = Number(selected);
        }
        if (doctor) {
            doctor.hidden = true;
            doctor.textContent = '';
        }
        setRescheduleState(selected ? 'در حال بارگذاری ساعت‌های آزاد همین موقعیت…' : 'پزشک مقصد را انتخاب کنید تا ساعت‌های آزاد همان پزشک در همین موقعیت بارگذاری شود.', null);
        rescheduleSync();
        if (reschedule.clinicianId) {
            rescheduleLoadSlots();
        }
    }

    function rescheduleLoadClinicians() {
        if (!reschedule.appointmentId) {
            return;
        }
        reschedule.seq += 1;
        var seq = reschedule.seq;
        reschedule.clinicians = [];
        reschedule.clinicianId = null;
        reschedule.days = [];
        reschedule.date = null;
        reschedule.slots = [];
        reschedule.slotId = null;
        reschedule.done = false;
        reschedule.idemKey = null;
        var wrap = el('sr-reschedule-clinician-wrap');
        var select = el('sr-reschedule-clinician');
        var doctor = el('sr-reschedule-doctor');
        if (select) {
            select.innerHTML = '';
        }
        if (wrap) {
            wrap.hidden = true;
        }
        if (doctor) {
            doctor.hidden = true;
        }
        var dateWrap = el('sr-reschedule-date-wrap');
        if (dateWrap) {
            dateWrap.hidden = true;
        }
        rescheduleRenderSlots();
        rescheduleRenderSelected();
        setRescheduleState('در حال بارگذاری پزشکان همین موقعیت…', null);
        api('/staff/portal/reception/clinicians').then(function (result) {
            if (seq !== reschedule.seq) {
                return;
            }
            if (!result.ok) {
                setRescheduleState(errorCodeOf(result.body) === 'CLINIC_SCOPE_REQUIRED' ? 'ابتدا موقعیت عملیاتی را انتخاب کنید.' : errorMessageOf(result.body, 'بارگذاری پزشکان انجام نشد.'), 'error');
                return;
            }
            var data = payloadOf(result.body);
            reschedule.locationId = data.location_id ? Number(data.location_id) : null;
            reschedule.clinicians = Array.isArray(data.clinicians) ? data.clinicians : [];
            rescheduleContext(data.location_name || state.locationName || '');
            rescheduleRenderClinicians();
        }).catch(function () {
            if (seq === reschedule.seq) {
                setRescheduleState('خطای شبکه هنگام بارگذاری پزشکان.', 'error');
            }
        });
    }

    function rescheduleOpen(target) {
        if (state.busy || reschedule.busy) {
            return;
        }
        var id = target.getAttribute('data-appointment-id');
        if (!id) {
            return;
        }
        // فقط یک تأیید باز در هر لحظه (لغو هم بسته می‌شود).
        var wrappers = document.querySelectorAll('[data-role="sr-row-cancel"]');
        for (var i = 0; i < wrappers.length; i += 1) {
            wrappers[i].hidden = true;
        }
        var section = rescheduleSection();
        reschedule.appointmentId = Number(id);
        reschedule.patientName = String(target.getAttribute('data-patient-name') || '');
        reschedule.appointmentTime = String(target.getAttribute('data-appointment-time') || '');
        reschedule.currentClinicianId = Number(target.getAttribute('data-clinician-id') || 0) || null;
        reschedule.currentClinicianName = String(target.getAttribute('data-clinician-name') || '');
        if (section) {
            section.hidden = false;
        }
        setStatus('', null);
        setRescheduleState('', null);
        rescheduleContext('');
        rescheduleLoadClinicians();
    }

    function rescheduleAbort() {
        reschedule.seq += 1;
        var section = rescheduleSection();
        if (section) {
            section.hidden = true;
        }
        reschedule.appointmentId = null;
        reschedule.patientName = '';
        reschedule.appointmentTime = '';
        reschedule.currentClinicianId = null;
        reschedule.currentClinicianName = '';
        reschedule.locationId = null;
        reschedule.clinicians = [];
        reschedule.clinicianId = null;
        reschedule.days = [];
        reschedule.date = null;
        reschedule.slots = [];
        reschedule.slotId = null;
        reschedule.done = false;
        reschedule.idemKey = null;
        var select = el('sr-reschedule-clinician');
        if (select) {
            select.innerHTML = '';
        }
        var wrap = el('sr-reschedule-clinician-wrap');
        if (wrap) {
            wrap.hidden = true;
        }
        var doctor = el('sr-reschedule-doctor');
        if (doctor) {
            doctor.hidden = true;
            doctor.textContent = '';
        }
        var dateWrap = el('sr-reschedule-date-wrap');
        if (dateWrap) {
            dateWrap.hidden = true;
        }
        rescheduleRenderSlots();
        rescheduleRenderSelected();
        setRescheduleState('', null);
        rescheduleSync();
    }

    function rescheduleSelectSlot(button) {
        // After a successful outcome the source row is no longer a booked row:
        // the panel stays an honest terminal report until it is reopened.
        if (reschedule.busy || reschedule.done) {
            return;
        }
        var id = Number(button.getAttribute('data-slot-id') || 0);
        if (!id) {
            return;
        }
        reschedule.slotId = id;
        reschedule.done = false;
        reschedule.idemKey = null;
        var buttons = document.querySelectorAll('[data-role="sr-reschedule-slot"]');
        for (var i = 0; i < buttons.length; i += 1) {
            buttons[i].setAttribute('aria-pressed', Number(buttons[i].getAttribute('data-slot-id') || 0) === id ? 'true' : 'false');
        }
        rescheduleRenderSelected();
        setRescheduleState('', null);
        rescheduleSync();
    }

    function rescheduleSubmit() {
        if (reschedule.busy || reschedule.done || !reschedule.appointmentId || !reschedule.clinicianId || !reschedule.slotId) {
            return;
        }
        if (state.locationId && reschedule.locationId && String(reschedule.locationId) !== String(state.locationId)) {
            rescheduleLoadClinicians();
            return;
        }
        reschedule.busy = true;
        rescheduleSync();
        var seq = reschedule.seq;
        var refreshedAfterSubmit = false;
        setRescheduleState('در حال جابه‌جایی نوبت…', null);
        api('/staff/portal/reception/appointments/' + encodeURIComponent(String(reschedule.appointmentId)) + '/reschedule', {
            method: 'POST',
            headers: { 'Idempotency-Key': rescheduleIdemKey() },
            body: { clinician_id: reschedule.clinicianId, slot_id: reschedule.slotId }
        }).then(function (result) {
            if (seq !== reschedule.seq) {
                return null;
            }
            var data = payloadOf(result.body);
            var code = errorCodeOf(result.body);
            if (result.ok && data.appointment) {
                var appt = data.appointment;
                reschedule.done = true;
                reschedule.slotId = null;
                setRescheduleState('نوبت جابه‌جا شد: ' + String(appt.jalali || '') + ' ساعت ' + String(appt.time || '') + ' — کد پیگیری ' + String(appt.reference_code || '') + ' (تاییدشده). هیچ ویزیت، صف، ورود حضوری یا پرداختی ایجاد نشد.', 'ok');
            } else if (code === 'HAS_ACTIVE_VISIT') {
                setRescheduleState('این نوبت ویزیت فعال دارد و جابه‌جا نمی‌شود — وضعیت ویزیت را بررسی کنید.', 'error');
            } else if (code === 'CLINIC_INVALID_TRANSITION' || code === 'CLINIC_CANCEL_NOT_ALLOWED') {
                setRescheduleState('این وضعیت نوبت اجازهٔ جابه‌جایی نمی‌دهد؛ تخته تازه‌سازی می‌شود.', 'error');
            } else if (code === 'CLINIC_SLOT_TAKEN') {
                setRescheduleState('ظرفیت این ساعت پر شده است — فهرست ساعت‌ها تازه‌سازی شد، ساعت دیگری انتخاب کنید.', 'error');
            } else if (code === 'CLINIC_POLICY_VIOLATION') {
                setRescheduleState('این ساعت دیگر در بازهٔ مجاز جابه‌جایی نیست — فهرست ساعت‌ها تازه‌سازی شد.', 'error');
            } else if (code === 'CLINIC_DUPLICATE_IN_FLIGHT') {
                setRescheduleState('درخواست قبلی همین جابه‌جایی در حال پردازش است — دوباره ارسال نکنید.', 'error');
            } else if (code === 'CLINIC_NOT_FOUND') {
                setRescheduleState('این نوبت یا اسلات مقصد دیگر در همین موقعیت در دسترس نیست.', 'error');
            } else if (code === 'CLINIC_SCOPE_REQUIRED' || code === 'CLINIC_SCOPE_UNAVAILABLE') {
                setRescheduleState('موقعیت عملیاتی معتبر این جابه‌جایی در دسترس نیست.', 'error');
            } else {
                setRescheduleState('جابه‌جایی نوبت انجام نشد: ' + errorMessageOf(result.body, 'خطای نامشخص'), 'error');
            }
            refreshedAfterSubmit = true;
            rescheduleLoadSlots(true);
            return loadBoard(true).then(function () { if (result.ok && data.appointment) { loadUpcoming(); } });
        }).catch(function () {
            if (seq === reschedule.seq) {
                setRescheduleState('خطای شبکه هنگام جابه‌جایی نوبت — همان کلید دوباره ارسال می‌شود.', 'error');
            }
        }).then(function () {
            reschedule.busy = false;
            rescheduleSync();
            if (!refreshedAfterSubmit) {
                return loadBoard(true);
            }
            return null;
        });
    }

    (function bindReschedule() {
        var clinician = el('sr-reschedule-clinician');
        if (clinician) {
            clinician.addEventListener('change', function () {
                reschedule.clinicianId = clinician.value ? Number(clinician.value) : null;
                reschedule.date = null;
                rescheduleClearSlots();
                setRescheduleState(reschedule.clinicianId ? '' : 'انتخاب پزشک مقصد الزامی است.', null);
                if (reschedule.clinicianId) {
                    rescheduleLoadSlots();
                }
            });
        }
        var date = el('sr-reschedule-date');
        if (date) {
            date.addEventListener('change', function () {
                reschedule.date = date.value ? String(date.value) : null;
                rescheduleClearSlots();
                rescheduleLoadSlots();
            });
        }
    }());

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (target && target.getAttribute) {
            var role = target.getAttribute('data-role');
            if (role === 'sr-arrive' || role === 'sr-recover') {
                arrive(target);
            }
            if (role === 'sr-cancel-open') {
                cancelOpen(target);
            }
            if (role === 'sr-cancel-confirm') {
                cancelSubmit(target);
            }
            if (role === 'sr-cancel-abort') {
                cancelClose(target.getAttribute('data-appointment-id'));
                setStatus('', null);
            }
            if (role === 'sr-reschedule-open') {
                rescheduleOpen(target);
            }
            if (role === 'sr-reschedule-slot') {
                rescheduleSelectSlot(target);
            }
            if (role === 'sr-reschedule-confirm') {
                rescheduleSubmit();
            }
            if (role === 'sr-reschedule-abort') {
                rescheduleAbort();
            }
            if (role === 'sr-book-slot') {
                bookSelectSlot(target);
            }
        }
    });

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target && target.getAttribute && target.getAttribute('data-role') === 'sr-location-select') {
            state.locationId = Number(target.value || 0);
            state.locationName = '';
            // A Location switch invalidates the panel: the destination is ALWAYS
            // the CURRENT trusted Location — nothing switches automatically.
            rescheduleAbort();
            setStatus('در حال بارگذاری…', null);
            loadBoard(false);
            loadUpcoming();
            // Phase 11 Slice 4: a Location change invalidates the doctor list and
            // any doctor selection immediately (never carried across Locations).
            walkinReload();
            // Phase 11 Slice 5: the same rule for booking — a Location change
            // invalidates the doctor, date and slot selection immediately.
            bookReload();
        }
    });

    // ---- Phase 11 Slice 2: read-only Clinic patient search ----
    // Calls the reception adapter over the ESTABLISHED patient search contract
    // (min 2 chars, server-bounded limit, masked national ID). Debounced; a
    // stale response never overwrites a newer query. The selected result is a
    // local, presentation-only copy of the bounded search row: it is never
    // posted anywhere and is not coupled to the arrival action.
    var SEARCH_MIN = 2;
    var SEARCH_DEBOUNCE_MS = 350;
    var SEARCH_LIMIT_HINT = 25;
    var SEARCH_IDLE = 'برای جستجو دست‌کم ۲ نویسه وارد کنید.';
    var search = { timer: null, seq: 0, lastQuery: '', results: [], selected: null, created: null };
    var createBusy = false;

    function faDigits(value) {
        return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(Number(d)); });
    }

    function setSearchState(text, isError) {
        var node = el('sr-search-state');
        if (!node) {
            return;
        }
        node.textContent = text || '';
        node.className = 'cpms-sr-search-state' + (isError ? ' cpms-sr-search-state--error' : '');
    }

    function patientLabel(p) {
        var name = String((p.first_name || '') + ' ' + (p.last_name || '')).trim();
        return name || ('بیمار ' + p.id);
    }

    function patientMeta(p) {
        var meta = [];
        if (p.mrn) {
            meta.push('پرونده: <bdi>' + escapeHtml(p.mrn) + '</bdi>');
        }
        if (p.mobile) {
            meta.push('موبایل: <bdi>' + escapeHtml(p.mobile) + '</bdi>');
        }
        if (p.national_id) {
            meta.push('کد ملی: <bdi>' + escapeHtml(p.national_id) + '</bdi>');
        }
        return meta.join(' · ');
    }

    function syncSearchPressed() {
        var buttons = document.querySelectorAll('[data-role="sr-search-result"]');
        var selectedId = search.selected ? String(search.selected.id) : '';
        for (var i = 0; i < buttons.length; i += 1) {
            buttons[i].setAttribute('aria-pressed', buttons[i].getAttribute('data-patient-id') === selectedId ? 'true' : 'false');
        }
    }

    function renderSearchResults() {
        var list = el('sr-search-results');
        if (!list) {
            return;
        }
        var html = '';
        for (var i = 0; i < search.results.length; i += 1) {
            var p = search.results[i];
            html += '<li><button type="button" class="cpms-sr-search-result" data-role="sr-search-result" data-patient-id="' + escapeHtml(p.id) + '" aria-pressed="false">' +
                '<span class="cpms-sr-name" data-role="sr-search-result-name">' + escapeHtml(patientLabel(p)) + '</span>' +
                '<span class="cpms-sr-search-meta">' + patientMeta(p) + '</span>' +
                '</button></li>';
        }
        list.innerHTML = html;
        syncSearchPressed();
    }

    function setSelectedNote(created) {
        var note = el('sr-search-selected-note');
        if (!note) {
            return;
        }
        if (created) {
            note.textContent = 'بیمار با موفقیت ثبت شد — فقط برای شناسایی؛ هیچ نوبت یا ویزیتی ایجاد نشد.';
            note.className = 'cpms-sr-search-selected-note cpms-sr-search-selected-note--ok';
        } else {
            note.textContent = 'فقط برای شناسایی — هیچ تغییری در پرونده یا نوبت ایجاد نمی‌شود.';
            note.className = 'cpms-sr-search-selected-note';
        }
    }

    function renderSelected() {
        var box = el('sr-search-selected');
        var text = el('sr-search-selected-text');
        if (!box || !text) {
            return;
        }
        if (!search.selected) {
            text.innerHTML = '';
            box.hidden = true;
            setSelectedNote(false);
            walkinOnPatient();
            bookOnPatient();
            return;
        }
        text.innerHTML = '<span class="cpms-sr-name">' + escapeHtml(patientLabel(search.selected)) + '</span> <span class="cpms-sr-search-meta">' + patientMeta(search.selected) + '</span>';
        box.hidden = false;
        setSelectedNote(Boolean(search.created && search.selected && String(search.created) === String(search.selected.id)));
        walkinOnPatient();
        bookOnPatient();
    }

    function runSearch(force) {
        var input = el('sr-search-input');
        var q = input ? String(input.value || '').trim() : '';
        if (search.timer) {
            window.clearTimeout(search.timer);
            search.timer = null;
        }
        if (q.length < SEARCH_MIN) {
            search.seq += 1;
            search.lastQuery = '';
            search.results = [];
            renderSearchResults();
            setSearchState(SEARCH_IDLE, false);
            return;
        }
        if (!force && q === search.lastQuery) {
            return;
        }
        search.lastQuery = q;
        search.seq += 1;
        var seq = search.seq;
        setSearchState('در حال جستجو…', false);
        var path = '/staff/portal/reception/patients/search' + (CONFIG.restRoot.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q);
        api(path).then(function (result) {
            if (seq !== search.seq) {
                return;
            }
            if (!result.ok) {
                search.lastQuery = '';
                search.results = [];
                renderSearchResults();
                if (result.status === 403) {
                    setSearchState('دسترسی جستجوی بیمار برای شما فعال نیست.', true);
                } else {
                    setSearchState('جستجو انجام نشد: ' + errorMessageOf(result.body, ''), true);
                }
                return;
            }
            var rows = (result.body && Array.isArray(result.body.data)) ? result.body.data : [];
            search.results = rows;
            renderSearchResults();
            if (rows.length === 0) {
                setSearchState('بیماری با این مشخصات در این کلینیک یافت نشد. می‌توانید بیمار تازه را ثبت کنید.', false);
                var createOpenMiss = el('sr-create-open');
                if (createOpenMiss && el('sr-create') && el('sr-create').hidden) {
                    createOpenMiss.hidden = false;
                }
            } else if (rows.length >= SEARCH_LIMIT_HINT) {
                setSearchState(faDigits(rows.length) + ' نتیجهٔ نخست نمایش داده شد؛ برای یافتن دقیق‌تر، عبارت کامل‌تری وارد کنید.', false);
            } else {
                setSearchState(faDigits(rows.length) + ' بیمار یافت شد. برای شناسایی، روی بیمار موردنظر بزنید.', false);
            }
        }).catch(function () {
            if (seq !== search.seq) {
                return;
            }
            search.lastQuery = '';
            setSearchState('خطای شبکه هنگام جستجو.', true);
        });
    }

    function selectSearchResult(button) {
        var id = String(button.getAttribute('data-patient-id') || '');
        for (var i = 0; i < search.results.length; i += 1) {
            if (String(search.results[i].id) === id) {
                search.selected = search.results[i];
                break;
            }
        }
        syncSearchPressed();
        renderSelected();
    }

    (function bindSearch() {
        var panel = el('sr-search');
        var form = el('sr-search-form');
        var input = el('sr-search-input');
        if (!panel || !form || !input) {
            return;
        }
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            runSearch(true);
        });
        input.addEventListener('input', function () {
            if (search.timer) {
                window.clearTimeout(search.timer);
            }
            if (String(input.value || '').trim().length < SEARCH_MIN) {
                runSearch(false);
                return;
            }
            search.timer = window.setTimeout(function () {
                search.timer = null;
                runSearch(false);
            }, SEARCH_DEBOUNCE_MS);
        });
        panel.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }
            var result = target.closest('[data-role="sr-search-result"]');
            if (result) {
                selectSearchResult(result);
                return;
            }
            if (target.closest('[data-role="sr-search-clear"]')) {
                search.selected = null;
                syncSearchPressed();
                renderSelected();
                input.focus();
                return;
            }
            if (target.closest('[data-role="sr-create-open"]')) {
                openCreate();
                return;
            }
            if (target.closest('[data-role="sr-create-cancel"]')) {
                closeCreate(false);
            }
        });
        var createForm = el('sr-create');
        if (createForm) {
            createForm.addEventListener('submit', submitCreate);
        }
        var createOpen = el('sr-create-open');
        if (createOpen) {
            createOpen.hidden = false;
        }
    }());

    function setCreateError(text) {
        var node = el('sr-create-error');
        if (!node) {
            return;
        }
        node.textContent = text || '';
        node.hidden = !text;
    }

    function openCreate() {
        var form = el('sr-create');
        if (!form) {
            return;
        }
        form.hidden = false;
        var open = el('sr-create-open');
        if (open) {
            open.hidden = true;
        }
        setCreateError('');
        var first = el('sr-create-first-name');
        if (first) {
            first.focus();
        }
    }

    function closeCreate(reset) {
        var form = el('sr-create');
        if (form) {
            form.hidden = true;
            if (reset) {
                form.reset();
            }
        }
        var open = el('sr-create-open');
        if (open) {
            open.hidden = false;
        }
        setCreateError('');
    }

    function submitCreate(event) {
        event.preventDefault();
        if (createBusy) {
            return;
        }
        var firstNode = el('sr-create-first-name');
        var lastNode = el('sr-create-last-name');
        var mobileNode = el('sr-create-mobile');
        var first = firstNode ? String(firstNode.value || '').trim() : '';
        var last = lastNode ? String(lastNode.value || '').trim() : '';
        var mobile = mobileNode ? String(mobileNode.value || '').trim() : '';
        if (!first || !last || !mobile) {
            setCreateError('نام، نام خانوادگی و موبایل الزامی است.');
            return;
        }
        var body = { first_name: first, last_name: last, mobile: mobile };
        var nidNode = el('sr-create-national-id');
        var birthNode = el('sr-create-birth-date');
        var genderNode = el('sr-create-gender');
        var nid = nidNode ? String(nidNode.value || '').trim() : '';
        var birth = birthNode ? String(birthNode.value || '').trim() : '';
        var gender = genderNode ? String(genderNode.value || '').trim() : '';
        if (nid) {
            body.national_id = nid;
        }
        if (birth) {
            body.birth_date = birth;
        }
        if (gender) {
            body.gender = gender;
        }
        createBusy = true;
        var submit = el('sr-create-submit');
        if (submit) {
            submit.disabled = true;
        }
        setCreateError('');
        api('/staff/portal/reception/patients', { method: 'POST', body: body }).then(function (result) {
            createBusy = false;
            if (submit) {
                submit.disabled = false;
            }
            if (!result.ok) {
                setCreateError(errorMessageOf(result.body, 'ثبت بیمار انجام نشد.'));
                return;
            }
            var row = payloadOf(result.body);
            if (!row || !row.id) {
                setCreateError('ثبت بیمار انجام نشد.');
                return;
            }
            search.created = row.id;
            search.selected = row;
            syncSearchPressed();
            renderSelected();
            closeCreate(true);
            setSearchState('بیمار با موفقیت ثبت شد.', false);
        }).catch(function () {
            createBusy = false;
            if (submit) {
                submit.disabled = false;
            }
            setCreateError('خطای شبکه هنگام ثبت بیمار.');
        });
    }

    // ---- Phase 11 Slice 4: walk-in for the selected Clinic patient ----
    // Doctor options come from the bounded reception adapter for the trusted
    // Clinic + current operational Location (0 ⇒ disabled, 1 ⇒ shown and
    // auto-selected, N ⇒ explicit choice, never a first-row fallback). The
    // explicit submit posts ONLY patient_id + clinician_id; the server owns
    // Clinic/Location/eligibility and the existing walk-in → enqueue path.
    // A recoverable partial is completed by re-posting the same selectors —
    // the server derives the existing walk-in Visit itself.
    var walkin = { patientId: null, seq: 0, locationId: null, clinicians: [], selectedId: null, busy: false, done: false };

    function setWalkinState(text, kind) {
        var node = el('sr-walkin-state');
        if (!node) {
            return;
        }
        node.textContent = text || '';
        node.className = 'cpms-sr-walkin-state' + (kind === 'error' ? ' cpms-sr-walkin-state--error' : (kind === 'ok' ? ' cpms-sr-walkin-state--ok' : ''));
    }

    function walkinSync() {
        var submit = el('sr-walkin-submit');
        if (submit) {
            submit.disabled = walkin.busy || walkin.done || !walkin.patientId || !walkin.selectedId ||
                !walkin.locationId || String(walkin.locationId) !== String(state.locationId || walkin.locationId);
        }
        var recover = el('sr-walkin-recover');
        if (recover) {
            recover.disabled = walkin.busy;
        }
    }

    function walkinShowRecover(show) {
        var recover = el('sr-walkin-recover');
        var submit = el('sr-walkin-submit');
        if (recover) {
            recover.hidden = !show;
        }
        if (submit) {
            submit.hidden = Boolean(show);
        }
    }

    function walkinClear() {
        walkin.seq += 1;
        walkin.locationId = null;
        walkin.clinicians = [];
        walkin.selectedId = null;
        walkin.done = false;
        walkinShowRecover(false);
        var wrap = el('sr-walkin-clinician-wrap');
        var select = el('sr-walkin-clinician');
        var doctor = el('sr-walkin-doctor');
        if (select) {
            select.innerHTML = '';
        }
        if (wrap) {
            wrap.hidden = true;
        }
        if (doctor) {
            doctor.hidden = true;
            doctor.textContent = '';
        }
        var loc = el('sr-walkin-location');
        if (loc) {
            loc.textContent = '—';
        }
        walkinSync();
    }

    function walkinRender(data) {
        walkin.locationId = data.location_id ? Number(data.location_id) : null;
        walkin.clinicians = Array.isArray(data.clinicians) ? data.clinicians : [];
        walkin.selectedId = null;
        var loc = el('sr-walkin-location');
        if (loc) {
            loc.textContent = String(data.location_name || state.locationName || '—');
        }
        var wrap = el('sr-walkin-clinician-wrap');
        var select = el('sr-walkin-clinician');
        var doctor = el('sr-walkin-doctor');
        if (!walkin.locationId) {
            setWalkinState('موقعیت عملیاتی فعالی در دسترس نیست — ورود حضوری ممکن نیست.', 'error');
        } else if (walkin.clinicians.length === 0) {
            setWalkinState('پزشکی برای این موقعیت در دسترس نیست — ورود حضوری ممکن نیست.', 'error');
        } else if (walkin.clinicians.length === 1) {
            walkin.selectedId = Number(walkin.clinicians[0].id);
            if (doctor) {
                doctor.textContent = 'پزشک: ' + String(walkin.clinicians[0].name || '') + ' (تنها پزشک در دسترس این موقعیت — خودکار انتخاب شد)';
                doctor.hidden = false;
            }
            setWalkinState('', null);
        } else {
            if (select) {
                var html = '<option value="">— انتخاب پزشک —</option>';
                for (var i = 0; i < walkin.clinicians.length; i += 1) {
                    html += '<option value="' + escapeHtml(walkin.clinicians[i].id) + '">' + escapeHtml(walkin.clinicians[i].name) + '</option>';
                }
                select.innerHTML = html;
                select.value = '';
            }
            if (wrap) {
                wrap.hidden = false;
            }
            setWalkinState('انتخاب پزشک الزامی است.', null);
        }
        walkinSync();
    }

    function walkinReload() {
        walkinClear();
        var section = el('sr-walkin');
        if (!walkin.patientId) {
            if (section) {
                section.hidden = true;
            }
            setWalkinState('', null);
            return;
        }
        if (section) {
            section.hidden = false;
        }
        if (state.locations.length > 1 && !state.locationId) {
            setWalkinState('ابتدا موقعیت عملیاتی را انتخاب کنید.', 'error');
            return;
        }
        var seq = walkin.seq;
        setWalkinState('در حال بارگذاری پزشکان…', null);
        api('/staff/portal/reception/clinicians').then(function (result) {
            if (seq !== walkin.seq) {
                return;
            }
            if (!result.ok) {
                setWalkinState(errorCodeOf(result.body) === 'CLINIC_SCOPE_REQUIRED' ? 'ابتدا موقعیت عملیاتی را انتخاب کنید.' : errorMessageOf(result.body, 'بارگذاری پزشکان انجام نشد.'), 'error');
                return;
            }
            walkinRender(payloadOf(result.body));
        }).catch(function () {
            if (seq === walkin.seq) {
                setWalkinState('خطای شبکه هنگام بارگذاری پزشکان.', 'error');
            }
        });
    }

    function walkinOnPatient() {
        var id = search.selected ? Number(search.selected.id) : null;
        if (id === walkin.patientId) {
            return;
        }
        walkin.patientId = id;
        walkinReload();
    }

    function walkinSubmit(isRecovery) {
        if (walkin.busy || !walkin.patientId || !walkin.selectedId || !walkin.locationId) {
            return;
        }
        if (state.locationId && String(walkin.locationId) !== String(state.locationId)) {
            // Stale list from a previous Location: never submit it.
            walkinReload();
            return;
        }
        walkin.busy = true;
        walkinSync();
        var seq = walkin.seq;
        setWalkinState(isRecovery ? 'در حال تکمیل ورود به صف…' : 'در حال ثبت ورود حضوری…', null);
        api('/staff/portal/reception/walk-ins', { method: 'POST', body: { patient_id: walkin.patientId, clinician_id: walkin.selectedId } })
            .then(function (result) {
                if (seq !== walkin.seq) {
                    return null;
                }
                var data = payloadOf(result.body);
                var info = data.walk_in || {};
                var code = errorCodeOf(result.body);
                if (result.ok && info.complete === true) {
                    var visit = data.visit || {};
                    walkin.done = true;
                    walkinShowRecover(false);
                    setWalkinState('ورود حضوری ثبت شد و بیمار در صف انتظار ' + String(visit.clinician_name || 'پزشک') + ' قرار گرفت.', 'ok');
                } else if (code === 'CLINIC_WALK_IN_INCOMPLETE' || info.complete === false) {
                    walkinShowRecover(true);
                    setWalkinState('ورود حضوری ثبت شد، اما قرارگیری در صف انجام نشد — برای تکمیل، «تکمیل ورود به صف» را بزنید.', 'error');
                } else if (code === 'CLINIC_DUPLICATE_ACTIVE_VISIT') {
                    walkinShowRecover(false);
                    walkin.done = true;
                    var existing = data.visit_status ? ' (وضعیت فعلی: ' + statusLabel(String(data.visit_status)) + ')' : '';
                    setWalkinState('این بیمار امروز نزد همین پزشک مراجعهٔ فعال دارد' + existing + ' — مراجعهٔ تازه ثبت نشد.', 'error');
                } else if (code === 'CLINIC_SCOPE_REQUIRED') {
                    setWalkinState('ابتدا موقعیت عملیاتی را انتخاب کنید.', 'error');
                } else {
                    setWalkinState((isRecovery ? 'تکمیل ورود به صف انجام نشد: ' : 'ثبت ورود حضوری انجام نشد: ') + errorMessageOf(result.body, 'خطای نامشخص'), 'error');
                }
                return loadBoard(true);
            })
            .catch(function () {
                if (seq === walkin.seq) {
                    setWalkinState('خطای شبکه هنگام ثبت ورود حضوری.', 'error');
                }
            })
            .then(function () {
                walkin.busy = false;
                walkinSync();
            });
    }

    (function bindWalkin() {
        var select = el('sr-walkin-clinician');
        if (select) {
            select.addEventListener('change', function () {
                walkin.selectedId = select.value ? Number(select.value) : null;
                walkin.done = false;
                walkinShowRecover(false);
                setWalkinState(walkin.selectedId ? '' : 'انتخاب پزشک الزامی است.', null);
                walkinSync();
            });
        }
        var submit = el('sr-walkin-submit');
        if (submit) {
            submit.addEventListener('click', function () {
                walkinSubmit(false);
            });
        }
        var recover = el('sr-walkin-recover');
        if (recover) {
            recover.addEventListener('click', function () {
                walkinSubmit(true);
            });
        }
    }());

    // ---- Phase 11 Slice 5: create an appointment from an explicitly selected slot ----
    // The patient is already selected (Slice 2/3) and the Location is already
    // trusted (Slice 1 policy). This module adds ONE bounded slot read per
    // selection change (doctor or date) — never a per-slot request, never a
    // poll. The server owns the operational day (Location IANA timezone), the
    // Jalali presentation and the availability formula; slot_id is the only
    // booking authority and no free-form date/time is ever sent. Booking itself
    // is the existing staff-create service: no Visit, no queue, no check-in, no
    // walk-in, no payment.
    var book = { patientId: null, seq: 0, locationId: null, clinicians: [], clinicianId: null, days: [], date: null, slots: [], slotId: null, busy: false, done: false };

    function setBookState(text, kind) {
        var node = el('sr-book-state');
        if (!node) {
            return;
        }
        node.textContent = text || '';
        node.className = 'cpms-sr-book-state' + (kind === 'error' ? ' cpms-sr-book-state--error' : (kind === 'ok' ? ' cpms-sr-book-state--ok' : ''));
    }

    function bookSync() {
        var submit = el('sr-book-submit');
        if (submit) {
            submit.disabled = book.busy || book.done || !book.patientId || !book.clinicianId || !book.slotId ||
                !book.locationId || String(book.locationId) !== String(state.locationId || book.locationId);
        }
        var buttons = document.querySelectorAll('[data-role="sr-book-slot"]');
        for (var i = 0; i < buttons.length; i += 1) {
            buttons[i].disabled = book.busy;
        }
        var clinician = el('sr-book-clinician');
        var date = el('sr-book-date');
        var reason = el('sr-book-reason');
        var location = el('sr-location-select');
        if (clinician) {
            clinician.disabled = book.busy;
        }
        if (date) {
            date.disabled = book.busy;
        }
        if (reason) {
            reason.disabled = book.busy;
        }
        if (location) {
            location.disabled = book.busy;
        }
        var patientControls = document.querySelectorAll('[data-role="sr-search-result"], [data-role="sr-search-clear"], [data-role="sr-search-submit"], [data-role="sr-search-input"]');
        for (var j = 0; j < patientControls.length; j += 1) {
            patientControls[j].disabled = book.busy;
        }
    }

    function bookRenderSlots() {
        var list = el('sr-book-slots');
        if (!list) {
            return;
        }
        if (!book.slots.length) {
            list.innerHTML = '<p class="cpms-sr-book-empty" data-role="sr-book-slots-empty">برای این پزشک در این تاریخ ساعت آزادی ثبت نشده است.</p>';
            return;
        }
        var html = '';
        for (var i = 0; i < book.slots.length; i += 1) {
            var slot = book.slots[i];
            var pressed = book.slotId && String(book.slotId) === String(slot.slot_id) ? 'true' : 'false';
            html += '<button type="button" class="cpms-sr-book-slot" data-role="sr-book-slot" data-slot-id="' + escapeHtml(slot.slot_id) + '" aria-pressed="' + pressed + '">' +
                escapeHtml(slot.time) +
                '<span class="cpms-sr-book-slot-cap">ظرفیت باقی‌مانده ' + faDigits(slot.capacity_left) + ' · ' + faDigits(slot.duration_min) + ' دقیقه</span>' +
                '</button>';
        }
        list.innerHTML = html;
    }

    function bookRenderDays() {
        var select = el('sr-book-date');
        var wrap = el('sr-book-date-wrap');
        if (!select || !wrap) {
            return;
        }
        if (!book.days.length) {
            select.innerHTML = '';
            wrap.hidden = true;
            return;
        }
        var html = '';
        for (var i = 0; i < book.days.length; i += 1) {
            var day = book.days[i];
            html += '<option value="' + escapeHtml(day.date) + '"' + (String(day.date) === String(book.date) ? ' selected' : '') + '>' + escapeHtml(day.jalali) + '</option>';
        }
        select.innerHTML = html;
        select.value = String(book.date || book.days[0].date);
        wrap.hidden = false;
    }

    function bookClearSlots() {
        book.seq += 1;
        book.slots = [];
        book.slotId = null;
        bookRenderSlots();
        bookSync();
    }

    function bookClear() {
        book.seq += 1;
        book.locationId = null;
        book.clinicians = [];
        book.clinicianId = null;
        book.days = [];
        book.date = null;
        book.slots = [];
        book.slotId = null;
        book.done = false;
        var clinicianWrap = el('sr-book-clinician-wrap');
        var clinicianSelect = el('sr-book-clinician');
        var doctor = el('sr-book-doctor');
        var dateWrap = el('sr-book-date-wrap');
        var dateSelect = el('sr-book-date');
        var reason = el('sr-book-reason');
        var location = el('sr-book-location');
        if (clinicianSelect) {
            clinicianSelect.innerHTML = '';
        }
        if (clinicianWrap) {
            clinicianWrap.hidden = true;
        }
        if (doctor) {
            doctor.hidden = true;
            doctor.textContent = '';
        }
        if (dateSelect) {
            dateSelect.innerHTML = '';
        }
        if (dateWrap) {
            dateWrap.hidden = true;
        }
        if (reason) {
            reason.value = '';
        }
        if (location) {
            location.textContent = '—';
        }
        bookRenderSlots();
        bookSync();
    }

    function bookLoadSlots(preserveMessage) {
        preserveMessage = Boolean(preserveMessage);
        if (!book.patientId || !book.clinicianId) {
            return;
        }
        book.seq += 1;
        var seq = book.seq;
        book.slots = [];
        book.slotId = null;
        bookRenderSlots();
        bookSync();
        if (!preserveMessage) {
            setBookState('در حال بارگذاری ساعت‌های آزاد…', null);
        }
        var path = '/staff/portal/reception/slots' + (CONFIG.restRoot.indexOf('?') === -1 ? '?' : '&') +
            'clinician_id=' + encodeURIComponent(String(book.clinicianId)) +
            (book.date ? '&date=' + encodeURIComponent(String(book.date)) : '');
        api(path).then(function (result) {
            if (seq !== book.seq) {
                return;
            }
            if (!result.ok) {
                var code = errorCodeOf(result.body);
                setBookState(code === 'CLINIC_SCOPE_REQUIRED' ? 'ابتدا موقعیت عملیاتی را انتخاب کنید.' : errorMessageOf(result.body, 'بارگذاری ساعت‌های آزاد انجام نشد.'), 'error');
                return;
            }
            var data = payloadOf(result.body);
            book.locationId = data.location_id ? Number(data.location_id) : null;
            book.days = Array.isArray(data.days) ? data.days : [];
            book.slots = Array.isArray(data.slots) ? data.slots : [];
            book.date = data.date ? String(data.date) : null;
            bookRenderDays();
            bookRenderSlots();
            if (!book.locationId) {
                setBookState('موقعیت عملیاتی فعالی در دسترس نیست — ثبت نوبت ممکن نیست.', 'error');
            } else if (!preserveMessage && !book.slots.length) {
                setBookState('برای این پزشک در این تاریخ ساعت آزادی ثبت نشده است — تاریخ یا پزشک دیگری را انتخاب کنید.', null);
            } else if (!preserveMessage) {
                setBookState('یک ساعت آزاد را انتخاب کنید و سپس «ثبت نوبت» را بزنید.', null);
            }
            bookSync();
        }).catch(function () {
            if (seq === book.seq) {
                setBookState('خطای شبکه هنگام بارگذاری ساعت‌های آزاد.', 'error');
            }
        });
    }

    function bookRenderClinicians(data) {
        book.locationId = data.location_id ? Number(data.location_id) : null;
        book.clinicians = Array.isArray(data.clinicians) ? data.clinicians : [];
        book.clinicianId = null;
        var location = el('sr-book-location');
        if (location) {
            location.textContent = String(data.location_name || state.locationName || '—');
        }
        var wrap = el('sr-book-clinician-wrap');
        var select = el('sr-book-clinician');
        var doctor = el('sr-book-doctor');
        if (!book.locationId) {
            setBookState('موقعیت عملیاتی فعالی در دسترس نیست — ثبت نوبت ممکن نیست.', 'error');
        } else if (!book.clinicians.length) {
            setBookState('پزشکی برای این موقعیت در دسترس نیست — ثبت نوبت ممکن نیست.', 'error');
        } else if (book.clinicians.length === 1) {
            book.clinicianId = Number(book.clinicians[0].id);
            if (select) {
                select.innerHTML = '';
            }
            if (wrap) {
                wrap.hidden = true;
            }
            if (doctor) {
                doctor.textContent = 'پزشک: ' + String(book.clinicians[0].name || '') + ' (تنها پزشک در دسترس این موقعیت — خودکار انتخاب شد)';
                doctor.hidden = false;
            }
            setBookState('', null);
            bookSync();
            bookLoadSlots();
            return;
        } else {
            if (select) {
                var html = '<option value="">— انتخاب پزشک —</option>';
                for (var i = 0; i < book.clinicians.length; i += 1) {
                    html += '<option value="' + escapeHtml(book.clinicians[i].id) + '">' + escapeHtml(book.clinicians[i].name) + '</option>';
                }
                select.innerHTML = html;
                select.value = '';
            }
            if (wrap) {
                wrap.hidden = false;
            }
            if (doctor) {
                doctor.hidden = true;
                doctor.textContent = '';
            }
            setBookState('پزشک را انتخاب کنید تا ساعت‌های آزاد همان پزشک بارگذاری شود.', null);
        }
        bookSync();
    }

    function bookReload() {
        bookClear();
        var section = el('sr-book');
        if (!book.patientId) {
            if (section) {
                section.hidden = true;
            }
            setBookState('', null);
            return;
        }
        if (section) {
            section.hidden = false;
        }
        if (state.locations.length > 1 && !state.locationId) {
            setBookState('ابتدا موقعیت عملیاتی را انتخاب کنید.', 'error');
            return;
        }
        book.seq += 1;
        var seq = book.seq;
        setBookState('در حال بارگذاری پزشکان…', null);
        api('/staff/portal/reception/clinicians').then(function (result) {
            if (seq !== book.seq) {
                return;
            }
            if (!result.ok) {
                setBookState(errorCodeOf(result.body) === 'CLINIC_SCOPE_REQUIRED' ? 'ابتدا موقعیت عملیاتی را انتخاب کنید.' : errorMessageOf(result.body, 'بارگذاری پزشکان انجام نشد.'), 'error');
                return;
            }
            bookRenderClinicians(payloadOf(result.body));
        }).catch(function () {
            if (seq === book.seq) {
                setBookState('خطای شبکه هنگام بارگذاری پزشکان.', 'error');
            }
        });
    }

    function bookOnPatient() {
        if (book.busy) {
            return;
        }
        var id = search.selected ? Number(search.selected.id) : null;
        if (id === book.patientId) {
            return;
        }
        book.patientId = id;
        bookReload();
    }

    function bookSelectSlot(button) {
        if (book.busy) {
            return;
        }
        var id = Number(button.getAttribute('data-slot-id') || 0);
        if (!id) {
            return;
        }
        book.slotId = id;
        book.done = false;
        var buttons = document.querySelectorAll('[data-role="sr-book-slot"]');
        var chosen = null;
        for (var i = 0; i < buttons.length; i += 1) {
            buttons[i].setAttribute('aria-pressed', Number(buttons[i].getAttribute('data-slot-id') || 0) === id ? 'true' : 'false');
        }
        for (var j = 0; j < book.slots.length; j += 1) {
            if (Number(book.slots[j].slot_id) === id) {
                chosen = book.slots[j];
            }
        }
        setBookState(chosen ? ('ساعت ' + String(chosen.time) + ' انتخاب شد — برای ثبت، «ثبت نوبت» را بزنید.') : '', null);
        bookSync();
    }

    function bookSubmit() {
        if (book.busy || !book.patientId || !book.clinicianId || !book.slotId || !book.locationId) {
            return;
        }
        if (state.locationId && String(book.locationId) !== String(state.locationId)) {
            // Stale slot context from a previous Location: never submit it.
            bookReload();
            return;
        }
        book.busy = true;
        bookSync();
        var seq = book.seq;
        var refreshedAfterSubmit = false;
        var reasonNode = el('sr-book-reason');
        var reason = reasonNode ? String(reasonNode.value || '').trim() : '';
        setBookState('در حال ثبت نوبت…', null);
        api('/staff/portal/reception/appointments', { method: 'POST', body: { patient_id: book.patientId, clinician_id: book.clinicianId, slot_id: book.slotId, reason: reason } })
            .then(function (result) {
                if (seq !== book.seq) {
                    return null;
                }
                var data = payloadOf(result.body);
                var code = errorCodeOf(result.body);
                if (result.ok && data.appointment) {
                    var appt = data.appointment;
                    book.done = true;
                    book.slotId = null;
                    setBookState('نوبت ثبت شد: ' + String(appt.jalali || '') + ' ساعت ' + String(appt.time || '') + ' — کد پیگیری ' + String(appt.reference_code || '') + ' (تاییدشده). هیچ ویزیت یا صفی ایجاد نشد.', 'ok');
                } else if (code === 'CLINIC_DUPLICATE_APPOINTMENT') {
                    setBookState('این بیمار در همین ساعت نوبت فعال دارد — نوبت تکراری ثبت نشد.', 'error');
                } else if (code === 'CLINIC_SLOT_TAKEN') {
                    setBookState('ظرفیت این ساعت پر شده است — فهرست تازه‌سازی شد، ساعت دیگری انتخاب کنید.', 'error');
                } else if (code === 'CLINIC_POLICY_VIOLATION') {
                    setBookState('این ساعت دیگر در بازهٔ مجاز رزرو نیست — فهرست ساعت‌ها تازه‌سازی شد.', 'error');
                } else if (code === 'CLINIC_NOT_FOUND') {
                    setBookState('این ساعت دیگر در دسترس نیست — فهرست ساعت‌ها تازه‌سازی شد.', 'error');
                } else if (code === 'CLINIC_SCOPE_REQUIRED') {
                    setBookState('ابتدا موقعیت عملیاتی را انتخاب کنید.', 'error');
                } else {
                    setBookState('ثبت نوبت انجام نشد: ' + errorMessageOf(result.body, 'خطای نامشخص'), 'error');
                }
                // Start the bounded availability refresh immediately after the
                // durable POST response: clear the now-stale slot options before
                // yielding to the board refresh. The explicit response message
                // remains visible while the fresh slot list is fetched.
                refreshedAfterSubmit = true;
                bookLoadSlots(true);
                return loadBoard(true).then(function () { if (result.ok && data.appointment) { loadUpcoming(); } });
            })
            .catch(function () {
                if (seq === book.seq) {
                    setBookState('خطای شبکه هنگام ثبت نوبت.', 'error');
                }
            })
            .then(function () {
                book.busy = false;
                book.done = false;
                if (!refreshedAfterSubmit) {
                    // Network failure: no response confirmed the mutation, but
                    // the displayed availability is still re-read honestly.
                    bookLoadSlots(true);
                } else {
                    bookSync();
                }
            });
    }

    (function bindBook() {
        var clinician = el('sr-book-clinician');
        if (clinician) {
            clinician.addEventListener('change', function () {
                book.clinicianId = clinician.value ? Number(clinician.value) : null;
                bookClearSlots();
                setBookState(book.clinicianId ? '' : 'انتخاب پزشک الزامی است.', null);
                if (book.clinicianId) {
                    bookLoadSlots();
                }
            });
        }
        var date = el('sr-book-date');
        if (date) {
            date.addEventListener('change', function () {
                book.date = date.value ? String(date.value) : null;
                bookClearSlots();
                bookLoadSlots();
            });
        }
        var submit = el('sr-book-submit');
        if (submit) {
            submit.addEventListener('click', function () {
                bookSubmit();
            });
        }
    }());

    function poll() {
        loadBoard(true).then(function () {
            window.setTimeout(poll, POLL_MS);
        }, function () {
            window.setTimeout(poll, POLL_MS);
        });
    }

    setStatus('در حال بارگذاری…', null);
    loadContext().then(function () {
        return loadBoard(false).then(loadUpcoming);
    }).then(poll);
}());
</script>
