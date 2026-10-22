<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Domain\Clinical\MedicalSpecialtyCatalog;

/**
 * Renders the offline, searchable clinical-specialty control for Classic forms.
 *
 * Invariants (same discipline as `AddressSelectorField`):
 *  - Offline only: `MedicalSpecialtyCatalog` ships inside the release ZIP; no network call.
 *  - No silent default: an unknown/empty stored value selects nothing and never becomes
 *    "پزشک عمومی".
 *  - Canonical vs user-defined: a stored value that matches the catalogue is re-selected in the
 *    `<select>`; anything else is re-displayed in the manual "Other / سایر" field, so historical
 *    free-text specialties keep their exact meaning instead of being dropped or coerced.
 *  - Label-only: nothing here creates Specialty master data (Phase 4 / drift M-01) and no code
 *    system is invented. The persisted value stays a single human-readable label.
 *  - Server-side authority: the `<select>` and the manual input are re-validated by
 *    `MedicalSpecialtyCatalog::resolve_submitted()`; JavaScript is a filter, never a gate.
 *  - Progressive enhancement + RTL: the search box and the hide/show behaviour need script, the
 *    submission path does not; the manual input is rendered visible so a no-JS form still works.
 */
final class SpecialtySelectorField {

    /**
     * @param ?string $current_specialty Persisted (or stale) value to re-display.
     * @param string  $id_prefix         Deterministic unique prefix (no duplicate HTML ids).
     * @param string  $select_name       Submitted name of the catalogue control.
     * @param string  $custom_name       Submitted name of the manual ("Other") control.
     */
    public static function render(
        ?string $current_specialty,
        string $id_prefix,
        string $select_name = 'specialty',
        string $custom_name = 'specialty_custom'
    ): void {
        $classified   = MedicalSpecialtyCatalog::classify( $current_specialty );
        $selected     = $classified['custom'] ? '' : $classified['label'];
        $custom_value = $classified['custom'] ? $classified['label'] : '';
        $select_id    = $id_prefix . '_specialty';
        $custom_id    = $id_prefix . '_specialty_custom';
        $search_id    = $id_prefix . '_specialty_search';

        echo '<div class="cpms-specialty-field" dir="rtl" data-cpms-specialty-field="1"'
            . ' data-cpms-other-sentinel="' . esc_attr( MedicalSpecialtyCatalog::OTHER_SPECIALTY_SENTINEL ) . '"'
            . ' style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:6px;max-width:25em;box-sizing:border-box;">';

        echo '<div style="flex:1 1 11em;min-width:0;max-width:100%;box-sizing:border-box;">';
        echo '<label for="' . esc_attr( $select_id ) . '" style="display:block;font-size:.85em;margin-bottom:2px;">تخصص</label>';
        // No name attribute: a pure view filter, never submitted, never trusted.
        echo '<input type="search" id="' . esc_attr( $search_id ) . '"'
            . ' class="cpms-catalog-search" data-cpms-specialty-search="1"'
            . ' placeholder="جست‌وجوی تخصص…" aria-label="جست‌وجوی تخصص"'
            . ' autocomplete="off" inputmode="search"'
            . ' style="width:100%;min-width:0;max-width:100%;margin-bottom:4px;box-sizing:border-box;">';
        echo '<select id="' . esc_attr( $select_id ) . '"'
            . ' name="' . esc_attr( $select_name ) . '"'
            . ' data-cpms-specialty-select="1"'
            . ' style="width:100%;min-width:0;max-width:100%;box-sizing:border-box;">';
        echo '<option value="">— انتخاب تخصص (اختیاری) —</option>';
        foreach ( MedicalSpecialtyCatalog::groups() as $group ) {
            echo '<optgroup label="' . esc_attr( $group['label'] ) . '" data-group="' . esc_attr( $group['key'] ) . '">';
            foreach ( $group['items'] as $item ) {
                echo '<option value="' . esc_attr( $item['label'] ) . '"'
                    . ' data-en="' . esc_attr( $item['en'] ) . '"'
                    . ( $selected === $item['label'] ? ' selected' : '' ) . '>'
                    . esc_html( $item['label'] ) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '<option value="' . esc_attr( MedicalSpecialtyCatalog::OTHER_SPECIALTY_SENTINEL ) . '"'
            . ' data-cpms-specialty-other="1"'
            . ( $custom_value !== '' ? ' selected' : '' ) . '>'
            . 'سایر / Other — وارد کردن نام تخصص</option>';
        echo '</select>';
        echo '</div>';

        echo '<div style="flex:1 1 11em;min-width:0;max-width:100%;box-sizing:border-box;"'
            . ' data-cpms-specialty-custom-wrap="1">';
        echo '<label for="' . esc_attr( $custom_id ) . '"'
            . ' style="display:block;font-size:.85em;margin-bottom:2px;">نام تخصص (اگر در فهرست نیست)</label>';
        echo '<input type="text" id="' . esc_attr( $custom_id ) . '"'
            . ' name="' . esc_attr( $custom_name ) . '"'
            . ' data-cpms-specialty-custom-input="1"'
            . ' value="' . esc_attr( $custom_value ) . '"'
            . ' maxlength="' . esc_attr( (string) MedicalSpecialtyCatalog::MAX_SPECIALTY_LENGTH ) . '"'
            . ' autocomplete="off" dir="rtl"'
            . ' placeholder="مثال: فلوشیپ ناباروری"'
            . ' style="width:100%;min-width:0;max-width:100%;box-sizing:border-box;">';
        echo '<span class="description" style="display:block;font-size:.8em;">'
            . 'تخصص دستی در سمت سرور اعتبارسنجی می‌شود و بدون تغییر در همین پرونده ذخیره و دوباره نمایش داده می‌شود.</span>';
        echo '</div>';

        echo '</div>';
    }
}
