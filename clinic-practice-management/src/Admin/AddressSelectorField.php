<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Domain\Geography\IranProvinceCityCatalog;

/**
 * Renders offline Iranian province/city controls for Classic address forms.
 *
 * Security & UX invariants:
 *  - Purely offline: uses `IranProvinceCityCatalog` (31 official provinces + cities).
 *  - Never defaults to Tehran: unselected state is the empty placeholder `""`.
 *  - Backwards-compatible: existing stored addresses without a recognized `<Province>، <City>`
 *    prefix leave both selectors unselected while the address `<input>` retains its full value.
 *  - "Other / سایر": a city missing from the offline catalogue can be typed manually. The manual
 *    value is bound to the SELECTED province, is validated on the SERVER
 *    (`IranProvinceCityCatalog::validate_custom_city()`), and is persisted with an explicit
 *    marker so canonical and user-defined cities never mix. The sentinel itself is never stored.
 *  - Searchable: optional offline filter boxes (progressive enhancement, no network). Without
 *    JavaScript the native `<select>` still works and the manual city input stays visible, so no
 *    flow depends on script.
 *  - Responsive RTL: flex-wrap with `min-width:0;max-width:100%` prevents horizontal overflow
 *    down to 360px/390px viewports.
 */
final class AddressSelectorField {

