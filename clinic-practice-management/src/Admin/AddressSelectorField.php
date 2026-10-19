<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Domain\Geography\IranProvinceCityCatalog;

/**
 * Renders offline Iranian province/city `<select>` controls for Classic address forms.
 *
 * Security & UX invariants:
 *  - Purely offline: uses `IranProvinceCityCatalog` (31 official provinces + cities).
 *  - Never defaults to Tehran: unselected state is the empty placeholder `""`.
 *  - Backwards-compatible: existing stored addresses without a recognized `<Province>، <City>`
 *    prefix leave both selectors unselected while the address `<input>` retains its full value.
 *  - Responsive RTL: flex-wrap with `min-width:0;max-width:100%` prevents horizontal overflow
 *    down to 360px/390px viewports.
 */
final class AddressSelectorField {

    /**
     * Render the province and city `<select>` controls before an address `<input>`.
     */
    public static function render(
        ?string $current_address,
        string $id_prefix,
        string $province_name = 'address_province',
        string $city_name = 'address_city',
        string $target_input_id = ''
    ): void {
        $parsed            = IranProvinceCityCatalog::parse_address( $current_address );
        $selected_province = $parsed['province'];
        $selected_city     = $parsed['city'];
        $provinces         = IranProvinceCityCatalog::provinces();
        $province_id       = $id_prefix . '_province';
        $city_id           = $id_prefix . '_city';

        echo '<div class="cpms-address-geography" dir="rtl" data-cpms-address-geography="1"'
            . ' data-target-input="' . esc_attr( $target_input_id ) . '"'
            . ' style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:6px;max-width:25em;box-sizing:border-box;">';

        echo '<div style="flex:1 1 11em;min-width:0;max-width:100%;box-sizing:border-box;">';
        echo '<label for="' . esc_attr( $province_id ) . '" class="screen-reader-text">استان</label>';
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

        echo '<div style="flex:1 1 11em;min-width:0;max-width:100%;box-sizing:border-box;">';
        echo '<label for="' . esc_attr( $city_id ) . '" class="screen-reader-text">شهر</label>';
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
        echo '</select>';
        echo '</div>';

        echo '</div>';
    }
}
