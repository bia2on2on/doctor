<?php
/**
 * Public directory / booking — H1 (doctor title) and H2 (specialty label) at the
 * UNIT level, without WordPress and without a database.
 *
 * Why this file exists
 * --------------------
 * The public clinician item is rendered by
 * `ClinicCore\Frontend\PublicBookingShortcode::render_clinician_item()` and the
 * specialty filter by `::render_filters()`. Both are pure string builders: given
 * a clinician row they call `DoctorDisplayName` and `MedicalSpecialtyCatalog`
 * and escape the result. Nothing else in the project needs WordPress for them,
 * yet until now their only coverage was
 * `tests/Integration/PublicDoctorTitleSpecialtyLabelTest.php`, which requires
 * the WP test suite + MySQL and therefore only ever runs in CI.
 *
 * Consequences of that gap, measured on this repository:
 *  - on the revision BEFORE the #341 fix (`02cf7164^1`, blob
 *    `55ffcc13…` of the shortcode) the renderer emitted the RAW stored
 *    `full_name` and the RAW stored `specialty`:
 *        'سارا احمدی'        -> 'سارا احمدی'      (no title)
 *        'Dr. Smith'         -> 'Dr. Smith'       (no Persian title)
 *        'دکتر دکتر علی حسینی' -> 'دکتر دکتر علی حسینی' (doubled)
 *        'cardiology'        -> 'cardiology'      (raw slug as the label)
 *     This suite fails on that revision. It passes on the current one, so it is
 *     a regression lock for the delivered behaviour, not a new product rule.
 *
 * What is asserted (current, delivered behaviour — nothing invented)
 * -----------------------------------------------------------------
 *  H1  the visible name is `DoctorDisplayName::with_title_or_fallback()`:
 *      one Persian title is added when missing, an existing title is kept,
 *      duplicates collapse, a title word inside a name stays a name, and
 *      `data-search` carries the titled form the client matches against.
 *      `with_title_or_fallback()`'s 'پزشک' fallback is deliberately NOT asserted
 *      here: both callers filter empty names before rendering
 *      (`PublicBookingShortcode::active_clinicians()` and
 *      `PublicClinicDirectory::clinicians_by_clinic()`), so it is unreachable
 *      through this renderer.
 *  H2  a recognised catalogue value (canonical label, ASCII slug or Latin alias)
 *      is shown as its canonical Persian label and the SAME resolved string is
 *      used for `data-specialty` and for the filter `<option value>` — the
 *      client compares them for equality. An unrecognised custom/historical
 *      value is shown exactly as stored, and catalogue aliases collapse to one
 *      filter option instead of three.
 *
 * Not asserted here on purpose (would be a product decision, not a defect):
 *  - a custom specialty that differs from a catalogue label only by ZWNJ vs
 *    space ('متخصص پزشکی هسته‌ای' vs 'متخصص پزشکی هسته ای') is NOT fuzzy-matched;
 *    it stays raw, which is the documented "never coerced into a wrong bucket"
 *    rule of `MedicalSpecialtyCatalog::classify()`.
 *
 * Synthetic data only — no patient data, no fixtures, no database.
 */

declare(strict_types=1);

namespace {

    /**
     * The renderer reaches exactly two WordPress functions.
     *
     * They are declared at global scope (the classes call them unqualified) and
     * only when WordPress has not already provided them, so the same file is
     * safe inside the WP integration suite. Semantics match `wp-includes`:
     * HTML entities for quotes, and invalid UTF-8 substituted rather than
     * dropped (the production calls never pass raw user bytes, but the test
     * must not be the reason a sequence is silently lost).
     */
    if ( ! function_exists( 'esc_html' ) ) {
        /**
         * @param mixed $text Value to escape.
         */
        function esc_html( $text ) {
            return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        }
    }

    if ( ! function_exists( 'esc_attr' ) ) {
        /**
         * @param mixed $text Value to escape.
         */
        function esc_attr( $text ) {
            return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        }
    }
}

