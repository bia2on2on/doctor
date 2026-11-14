<?php
/**
 * Public directory / booking — the weekday CONTRACT on the public surface.
 *
 * Why this file exists
 * --------------------
 * `cpms_schedule.day_of_week` is stored as the Iranian week, Saturday first:
 * `0=شنبه … 6=جمعه`. That contract is asserted independently by
 * `docs/erd/data-dictionary.md` (the `cpms_schedule` section),
 * `ScheduleService`'s validation message, both admin `DAYS` arrays and
 * `SlotsGenerateHandler::toIranianDow()` — the one place that converts a real
 * calendar date INTO the contract.
 *
 * The public shortcode used to decode that stored value with the GREGORIAN
 * `w` table (`0=یک‌شنبه … 6=شنبه`), because one function served both meanings.
 * The result was a public «روزهای کاری» line that named the wrong day for every
 * stored value — 7 of 7 — while everything downstream of real dates (slots,
 * calendar captions) stayed correct. Both orders are integers in 0..6, so
 * nothing failed: the type system cannot tell them apart and no test pinned the
 * rendered string.
 *
 * Design of these tests
 * ---------------------
 * The assertions below go through the STABLE surface — `weekday_summary()` and
 * `render_clinician_item()` — so the file fails on the pre-fix source with a
 * wrong LABEL (not merely a missing method), and passes after the fix. Only the
 * last two tests name the new functions, because their whole point is that the
 * two meanings must stay separated.
 *
 * Pinned here:
 *  1. every stored value yields its exact Persian label;
 *  2. for real dates, the label for the stored value derived from that date IS
 *     the real weekday of the date, and the calendar caption agrees with it;
 *  3. the calendar-caption path keeps EXACTLY its previous output for all 7;
 *  4. the rendered «روزهای کاری» line for a known schedule is pinned so the two
 *     meanings cannot be silently re-merged.
 *
 * Synthetic data only — no patient data, no database, no fixtures.
 */

declare(strict_types=1);

namespace {

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
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionMethod;

    /**
     * Pins the stored-weekday contract and the Gregorian-caption contract apart.
     */
    final class PublicWeekdayContractTest extends TestCase {

        /** The storage contract: Saturday first. Index == stored `day_of_week`. */
        private const STORED_LABELS = array(
            0 => 'شنبه',
            1 => 'یک‌شنبه',
            2 => 'دوشنبه',
            3 => 'سه‌شنبه',
            4 => 'چهارشنبه',
            5 => 'پنجشنبه',
            6 => 'جمعه',
        );

        /** The Gregorian `w` contract: Sunday first. Index == PHP `format( 'w' )`. */
        private const GREGORIAN_W_LABELS = array(
            0 => 'یک‌شنبه',
            1 => 'دوشنبه',
            2 => 'سه‌شنبه',
            3 => 'چهارشنبه',
            4 => 'پنجشنبه',
            5 => 'جمعه',
            6 => 'شنبه',
        );

        private const WORKING_DAYS_PREFIX = 'روزهای کاری:';

        /** @var ReflectionMethod */
        private ReflectionMethod $summary;

        /** @var ReflectionMethod */
        private ReflectionMethod $render_item;

        protected function setUp(): void {
            parent::setUp();

            $this->summary     = new ReflectionMethod( PublicBookingShortcode::class, 'weekday_summary' );
            $this->render_item = new ReflectionMethod( PublicBookingShortcode::class, 'render_clinician_item' );
            $this->summary->setAccessible( true );
            $this->render_item->setAccessible( true );
        }

        /**
         * The stored `day_of_week` a real date falls on, per the documented contract.
         */
        private function stored_day_for( string $ymd ): int {
            $date = new DateTimeImmutable( $ymd . ' 12:00:00', new DateTimeZone( 'UTC' ) );

            $map = array( 0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 0 );

            return $map[ (int) $date->format( 'w' ) ];
        }

