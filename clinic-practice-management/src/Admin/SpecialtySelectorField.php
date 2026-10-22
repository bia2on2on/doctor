<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Domain\Clinical\MedicalSpecialtyCatalog;

/**
 * Renders the offline, searchable clinical-specialty control for Classic forms.
 *
 * Wire contract (deliberately unchanged from the pre-catalogue form): the SINGLE
 * submitted field is `input[name="specialty"]`, a plain visible text input holding
 * the exact label to persist. The catalogue `<select>` is a pure picker: it carries
 * NO name attribute, so it can never submit, never shadow the text input, and no
 * existing form handler, acceptance harness or stored value has to learn a second
 * field. `MedicalSpecialtyCatalog::resolve_submitted()` stays the only authority and
 * already accepts one string: a catalogue label/slug collapses to the canonical
 * label, anything else is validated as a user-defined label.
 *
 * Invariants (same discipline as `AddressSelectorField`):
 *  - Offline only: `MedicalSpecialtyCatalog` ships inside the release ZIP; no network call.
 *  - No silent default: an unknown/empty stored value selects nothing and never becomes
 *    "پزشک عمومی"; the text input re-displays the stored value verbatim.
 *  - Canonical vs user-defined: a stored value that matches the catalogue re-selects that
 *    option; anything else pre-selects "سایر / Other" and keeps its exact text, so historical
 *    free-text specialties keep their meaning instead of being dropped or coerced.
 *  - Label-only: nothing here creates Specialty master data (Phase 4 / drift M-01) and no code
 *    system is invented. The persisted value stays a single human-readable label.
 *  - Server-side authority: the submitted string is re-validated by
 *    `MedicalSpecialtyCatalog::resolve_submitted()`; JavaScript is a convenience, never a gate.
 *  - Progressive enhancement + RTL: without script the operator simply types the label into
 *    the text input (the pre-existing behaviour); with script the search box filters the
 *    catalogue and picking an option writes the canonical label into that same input.
 */
final class SpecialtySelectorField {

	/**
	 * @param ?string $current_specialty Persisted (or stale) value to re-display.
	 * @param string  $id_prefix         Deterministic unique prefix (no duplicate HTML ids).
	 * @param string  $input_name        Submitted name of the single specialty field.
	 */
	public static function render(
		?string $current_specialty,
		string $id_prefix,
		string $input_name = 'specialty'
	): void {
		$classified = MedicalSpecialtyCatalog::classify( $current_specialty );
		$stored     = trim( (string) ( $classified['label'] ?? '' ) );
		$is_custom  = (bool) ( $classified['custom'] ?? false ) && '' !== $stored;
		$select_id  = $id_prefix . '_specialty_pick';
		$input_id   = $id_prefix . '_specialty';
		$search_id  = $id_prefix . '_specialty_search';

		echo '<div class="cpms-specialty-field" dir="rtl" data-cpms-specialty-field="1"'
			. ' data-cpms-other-sentinel="' . esc_attr( MedicalSpecialtyCatalog::OTHER_SPECIALTY_SENTINEL ) . '"'
			. ' style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:6px;max-width:25em;box-sizing:border-box;">';

		echo '<div style="flex:1 1 11em;min-width:0;max-width:100%;box-sizing:border-box;">';
		echo '<label for="' . esc_attr( $select_id ) . '" style="display:block;font-size:.85em;margin-bottom:2px;">انتخاب از فهرست تخصص‌ها</label>';
		// No name attribute: a pure picker/filter, never submitted, never trusted.
		echo '<input type="search" id="' . esc_attr( $search_id ) . '"'
			. ' class="cpms-catalog-search" data-cpms-specialty-search="1"'
			. ' placeholder="جست‌وجوی تخصص…" aria-label="جست‌وجوی تخصص"'
			. ' autocomplete="off" inputmode="search"'
			. ' style="width:100%;min-width:0;max-width:100%;margin-bottom:4px;box-sizing:border-box;">';
		echo '<select id="' . esc_attr( $select_id ) . '"'
			. ' data-cpms-specialty-select="1"'
			. ' data-cpms-specialty-target="' . esc_attr( $input_id ) . '"'
			. ' style="width:100%;min-width:0;max-width:100%;box-sizing:border-box;">';
		echo '<option value="">— انتخاب تخصص (اختیاری) —</option>';
		foreach ( MedicalSpecialtyCatalog::groups() as $group ) {
			echo '<optgroup label="' . esc_attr( $group['label'] ) . '" data-group="' . esc_attr( $group['key'] ) . '">';
			foreach ( $group['items'] as $item ) {
				echo '<option value="' . esc_attr( $item['label'] ) . '"'
					. ' data-en="' . esc_attr( $item['en'] ) . '"'
					. ( ! $is_custom && $stored === $item['label'] ? ' selected' : '' ) . '>'
					. esc_html( $item['label'] ) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '<option value="' . esc_attr( MedicalSpecialtyCatalog::OTHER_SPECIALTY_SENTINEL ) . '"'
			. ' data-cpms-specialty-other="1"'
			. ( $is_custom ? ' selected' : '' ) . '>'
			. 'سایر / Other — وارد کردن نام تخصص</option>';
		echo '</select>';
		echo '</div>';

		echo '<div style="flex:1 1 11em;min-width:0;max-width:100%;box-sizing:border-box;"'
			. ' data-cpms-specialty-custom-wrap="1">';
		echo '<label for="' . esc_attr( $input_id ) . '"'
			. ' style="display:block;font-size:.85em;margin-bottom:2px;">تخصص</label>';
		echo '<input type="text" id="' . esc_attr( $input_id ) . '"'
			. ' name="' . esc_attr( $input_name ) . '"'
			. ' data-cpms-specialty-custom-input="1"'
			. ' value="' . esc_attr( $stored ) . '"'
			. ' maxlength="' . esc_attr( (string) MedicalSpecialtyCatalog::MAX_SPECIALTY_LENGTH ) . '"'
			. ' autocomplete="off" dir="rtl"'
			. ' placeholder="مثال: پزشک عمومی یا فلوشیپ ناباروری"'
			. ' style="width:100%;min-width:0;max-width:100%;box-sizing:border-box;">';
		echo '<span class="description" style="display:block;font-size:.8em;">'
			. 'انتخاب از فهرست همین مقدار را پر می‌کند؛ تخصص دستی در سمت سرور اعتبارسنجی می‌شود و بدون تغییر ذخیره و دوباره نمایش داده می‌شود.</span>';
		echo '</div>';

		echo '</div>';
	}
}