namespace ClinicCore\Tests\Unit\Frontend {

    use ClinicCore\Frontend\PublicBookingShortcode;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    /**
     * Pins the delivered public title/specialty presentation without WP or MySQL.
     */
    final class PublicDoctorTitleSpecialtyUnitTest extends TestCase {

        /** @var ReflectionMethod */
        private ReflectionMethod $render_item;

        /** @var ReflectionMethod */
        private ReflectionMethod $render_filters;

        protected function setUp(): void {
            parent::setUp();

            $this->render_item    = new ReflectionMethod( PublicBookingShortcode::class, 'render_clinician_item' );
            $this->render_filters = new ReflectionMethod( PublicBookingShortcode::class, 'render_filters' );
            // Required on PHP < 8.1 semantics and harmless afterwards.
            $this->render_item->setAccessible( true );
            $this->render_filters->setAccessible( true );
        }

        /**
         * Render one synthetic clinician row through the real production method.
         *
         * @param array<string, mixed> $clinician Synthetic row (no patient data).
         */
        private function item( array $clinician ): string {
            $html = $this->render_item->invoke( null, $clinician, 7, array() );
            self::assertIsString( $html );

            return $html;
        }

        /**
         * Visible text of one span inside the rendered item, or null when the
         * span was not published. Fails loudly if the markup shape changed, so
         * a silent restructure cannot turn the assertions below vacuous.
         *
         * @param string $html   Rendered item.
         * @param string $suffix Class suffix after `cpms-public-booking__`.
         */
        private function span_text( string $html, string $suffix ): ?string {
            $pattern = '#<span class="cpms-public-booking__' . preg_quote( $suffix, '#' ) . '">(.*?)</span>#s';
            $found   = preg_match( $pattern, $html, $matches );
            self::assertNotFalse( $found, 'span scan failed for ' . $suffix );
            if ( 1 !== $found ) {
                return null;
            }

            return $matches[1];
        }

        /**
         * Value of one `data-*` attribute of the rendered <li>, or null.
         *
         * @param string $html Rendered item.
         * @param string $name Attribute name without the `data-` prefix.
         */
        private function data_attr( string $html, string $name ): ?string {
            $pattern = '#data-' . preg_quote( $name, '#' ) . '="(.*?)"#s';
            $found   = preg_match( $pattern, $html, $matches );
            self::assertNotFalse( $found );
            if ( 1 !== $found ) {
                return null;
            }

            return $matches[1];
        }

        // =================== H1 — the doctor title is a display label ===================

        public function test_h1_missing_title_is_added_and_raw_name_is_not_published(): void {
            $html = $this->item( array( 'id' => 1, 'name' => 'سارا احمدی', 'specialty' => '', 'weekdays' => array() ) );

            self::assertSame( 'دکتر سارا احمدی', $this->span_text( $html, 'clinician-name' ) );
            // CONTROL: the raw, untitled name must not survive as the visible text.
            self::assertStringNotContainsString( '>سارا احمدی<', $html );
        }

        public function test_h1_existing_title_is_kept_exactly_once(): void {
            $html = $this->item( array( 'id' => 2, 'name' => 'دکتر رضا کریمی', 'specialty' => '', 'weekdays' => array() ) );

            self::assertSame( 'دکتر رضا کریمی', $this->span_text( $html, 'clinician-name' ) );
        }

        public function test_h1_doubled_stored_title_collapses_to_one(): void {
            $html = $this->item( array( 'id' => 3, 'name' => 'دکتر دکتر علی حسینی', 'specialty' => '', 'weekdays' => array() ) );

            self::assertSame( 'دکتر علی حسینی', $this->span_text( $html, 'clinician-name' ) );
            // Scoped to the VISIBLE span on purpose: `data-search` deliberately
            // also carries the normalised raw stored form, so the doubled text
            // stays searchable. Only the published label must be collapsed.
            self::assertStringNotContainsString(
                '<span class="cpms-public-booking__clinician-name">دکتر دکتر علی حسینی</span>',
                $html
            );
        }