        /**
         * The calendar-caption decoder, resolved by whichever name is present.
         *
         * This deliberately accepts both the post-fix name and the legacy name so
         * the CAPTION assertion means the same thing before and after the fix:
         * "this real date still gets this label". It is the caption path that
         * must not change, so the test must be able to read it on both revisions.
         */
        private function caption_label( int $gregorian_w ): string {
            $class = new ReflectionClass( PublicBookingShortcode::class );
            foreach ( array( 'weekday_label_from_gregorian_w', 'weekday_label_from_db' ) as $name ) {
                if ( $class->hasMethod( $name ) ) {
                    $m = $class->getMethod( $name );
                    $m->setAccessible( true );

                    return (string) $m->invoke( null, $gregorian_w );
                }
            }

            self::fail( 'no calendar-caption decoder found on PublicBookingShortcode' );
        }

        /** The full «روزهای کاری: …» line for a set of stored days. */
        private function summary_line( array $stored_days ): string {
            return (string) $this->summary->invoke( null, $stored_days );
        }

        // ============ 1. every stored value maps to its exact label ============

        public function test_every_stored_day_maps_to_its_exact_persian_label(): void {
            foreach ( self::STORED_LABELS as $stored => $expected ) {
                self::assertSame(
                    self::WORKING_DAYS_PREFIX . ' ' . $expected,
                    $this->summary_line( array( $stored ) ),
                    'stored day_of_week ' . $stored . ' must render ' . $expected
                );
            }
        }

        public function test_a_stored_day_is_never_labelled_with_the_gregorian_table(): void {
            // The exact regression: the `w` table applied to a stored value named
            // the NEXT day, so every label was wrong in the same direction.
            foreach ( self::STORED_LABELS as $stored => $expected ) {
                $wrong = self::GREGORIAN_W_LABELS[ $stored ];
                if ( $wrong === $expected ) {
                    continue;
                }
                self::assertStringNotContainsString(
                    self::WORKING_DAYS_PREFIX . ' ' . $wrong,
                    $this->summary_line( array( $stored ) ),
                    'stored ' . $stored . ' must not be decoded with the Gregorian w table'
                );
            }
        }

        // ====== 2. real dates: the stored path and the caption path agree ======

        public function test_for_real_dates_the_stored_label_is_the_real_weekday(): void {
            // Two full weeks from a known Saturday, so every weekday appears twice
            // and the week wrap is covered as well.
            $start = new DateTimeImmutable( '2026-11-14 12:00:00', new DateTimeZone( 'UTC' ) );
            self::assertSame( '6', $start->format( 'w' ), 'sanity: 2026-11-14 is a Saturday' );

            for ( $i = 0; $i < 14; $i++ ) {
                $date = $start->modify( '+' . $i . ' days' );
                $ymd  = $date->format( 'Y-m-d' );

                $real_weekday = self::GREGORIAN_W_LABELS[ (int) $date->format( 'w' ) ];
                $stored_day   = $this->stored_day_for( $ymd );

                self::assertSame(
                    self::WORKING_DAYS_PREFIX . ' ' . $real_weekday,
                    $this->summary_line( array( $stored_day ) ),
                    $ymd . ': stored day ' . $stored_day . ' must name the real weekday'
                );
                self::assertSame(
                    $real_weekday,
                    $this->caption_label( (int) $date->format( 'w' ) ),
                    $ymd . ': the calendar caption must name the same real weekday'
                );
            }
        }

        public function test_a_doctor_working_saturdays_is_published_as_saturday(): void {
            // The headline case from the defect report: stored 0 is Saturday.
            $html = $this->render_item->invoke(
                null,
                array(
                    'id'        => 1,
                    'name'      => 'سارا احمدی',
                    'specialty' => '',
                    'weekdays'  => array( 0 ),
                ),
                7,
                array()
            );

            self::assertIsString( $html );
            self::assertStringContainsString( 'روزهای کاری: شنبه', $html );
            self::assertStringNotContainsString( 'روزهای کاری: یک‌شنبه', $html );
        }

        // ========== 3. the calendar-caption path is unchanged for all 7 =========