    /**
     * Render the province and city controls before an address `<input>`.
     *
     * @param ?string $current_address  Persisted address (or composed stale value) to re-display.
     * @param string  $id_prefix        Deterministic unique prefix (no duplicate HTML ids).
     * @param string  $province_name    Submitted name of the province control.
     * @param string  $city_name        Submitted name of the city control.
     * @param string  $target_input_id  Id of the address `<input>` this selector belongs to.
     * @param string  $city_custom_name Submitted name of the manual ("Other") city control.
     */
    public static function render(
        ?string $current_address,
        string $id_prefix,
        string $province_name = 'address_province',
        string $city_name = 'address_city',
        string $target_input_id = '',
        string $city_custom_name = 'address_city_custom'
    ): void {
        $parsed            = IranProvinceCityCatalog::parse_address( $current_address );
        $selected_province = $parsed['province'];
        $selected_city     = $parsed['city'];
        $selected_custom   = $parsed['city_custom'];
        $provinces         = IranProvinceCityCatalog::provinces();
        $province_id       = $id_prefix . '_province';
        $city_id           = $id_prefix . '_city';
        $city_custom_id    = $id_prefix . '_city_custom';
        $province_search_id = $id_prefix . '_province_search';
        $city_search_id    = $id_prefix . '_city_search';

        echo '<div class="cpms-address-geography" dir="rtl" data-cpms-address-geography="1"'
            . ' data-target-input="' . esc_attr( $target_input_id ) . '"'
            . ' data-cpms-other-sentinel="' . esc_attr( IranProvinceCityCatalog::OTHER_CITY_SENTINEL ) . '"'
            . ' style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:6px;max-width:25em;box-sizing:border-box;">';

        // ---- Province: search box + canonical select -----------------------
        echo '<div style="flex:1 1 11em;min-width:0;max-width:100%;box-sizing:border-box;">';
        echo '<label for="' . esc_attr( $province_id ) . '" class="screen-reader-text">استان</label>';
        // No name attribute: a pure view filter is never submitted and never
        // reaches the server, so it can never influence validation.
        echo '<input type="search" id="' . esc_attr( $province_search_id ) . '"'
            . ' class="cpms-catalog-search" data-cpms-province-search="1"'
            . ' placeholder="جست‌وجوی استان…" aria-label="جست‌وجوی استان"'
            . ' autocomplete="off" inputmode="search"'
            . ' style="width:100%;min-width:0;max-width:100%;margin-bottom:4px;box-sizing:border-box;">';
        echo '<select id="' . esc_attr( $province_id ) . '"'
            . ' name="' . esc_attr( $province_name ) . '"'
            . ' data-cpms-province-select="1"'
            . ' aria-label="استان"'
            . ' style="width:100%;min-width:0;max-width:100%;box-sizing:border-box;">';
        echo '<option value="">— انتخاب استان (اختیاری) —</option>';
        foreach ( $provinces as $province ) {
            $p_name = $province['name'];
            echo '<option value="' . esc_attr( $p_name ) . '"'
                . ( $selected_province === $p_name ? ' selected' : '' ) . '>'
                . esc_html( $p_name ) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // ---- City: search box + province-grouped canonical select + "Other" --
        echo '<div style="flex:1 1 11em;min-width:0;max-width:100%;box-sizing:border-box;">';
        echo '<label for="' . esc_attr( $city_id ) . '" class="screen-reader-text">شهر</label>';
        echo '<input type="search" id="' . esc_attr( $city_search_id ) . '"'
            . ' class="cpms-catalog-search" data-cpms-city-search="1"'
            . ' placeholder="جست‌وجوی شهر…" aria-label="جست‌وجوی شهر"'
            . ' autocomplete="off" inputmode="search"'
            . ' style="width:100%;min-width:0;max-width:100%;margin-bottom:4px;box-sizing:border-box;">';
        echo '<select id="' . esc_attr( $city_id ) . '"'
            . ' name="' . esc_attr( $city_name ) . '"'
            . ' data-cpms-city-select="1"'
            . ' data-selected-city="' . esc_attr( $selected_city ) . '"'
            . ' aria-label="شهر"'
            . ' style="width:100%;min-width:0;max-width:100%;box-sizing:border-box;">';
        echo '<option value="">— انتخاب شهر (اختیاری) —</option>';
        foreach ( $provinces as $province ) {
            $p_name       = $province['name'];
            $group_hidden = ( $selected_province !== '' && $selected_province !== $p_name );
            echo '<optgroup label="' . esc_attr( $p_name ) . '" data-province="' . esc_attr( $p_name ) . '"'
                . ( $group_hidden ? ' hidden disabled' : '' ) . '>';
            foreach ( $province['cities'] as $city ) {
                $is_selected = ( $selected_province === $p_name && $selected_city === $city );
                echo '<option value="' . esc_attr( $city ) . '"'
                    . ' data-province="' . esc_attr( $p_name ) . '"'
                    . ( $is_selected ? ' selected' : '' ) . '>'
                    . esc_html( $city ) . '</option>';
            }
            echo '</optgroup>';
        }
        // Always available, outside every optgroup: the manual entry path.
        echo '<option value="' . esc_attr( IranProvinceCityCatalog::OTHER_CITY_SENTINEL ) . '"'
            . ' data-cpms-city-other="1"'
            . ( $selected_custom !== '' ? ' selected' : '' ) . '>'
            . 'سایر / Other — وارد کردن نام شهر</option>';
        echo '</select>';
        echo '</div>';

        // ---- Manual city (bound to the selected province) -------------------
        // Rendered visible: without JavaScript the form stays fully usable and the
        // operator can still supply a city that the offline catalogue lacks.
        echo '<div style="flex:1 1 22em;min-width:0;max-width:100%;box-sizing:border-box;"'
            . ' data-cpms-city-custom-wrap="1">';
        echo '<label for="' . esc_attr( $city_custom_id ) . '"'
            . ' style="display:block;font-size:.85em;margin-bottom:2px;">نام شهر (اگر در فهرست نیست)</label>';
        echo '<input type="text" id="' . esc_attr( $city_custom_id ) . '"'
            . ' name="' . esc_attr( $city_custom_name ) . '"'
            . ' data-cpms-city-custom-input="1"'
            . ' value="' . esc_attr( $selected_custom ) . '"'
            . ' maxlength="' . esc_attr( (string) IranProvinceCityCatalog::MAX_CUSTOM_CITY_LENGTH ) . '"'
            . ' autocomplete="off" dir="rtl"'
            . ' placeholder="مثال: پردیس"'
            . ' style="width:100%;min-width:0;max-width:100%;box-sizing:border-box;">';
        echo '<span class="description" style="display:block;font-size:.8em;">'
            . 'شهر دستی فقط همراه استان انتخاب‌شده پذیرفته می‌شود و در سمت سرور اعتبارسنجی می‌گردد.</span>';
        echo '</div>';

        echo '</div>';
    }
}