        public function test_h1_latin_dr_title_becomes_the_persian_title(): void {
            // Deliberately asserts CURRENT behaviour; debt F5 records that
            // "Dr. Smith" -> "دکتر Smith" is not a desired end state.
            $html = $this->item( array( 'id' => 4, 'name' => 'Dr. Smith', 'specialty' => '', 'weekdays' => array() ) );

            self::assertSame( 'دکتر Smith', $this->span_text( $html, 'clinician-name' ) );
        }

        public function test_h1_arabic_kaf_in_stored_title_is_normalised_in_display(): void {
            // U+0643 ARABIC LETTER KAF in the stored value, Persian U+06A9 on screen.
            $html = $this->item( array( 'id' => 5, 'name' => "د\u{0643}تر مینا رضایی", 'specialty' => '', 'weekdays' => array() ) );

            $visible = $this->span_text( $html, 'clinician-name' );
            self::assertSame( "د\u{06A9}تر مینا رضایی", $visible );
            self::assertStringNotContainsString( "\u{0643}تر", (string) $visible );
        }

        public function test_h1_a_name_that_only_starts_with_title_letters_stays_a_name(): void {
            // Owner rule D1: only a WHOLE title token is a title; "دکترزاده" is a name.
            $html = $this->item( array( 'id' => 6, 'name' => 'دکترزاده', 'specialty' => '', 'weekdays' => array() ) );

            self::assertSame( 'دکتر دکترزاده', $this->span_text( $html, 'clinician-name' ) );
        }

        public function test_h1_search_index_carries_the_titled_form(): void {
            $html = $this->item( array( 'id' => 7, 'name' => 'سارا احمدی', 'specialty' => '', 'weekdays' => array() ) );

            $search = (string) $this->data_attr( $html, 'search' );
            self::assertStringContainsString( 'دکتر سارا احمدی', $search );

            // Latin title: the raw form must remain searchable too, because "dr"
            // is not a substring of the titled "دکتر smith" form.
            $latin = $this->item( array( 'id' => 8, 'name' => 'Dr. Smith', 'specialty' => '', 'weekdays' => array() ) );
            $latin_search = (string) $this->data_attr( $latin, 'search' );
            self::assertStringContainsString( 'دکتر smith', $latin_search );
            self::assertStringContainsString( 'dr. smith', $latin_search );
        }

        public function test_h1_hostile_stored_name_is_escaped(): void {
            $html = $this->item( array( 'id' => 9, 'name' => '<script>alert(1)</script>', 'specialty' => '', 'weekdays' => array() ) );

            self::assertStringNotContainsString( '<script>alert(1)</script>', $html );
            self::assertStringContainsString( '&lt;script&gt;', $html );
        }

        // =================== H2 — the specialty label is resolved ===================

        public function test_h2_catalogue_slug_shows_the_canonical_persian_label(): void {
            $html = $this->item( array( 'id' => 10, 'name' => 'حسن نادری', 'specialty' => 'cardiology', 'weekdays' => array() ) );

            self::assertSame( 'متخصص قلب و عروق', $this->span_text( $html, 'clinician-specialty' ) );
            // CONTROL: the raw ASCII slug must not be published anywhere.
            self::assertStringNotContainsString( 'cardiology', $html );
            self::assertSame( 'متخصص قلب و عروق', $this->data_attr( $html, 'specialty' ) );
        }

        public function test_h2_case_variant_slug_and_latin_alias_resolve_to_the_same_label(): void {
            $variants = array( 'Cardiology', 'cardiology', 'General Practitioner' );
            $resolved = array(
                'Cardiology'           => 'متخصص قلب و عروق',
                'cardiology'           => 'متخصص قلب و عروق',
                'General Practitioner' => 'پزشک عمومی',
            );

            foreach ( $variants as $stored ) {
                $html = $this->item( array( 'id' => 11, 'name' => 'حسن نادری', 'specialty' => $stored, 'weekdays' => array() ) );
                self::assertSame( $resolved[ $stored ], $this->span_text( $html, 'clinician-specialty' ), 'stored: ' . $stored );
                self::assertStringNotContainsString( (string) $stored, $html, 'raw value must not leak: ' . $stored );
            }
        }