        public function test_the_calendar_caption_table_is_exactly_the_previous_output(): void {
            foreach ( self::GREGORIAN_W_LABELS as $w => $expected ) {
                self::assertSame(
                    $expected,
                    $this->caption_label( $w ),
                    'caption for w=' . $w . ' must be unchanged by the fix'
                );
            }
        }

        public function test_caption_labels_over_two_real_weeks_are_correct(): void {
            $start = new DateTimeImmutable( '2026-11-14 12:00:00', new DateTimeZone( 'UTC' ) );

            for ( $i = 0; $i < 14; $i++ ) {
                $date = $start->modify( '+' . $i . ' days' );
                self::assertSame(
                    self::GREGORIAN_W_LABELS[ (int) $date->format( 'w' ) ],
                    $this->caption_label( (int) $date->format( 'w' ) ),
                    $date->format( 'Y-m-d' ) . ' caption must equal the real weekday'
                );
            }
        }

        // ============ 4. guard: the two paths cannot be re-merged ==============

        public function test_the_rendered_working_days_line_is_pinned_for_a_known_schedule(): void {
            // A known schedule: Sat + Mon + Wed, stored as 0, 2, 4.
            $html = $this->render_item->invoke(
                null,
                array(
                    'id'        => 2,
                    'name'      => 'حسن نادری',
                    'specialty' => '',
                    'weekdays'  => array( 0, 2, 4 ),
                ),
                7,
                array()
            );

            self::assertIsString( $html );
            self::assertStringContainsString( 'روزهای کاری: شنبه، دوشنبه، چهارشنبه', $html );
        }

        public function test_the_summary_keeps_stored_order_and_deduplicates(): void {
            self::assertSame(
                'روزهای کاری: جمعه، شنبه، سه‌شنبه',
                $this->summary_line( array( 6, 0, 0, 3 ) ),
                'input order, no duplicates'
            );

            $empty = $this->summary_line( array() );
            self::assertStringNotContainsString( 'یک‌شنبه', $empty, 'an empty set publishes no weekday' );
            self::assertStringNotContainsString( 'شنبه', $empty, 'an empty set publishes no weekday' );
        }

        public function test_out_of_range_stored_days_publish_no_label(): void {
            foreach ( array( -1, 7, 99 ) as $bad ) {
                $line = $this->summary_line( array( $bad ) );
                foreach ( self::STORED_LABELS as $label ) {
                    self::assertStringNotContainsString( $label, $line, 'stored ' . $bad . ' must publish no day' );
                }
            }
        }

        // ==== 5. the separation itself: two names, two tables, both correct ====

        public function test_the_two_decoders_are_separately_named_and_both_correct(): void {
            $class = new ReflectionClass( PublicBookingShortcode::class );

            // The fix's whole point: one function per meaning, each with its own name.
            self::assertTrue( $class->hasMethod( 'weekday_label_from_stored_day' ), 'stored-day decoder must exist' );
            self::assertTrue( $class->hasMethod( 'weekday_label_from_gregorian_w' ), 'gregorian decoder must exist' );

            $stored = $class->getMethod( 'weekday_label_from_stored_day' );
            $greg   = $class->getMethod( 'weekday_label_from_gregorian_w' );
            $stored->setAccessible( true );
            $greg->setAccessible( true );

            foreach ( self::STORED_LABELS as $day => $expected ) {
                self::assertSame( $expected, $stored->invoke( null, $day ), 'stored decoder, day ' . $day );
            }
            foreach ( self::GREGORIAN_W_LABELS as $w => $expected ) {
                self::assertSame( $expected, $greg->invoke( null, $w ), 'gregorian decoder, w=' . $w );
            }

            // A single shared table cannot satisfy both meanings: at every index
            // the two contracts disagree, so re-merging them must fail loudly.
            $differences = 0;
            foreach ( self::STORED_LABELS as $i => $label ) {
                if ( $label !== self::GREGORIAN_W_LABELS[ $i ] ) {
                    $differences++;
                }
            }
            self::assertSame( 7, $differences, 'sanity: the two contracts differ at every index' );
        }
    }
}