        public function test_h2_custom_or_historical_specialty_is_preserved_verbatim(): void {
            $custom = 'فلوشیپ ناباروری';
            $html   = $this->item( array( 'id' => 12, 'name' => 'حسن نادری', 'specialty' => $custom, 'weekdays' => array() ) );

            self::assertSame( $custom, $this->span_text( $html, 'clinician-specialty' ) );
            self::assertSame( $custom, $this->data_attr( $html, 'specialty' ) );
        }

        public function test_h2_empty_specialty_publishes_no_specialty_span(): void {
            $html = $this->item( array( 'id' => 13, 'name' => 'زهرا مرادی', 'specialty' => '', 'weekdays' => array() ) );

            self::assertNull( $this->span_text( $html, 'clinician-specialty' ) );
            self::assertStringNotContainsString( 'clinician-specialty', $html );
        }

        public function test_h2_data_specialty_matches_the_filter_option_value(): void {
            // The client compares `data-specialty` with the selected
            // <option value> for equality; both come from ONE resolver.
            $stored = 'cardiology';
            $item   = $this->item( array( 'id' => 14, 'name' => 'حسن نادری', 'specialty' => $stored, 'weekdays' => array() ) );
            $filters = $this->render_filters->invoke( null, 'cpms-public-booking-1', array( $stored ) );
            self::assertIsString( $filters );

            $data_specialty = (string) $this->data_attr( $item, 'specialty' );
            self::assertSame( 'متخصص قلب و عروق', $data_specialty );

            $found = preg_match_all( '#<option value="([^"]+)">([^<]+)</option>#', (string) $filters, $matches, PREG_SET_ORDER );
            self::assertIsInt( $found );
            self::assertGreaterThan( 0, $found, 'the filter must publish at least one specialty option' );

            $values = array();
            foreach ( $matches as $match ) {
                self::assertSame( $match[2], $match[1], 'option value must equal its visible label' );
                $values[] = $match[1];
            }
            self::assertContains( $data_specialty, $values );
            self::assertNotContains( $stored, $values, 'the raw slug must never be an option value' );
        }

        public function test_h2_catalogue_aliases_collapse_to_one_filter_option(): void {
            // Same clinical specialty stored three ways by history: the filter
            // must offer ONE option, or the operator sees duplicate labels.
            $filters = $this->render_filters->invoke(
                null,
                'cpms-public-booking-1',
                array( 'cardiology', 'Cardiology', 'متخصص قلب و عروق', 'فلوشیپ ناباروری' )
            );
            self::assertIsString( $filters );

            $found = preg_match_all( '#<option value="([^"]+)">([^<]+)</option>#', (string) $filters, $matches, PREG_SET_ORDER );
            self::assertGreaterThan( 0, (int) $found );

            $labels = array();
            foreach ( $matches as $match ) {
                $labels[] = $match[1];
            }

            self::assertSame(
                array( 'فلوشیپ ناباروری', 'متخصص قلب و عروق' ),
                $labels,
                'aliases of one catalogue entry must render exactly one option, sorted by resolved label'
            );
        }

        public function test_h2_hostile_stored_specialty_is_escaped(): void {
            $html = $this->item(
                array( 'id' => 15, 'name' => 'امیر حسنی', 'specialty' => 'فلوشیپ ناباروری"><script>alert(1)</script>', 'weekdays' => array() )
            );

            self::assertStringNotContainsString( '<script>alert(1)</script>', $html );
            self::assertStringContainsString( '&quot;&gt;&lt;script&gt;', (string) $this->data_attr( $html, 'specialty' ) );
        }
    }
}
