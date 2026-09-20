<?php

/**
 * Phase 9 Slice 2 — Patient Portal: internal notifications list + unread badge
 * + explicit mark-all-read wiring (TEST-ONLY RED).
 *
 * ===========================================================================
 * SCOPE (owner-directed Slice 2 — narrowest useful vertical slice)
 * ===========================================================================
 *
 * The authenticated pure-patient console («نوبت‌های من», PatientPortalPage,
 * slug cpms-patient) must show the patient THEIR OWN internal notifications and
 * let them mark all of them read through the EXISTING backend contract:
 *
 *   G6  GET  clinic/v1/notifications            (X-WP-Nonce: wp_rest)
 *   R2b POST clinic/v1/notifications/read       {all: true}
 *
 * Both routes are already implemented and green on live main
 * (NotificationsController + NotificationService::inbox()/markRead(), covered by
 * NotificationFlowTest::testInboxMarkReadAndRtNotifications and
 * NotificationFlowTest::testPatientInboxShowsAppointmentNotificationsWithJalali).
 * FR-16.6 (SRS) requires "اعلان‌های Internal (لیست در داشبورد + Badge)".
 *
 * Slice 2 adds ONLY the portal surface that reaches those existing routes.
 * NOT in scope (NOT asserted here): any new REST route, any migration (live
 * latest on disk = 2026_09_20_0022; 0023 is NOT reserved), any settings key,
 * any product PHP/JS/CSS, any real-time polling/ETag UI, per-notification read
 * toggles, notification preferences, reschedule/profile/visits/files/finance.
 *
 * ===========================================================================
 * CHANNEL NEUTRALITY (future outbound channels: Telegram, Iranian messaging /
 * social platforms, other providers)
 * ===========================================================================
 *
 * This RED is deliberately channel-neutral and adds NOTHING for future
 * channels — no provider tables, settings, classes or speculative abstractions:
 *
 *  - every assertion about DATA is derived from what the EXISTING backend
 *    actually returns (NotificationService::inbox() / GET clinic/v1/notifications),
 *    never from a hardcoded payload, provider name, token or channel list;
 *  - the fixture asserts the seeded rows are `channel = 'internal'` because that
 *    is what the portal slice renders; it does NOT assert that internal is the
 *    only channel, nor that the inbox is channel-filtered — `cpms_notifications`
 *    already carries channel ENUM('internal','sms','email','push') and
 *    NotificationEvents stays the reusable domain registry a future provider
 *    adapter can consume unchanged;
 *  - no SMS/provider payload shape, no delivery status and no provider
 *    identifier is part of any portal contract here;
 *  - the UI markers are about NOTIFICATION RECORDS (id / title / read state),
 *    so a future adapter that publishes another channel needs no portal change.
 *
 * ===========================================================================
 * MULTI-CLINIC RULE (no permanent "primary Patient only" product claim)
 * ===========================================================================
 *
 * NotificationService::linkedPatient() resolves the actor's Patient with the
 * established backend precedent `ORDER BY l.is_primary DESC, l.id ASC LIMIT 1`
 * and derives the Clinic from that Patient row (C6). This RED:
 *
 *  - seeds exactly ONE active link, flagged primary, so that established
 *    resolution is unambiguous for the fixture (a fixture-construction concern);
 *  - NEVER asserts "a Patient may have only one link / one Clinic";
 *  - NEVER asserts that cross-Clinic aggregation is forbidden — every UI
 *    expectation is computed from the live backend inbox response, so if the
 *    backend later aggregates notifications across Clinics the portal contract
 *    below keeps holding without edits;
 *  - introduces NO global/current/first Clinic concept: the Clinic id is read
 *    dynamically from the installation's real seeded Clinic via App::scope()
 *    (SOURCE_SYSTEM_SINGLE), exactly as the Slice 1 portal suite does, because
 *    PatientPortalPage::render() itself calls App::settings().
 *
 * The foreign-recipient control below is another Patient inside the SAME
 * install — it proves recipient ownership isolation and makes no Clinic claim.
 *
 * ===========================================================================
 * LIVE STATE AT RED AUTHORING (independently fetched, 2026-09-20)
 * ===========================================================================
 *
 * - authoritative main = 28f51144ece98420ae9052e55e7f821156a37787
 *   ("Merge pull request #95 …"; 19/19 check-runs success on that head) —
 *   matches the owner's expected main, no drift.
 * - open PRs = 0 (`gh pr list --state open` => []); no Phase9Slice2* file
 *   exists anywhere in the tree => no overlapping work.
 * - workspace status = clean (tracked + untracked: none).
 * - latest migration on disk = 2026_09_20_0022_slot_holds_patient_binding.php
 *   (22 migrations + MigrationRunner; no 0023 exists; none is created/reserved).
 * - Phase 9 = 🚧 STARTED / IN PROGRESS; Slice 1 = CLOSED via PR #93.
 * - PatientPortalPage::render() today: title + upcoming/history tables + the
 *   Slice 1 self-cancel action + a `cpms-patient-portal__config` JSON script
 *   carrying ONLY {rest_root, cancel_path, nonce}. NO notifications section, NO
 *   notification rows, NO unread badge, NO mark-all-read control, NO published
 *   notifications/read path.
 * - Existing notification backend: NotificationsController registers
 *   GET /clinic/v1/notifications, POST /clinic/v1/notifications/read and
 *   GET /clinic/v1/rt/notifications (all `wp_rest`-nonce guarded, roles
 *   cpms_patient|cpms_secretary|cpms_doctor); NotificationRepository reads
 *   `recipient_patient_id` with `status != 'cancelled'` and counts unread with
 *   `read_at IS NULL`; markAllRead() is `WHERE recipient_patient_id = ? AND
 *   read_at IS NULL` (already idempotent).
 *
 * ===========================================================================
 * SLICE 2 UI/CONFIG CONTRACT (what GREEN must satisfy — defined by this file)
 * ===========================================================================
 *
 *  N1  The portal renders ONE professional notifications section for the
 *      authenticated pure patient:
 *        <section|landmark … data-role="notifications-section" …>
 *      - exactly one such element on the page;
 *      - it is a professional, distinguishable section: a <section> landmark, an
 *        element carrying role=… / aria-label=… / aria-labelledby=…, or one with
 *        its own non-empty heading (<h2>–<h6>) — matching the existing portal
 *        pattern («نوبت‌های پیش‌رو» / «تاریخچه» are <h2>);
 *      - it contains exactly one row per notification the backend inbox
 *        returned, each marked
 *          data-role="notification-row" data-notification-id="<id>"
 *        and no other row (another Patient's notification must not appear);
 *      - each row renders that notification's server-published title text;
 *      - it contains exactly one unread badge
 *          data-role="notifications-unread-badge" data-unread-count="<n>"
 *        whose data-unread-count equals the server-known unread_count exactly
 *        and whose visible text carries that same number as a standalone
 *        number token (Persian/Arabic digits accepted);
 *      - each row exposes read/unread state for later UI wiring:
 *          data-read="0" (unread, backend read_at IS NULL)
 *          data-read="1" (read,   backend read_at IS NOT NULL)
 *      The fixture seeds 4 notifications of which exactly 3 are unread, so the
 *      badge can never be satisfied by counting rendered rows (4 ≠ 3).
 *
 *  N2  The portal exposes explicit mark-all-read wiring that REUSES the
 *      existing contract:
 *        <button … data-role="notifications-mark-all-read" …>
 *      - exactly one such control on the page, a real <button>, not disabled,
 *        with a non-empty accessible name (aria-label wins, else text content);
 *      - the EXISTING `cpms-patient-portal__config` JSON script (published
 *        since Slice 1) additionally exposes the EXISTING read path
 *        '/notifications/read' as a string value (key name is GREEN's choice);
 *      - the config's existing `rest_root` is reused unchanged and equals
 *        untrailingslashit(rest_url('clinic/v1')) — correct under BOTH Plain
 *        (?rest_route=) and Pretty (/wp-json/) permalink shapes;
 *      - the config's existing `nonce` is reused unchanged and verifies for
 *        action 'wp_rest' as the authenticated patient;
 *      - the config exposes NO authority identifier: no clinic_id, patient_id,
 *        user_id/wp_user_id and no recipient* key at any nesting level —
 *        ownership stays server-side (G6 "نقش خود");
 *      - NO new endpoint is assumed: every path-shaped value the config
 *        publishes composes (apiUrl rule) to an ALREADY REGISTERED REST route,
 *        and '/notifications/read' must resolve to the registered
 *        /clinic/v1/notifications/read accepting POST.
 *
 * ===========================================================================
 * RED CLASSIFICATION (CI Integration job = source of truth; NOT RUN != PASS)
 * ===========================================================================
 *
 * INTENDED RED — exactly two tests fail on live main, attributable ONLY to the
 * missing Slice 2 portal UI/config wiring (product-level assertion failures,
 * never bootstrap/fixture/SQL/FK/harness failures):
 *
 *  R1 testPortalRendersNotificationsSectionWithUnreadBadgeForPurePatient
 *     Fixture + all positive controls pass (pure-patient WP user, active Patient
 *     link, 4 internal rows, service inbox returns them, unread_count === 3);
 *     the real PatientPortalPage::render() path is reached (title + both
 *     existing headings render); then N1 fails because no
 *     data-role="notifications-section" element exists (0 ≠ 1).
 *
 *  R2 testPortalExposesMarkAllReadWiringForAuthenticatedPatient
 *     Same fixture/controls; every "passes today" guard is evaluated FIRST and
 *     does pass (one config script; rest_root reused; wp_rest nonce reused; no
 *     authority identifiers; today's published cancel_path maps to the
 *     registered B4 route; /clinic/v1/notifications/read is already registered
 *     and accepts POST); then N2 fails because no
 *     data-role="notifications-mark-all-read" control exists (0 ≠ 1).
 *
 * POSITIVE-CONTROL BACKEND GUARD — must pass today and keep GREEN honest:
 *
 *  G1 testPositiveControlExistingInboxAndMarkAllReadRoutesWorkForLinkedPatient
 *     Identical fixture; proves the EXISTING backend is already green over the
 *     real REST boundary: inbox without nonce → 403 CLINIC_INVALID_NONCE;
 *     inbox with wp_rest nonce → 200 with exactly the actor-owned notifications
 *     and unread_count === 3; ?unread=true → exactly the 3 unread ids;
 *     POST read {ids:[<foreign id>]} → marked 0 and the foreign row stays
 *     unread (recipient ownership cannot be mutated); POST read {all:true} →
 *     marked 3; the identical second POST → marked 0 (idempotent); inbox again
 *     → unread_count 0 with every read_at set; the foreign Patient's row is
 *     STILL unread in the database. Backend contracts stay covered by their own
 *     suites (NotificationFlowTest, NotifDispatchM2WiringRedTest) and are not
 *     re-asserted or made RED here.
 *
 * Positive controls executed BEFORE any HTML assertion (fixture proof):
 *  - exactly one seeded Clinic resolves via App::scope() (SOURCE_SYSTEM_SINGLE)
 *    — PatientPortalPage::render() calls App::settings(); ids read dynamically;
 *  - WP user exists with exactly the portal-expected role (cpms_patient) and
 *    PatientPortalPage::isPatientOnly() is true;
 *  - a real ACTIVE Patient link row exists for that WP user and Clinic (single
 *    link, is_primary = 1 → unambiguous backend resolution);
 *  - internal notification rows exist in cpms_notifications for that Patient
 *    (4 rows, channel 'internal', none cancelled, exactly 3 with read_at NULL);
 *  - NotificationService::inbox() — the backend the portal would call — really
 *    returns those rows with the published titles and unread_count === 3;
 *  - another Patient's notification is NOT in this actor's inbox.
 *
 * INVALID-RED PROTECTIONS: fixture inserts use only columns that exist on live
 * main (same column sets as the Phase 7/8/9 RED suites); no hardcoded clinic or
 * patient id; distinct mobiles/logins/mrn/dedupe_key per row; ScopeContext,
 * App scope, SystemClinicResolver and Settings caches cleared in setUp AND
 * tearDown; output buffering closed in a finally block so a wp_die() cannot leak
 * buffers; no permalink mutation is needed by this suite.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Notifications\NotificationEvents;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Infrastructure\Repository\NotificationRepository;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase9Slice2PatientPortalNotificationsRedTest extends WP_UnitTestCase
{
	/** REST namespace of the EXISTING notifications API (NotificationsController::NS). */
	private const NS = 'clinic/v1';

	/** Existing G6 inbox route, relative to the namespace. */
	private const INBOX_PATH = '/notifications';

	/** Existing read / mark-all-read route, relative to the namespace. */
	private const READ_PATH = '/notifications/read';

	/** Class of the safe JSON runtime config the portal already publishes (Slice 1 C2). */
	private const CONFIG_CLASS = 'cpms-patient-portal__config';

	/** N1 — stable product-facing marker of the notifications section. */
	private const SECTION_ROLE = 'notifications-section';

	/** N1 — stable product-facing marker of one rendered notification row. */
	private const ROW_ROLE = 'notification-row';

	/** N1 — stable product-facing marker of the unread badge. */
	private const BADGE_ROLE = 'notifications-unread-badge';

	/** N2 — stable product-facing marker of the explicit mark-all-read control. */
	private const MARK_ALL_ROLE = 'notifications-mark-all-read';

	/** Attribute binding a rendered row to a backend notification record (selector only). */
	private const ROW_ID_ATTRIBUTE = 'data-notification-id';

	/** Attribute carrying the machine-readable unread total on the badge. */
	private const BADGE_COUNT_ATTRIBUTE = 'data-unread-count';

	/** Attribute carrying per-row read/unread state for later UI wiring. */
	private const ROW_READ_ATTRIBUTE = 'data-read';

	/** Exact number of internal notifications seeded for the linked Patient. */
	private const OWN_TOTAL = 4;

	/** Exact number of those notifications that are still unread (4 ≠ 3 on purpose). */
	private const OWN_UNREAD = 3;

	/** Exact product strings of PatientPortalPage (ZWNJ = \u{200C}, verified byte-for-byte). */
	private const TITLE_MY_APPOINTMENTS = "نوبت\u{200C}های من";
	private const HEADING_UPCOMING = "نوبت\u{200C}های پیش\u{200C}رو";
	private const HEADING_HISTORY = 'تاریخچه';

	protected function setUp(): void
	{
		parent::setUp();
		wp_set_current_user( 0 );
		ScopeContext::clear();
		App::resetScope();
		SystemClinicResolver::flush();
		Settings::flushCache();
	}

	protected function tearDown(): void
	{
		wp_set_current_user( 0 );
		ScopeContext::clear();
		App::resetScope();
		SystemClinicResolver::flush();
		Settings::flushCache();
		parent::tearDown();
	}

	// =================================================================
	// R1 — INTENDED RED: notifications section + rows + unread badge + read state (N1)
	// =================================================================

	public function testPortalRendersNotificationsSectionWithUnreadBadgeForPurePatient(): void
	{
		$fx   = $this->buildLinkedPatientNotificationFixture( 'n1' );
		$inbox = $this->assertFixtureMaterialized( $fx );

		$notifications = $inbox['notifications'];
		$unread_count  = (int) $inbox['unread_count'];
		self::assertSame( self::OWN_UNREAD, $unread_count, 'positive control: the server-known unread_count is exactly ' . self::OWN_UNREAD . '.' );
		self::assertSame( self::OWN_TOTAL, count( $notifications ), 'positive control: the backend inbox returns exactly ' . self::OWN_TOTAL . ' notifications.' );
		self::assertNotSame( count( $notifications ), $unread_count, 'positive control: unread_count differs from the row count — the badge cannot be a row count.' );

		// Real product path: PatientPortalPage::render() as the authenticated pure patient.
		$html = $this->renderPortalAs( (int) $fx['user_id'] );

		// ---- INTENDED RED (N1): the portal has no notifications section on live main. ----
		$section_tags = $this->markedOpeningTags( $html, self::SECTION_ROLE );
		self::assertCount(
			1,
			$section_tags,
			'Slice 2 N1: the portal must render exactly one professional notifications section '
			. '(data-role="' . self::SECTION_ROLE . '") for the authenticated pure patient. '
			. 'The backend inbox already returns ' . self::OWN_TOTAL . ' internal notifications '
			. '(unread_count=' . $unread_count . ') for this Patient.'
		);

		$section = $this->markedElementFragment( $html, self::SECTION_ROLE );
		self::assertNotNull( $section, 'Slice 2 N1: the notifications section must be a well-formed element.' );
		$section = (string) $section;

		// A "professional" section is a distinguishable, accessible one: a <section>
		// landmark, an element carrying role / aria-label / aria-labelledby, or one with
		// its own non-empty heading (the existing portal marks its sections with <h2>).
		self::assertTrue(
			$this->isLandmarkElement( $section_tags[0] ) || $this->hasNonEmptyHeading( $section ),
			'Slice 2 N1: the notifications section must be a professional, accessible section — a <section> landmark, '
			. 'an element with role / aria-label / aria-labelledby, or one carrying its own non-empty <h2>–<h6> heading. Got: '
			. $section_tags[0]
		);

		// ---- Rows: exactly one per backend notification, no foreign row. ----
		$page_rows    = $this->markedOpeningTags( $html, self::ROW_ROLE );
		$section_rows = $this->markedOpeningTags( $section, self::ROW_ROLE );
		self::assertCount(
			self::OWN_TOTAL,
			$page_rows,
			'Slice 2 N1: the portal must render exactly one notification row per notification the backend inbox returned '
			. '(' . self::OWN_TOTAL . '), and no row outside the notifications section.'
		);
		self::assertCount(
			self::OWN_TOTAL,
			$section_rows,
			'Slice 2 N1: every rendered notification row must live inside the notifications section.'
		);

		$expected_by_id = [];
		foreach ( $notifications as $notification ) {
			$expected_by_id[ (int) $notification['id'] ] = $notification;
		}
		$inbox_ids = array_map( 'intval', array_keys( $expected_by_id ) );
		sort( $inbox_ids );
		$seeded_ids = array_map( 'intval', $fx['own_ids'] );
		sort( $seeded_ids );
		self::assertSame(
			$seeded_ids,
			$inbox_ids,
			'positive control: the backend inbox returned exactly the seeded notification ids.'
		);

		$rendered_ids = [];
		foreach ( $section_rows as $row_tag ) {
			$row_id = $this->attributeValue( $row_tag, self::ROW_ID_ATTRIBUTE );
			self::assertNotNull(
				$row_id,
				'Slice 2 N1: every notification row must carry ' . self::ROW_ID_ATTRIBUTE . ' (selector only — authority stays server-side). Got: ' . $row_tag
			);
			self::assertMatchesRegularExpression(
				'/^\d+$/',
				(string) $row_id,
				'Slice 2 N1: ' . self::ROW_ID_ATTRIBUTE . ' must be a notification id. Got: ' . (string) $row_id
			);
			$rendered_ids[] = (int) $row_id;

			$notification = $expected_by_id[ (int) $row_id ] ?? null;
			self::assertNotNull(
				$notification,
				'Slice 2 N1: row ' . self::ROW_ID_ATTRIBUTE . '=' . (string) $row_id . ' is not a notification the backend returned to this actor. '
				. 'Backend ids: ' . implode( ', ', $fx['own_ids'] )
			);

			$row = $this->elementFragmentByOpeningTag( $section, $row_tag );
			self::assertNotNull( $row, 'Slice 2 N1: the notification row must be a well-formed element.' );
			$title = trim( (string) $notification['title'] );
			self::assertNotSame( '', $title, 'positive control: the backend published a non-empty title for notification #' . (int) $row_id . '.' );
			self::assertStringContainsString(
				$this->normalizeWhitespace( $title ),
				$this->textContent( (string) $row ),
				'Slice 2 N1: the row must render the server-published title of notification #' . (int) $row_id . '.'
			);

			// Read/unread semantics sufficient for later UI wiring (no client-side guessing).
			$read_flag = $this->attributeValue( $row_tag, self::ROW_READ_ATTRIBUTE );
			self::assertNotNull(
				$read_flag,
				'Slice 2 N1: every notification row must expose read/unread state via ' . self::ROW_READ_ATTRIBUTE . ' ("0" unread / "1" read). Got: ' . $row_tag
			);
			$expected_flag = $notification['read_at'] === null ? '0' : '1';
			self::assertSame(
				$expected_flag,
				(string) $read_flag,
				'Slice 2 N1: ' . self::ROW_READ_ATTRIBUTE . ' of notification #' . (int) $row_id
				. ' must mirror the server-known read_at (' . var_export( $notification['read_at'], true ) . ').'
			);
		}

		sort( $rendered_ids );
		$expected_ids = $fx['own_ids'];
		sort( $expected_ids );
		self::assertSame(
			$expected_ids,
			$rendered_ids,
			'Slice 2 N1: the rendered rows must be exactly the notifications the backend inbox returned for this actor.'
		);
		self::assertNotContains(
			(int) $fx['foreign_id'],
			$rendered_ids,
			'Slice 2 N1: another Patient\'s notification must never be rendered in this actor\'s portal.'
		);

		// ---- Badge: exact, derived from the server-known unread_count. ----
		$badge_tags = $this->markedOpeningTags( $html, self::BADGE_ROLE );
		self::assertCount(
			1,
			$badge_tags,
			'Slice 2 N1: the portal must render exactly one unread badge (data-role="' . self::BADGE_ROLE
			. '") derived from the server-known unread_count (' . $unread_count . ').'
		);
		self::assertCount(
			1,
			$this->markedOpeningTags( $section, self::BADGE_ROLE ),
			'Slice 2 N1: the unread badge must live inside the notifications section.'
		);

		$badge = $this->elementFragmentByOpeningTag( $section, $badge_tags[0] );
		self::assertNotNull( $badge, 'Slice 2 N1: the unread badge must be a well-formed element inside the notifications section.' );
		self::assertSame(
			(string) $unread_count,
			(string) $this->attributeValue( $badge_tags[0], self::BADGE_COUNT_ATTRIBUTE ),
			'Slice 2 N1: ' . self::BADGE_COUNT_ATTRIBUTE . ' must equal the server-known unread_count exactly ('
			. $unread_count . ') — the value later UI wiring consumes.'
		);
		$badge_text = $this->normalizeDigits( $this->textContent( (string) $badge ) );
		self::assertMatchesRegularExpression(
			'/(?<!\d)' . preg_quote( (string) $unread_count, '/' ) . '(?!\d)/',
			$badge_text,
			'Slice 2 N1: the badge must visibly show the exact unread_count (' . $unread_count
			. ') as a standalone number — not the row count (' . self::OWN_TOTAL . '). Rendered badge text: ' . $badge_text
		);
	}

	// =================================================================
	// R2 — INTENDED RED: explicit mark-all-read control + reused existing config (N2)
	// =================================================================

	public function testPortalExposesMarkAllReadWiringForAuthenticatedPatient(): void
	{
		$fx    = $this->buildLinkedPatientNotificationFixture( 'n2' );
		$inbox = $this->assertFixtureMaterialized( $fx );
		self::assertSame( self::OWN_UNREAD, (int) $inbox['unread_count'], 'positive control: there is something to mark read (unread_count=' . self::OWN_UNREAD . ').' );

		$html = $this->renderPortalAs( (int) $fx['user_id'] );

		// -----------------------------------------------------------------
		// POSITIVE CONTROLS FIRST — these pass on live main and prove the
		// existing runtime config is already the reuse point Slice 2 needs.
		// -----------------------------------------------------------------
		$scripts = $this->configScriptPayloads( $html );
		self::assertCount(
			1,
			$scripts,
			'positive control: the portal already publishes exactly one <script type="application/json" class="'
			. self::CONFIG_CLASS . '"> runtime config (Slice 1 C2).'
		);
		$payload = trim( $scripts[0] );
		self::assertStringNotContainsString( '<', $payload, 'positive control: the config payload is JSON_HEX_TAG-encoded (no raw "<").' );
		self::assertStringNotContainsString( '>', $payload, 'positive control: the config payload is JSON_HEX_TAG-encoded (no raw ">").' );
		$config = json_decode( $payload, true );
		self::assertIsArray( $config, 'positive control: the config payload is a JSON object. Payload: ' . $payload );
		$config = (array) $config;

		// Existing rest_root is reused unchanged (permalink-shape agnostic).
		self::assertArrayHasKey( 'rest_root', $config, 'positive control: the existing config exposes rest_root.' );
		self::assertSame(
			untrailingslashit( rest_url( self::NS ) ),
			(string) $config['rest_root'],
			'positive control: rest_root already equals untrailingslashit(rest_url("' . self::NS . '")) and is reused as-is.'
		);

		// Existing wp_rest nonce is reused unchanged.
		self::assertArrayHasKey( 'nonce', $config, 'positive control: the existing config exposes nonce.' );
		$nonce = (string) $config['nonce'];
		self::assertNotSame( '', $nonce, 'positive control: the published nonce is non-empty.' );
		self::assertSame( (int) $fx['user_id'], get_current_user_id(), 'precondition: the nonce is verified as the authenticated patient.' );
		self::assertNotFalse(
			wp_verify_nonce( $nonce, 'wp_rest' ),
			'positive control: the published nonce already verifies for action "wp_rest" as the authenticated patient and is reused as-is.'
		);

		// No authority identifier is exposed today, and none may be added.
		$this->assertNoAuthorityIdentifiers( $config );

		// No new endpoint is assumed: everything published today already resolves to a registered route.
		foreach ( $this->configPathValues( $config ) as $published_path ) {
			$route = $this->restRouteFromUrl( $this->composeApiUrl( (string) $config['rest_root'], $this->concretePath( $published_path ) ) );
			self::assertTrue(
				$this->routeIsRegistered( $route ),
				'positive control: every path the config publishes today maps to an already-registered REST route. Unregistered: ' . $route
			);
		}

		// The backend Slice 2 must wire to is ALREADY registered (no new endpoint).
		$existing_read_route  = '/' . self::NS . self::READ_PATH;
		$existing_inbox_route = '/' . self::NS . self::INBOX_PATH;
		self::assertTrue(
			$this->routeIsRegistered( $existing_read_route ),
			'positive control: the EXISTING read route ' . $existing_read_route . ' is already registered.'
		);
		self::assertTrue(
			$this->routeAcceptsMethod( $existing_read_route, 'POST' ),
			'positive control: the EXISTING read route ' . $existing_read_route . ' already accepts POST.'
		);
		self::assertTrue(
			$this->routeAcceptsMethod( $existing_inbox_route, 'GET' ),
			'positive control: the EXISTING inbox route ' . $existing_inbox_route . ' already accepts GET.'
		);

		// ---- INTENDED RED (N2a): no explicit mark-all-read control exists on live main. ----
		$controls = $this->markedOpeningTags( $html, self::MARK_ALL_ROLE );
		self::assertCount(
			1,
			$controls,
			'Slice 2 N2: the portal must render exactly one explicit mark-all-read control '
			. '(data-role="' . self::MARK_ALL_ROLE . '") so the authenticated patient can reach the EXISTING '
			. 'POST ' . $existing_read_route . ' {all:true}. The backend is already green (see the positive-control '
			. 'guard in this suite); only the portal wiring is missing.'
		);
		$control = $controls[0];
		self::assertMatchesRegularExpression(
			'/^<button\b/i',
			$control,
			'Slice 2 N2: the mark-all-read control must be a real <button> (keyboard/screen-reader operable). Got: ' . $control
		);
		self::assertFalse(
			$this->hasBooleanAttribute( $control, 'disabled' ),
			'Slice 2 N2: with unread_count=' . self::OWN_UNREAD . ' the mark-all-read control must not be disabled.'
		);
		$control_name = $this->accessibleName( $html, $control );
		self::assertNotSame( '', $control_name, 'Slice 2 N2: the mark-all-read control needs a non-empty accessible name.' );

		// ---- INTENDED RED (N2b): the config does not publish the existing read path. ----
		$published_paths = $this->configPathValues( $config );
		self::assertContains(
			self::READ_PATH,
			$published_paths,
			'Slice 2 N2: the EXISTING portal config must expose the EXISTING notifications/read path "'
			. self::READ_PATH . '" (relative to rest_root, {id}-free) so vanilla JS can POST {all:true} '
			. 'without any new endpoint and without any hardcoded URL. Published paths: '
			. ( $published_paths === [] ? '(none)' : implode( ', ', $published_paths ) )
		);

		// Sufficiency (reached in GREEN): the published path + reused rest_root/nonce
		// resolve to the already-registered route and actually mark everything read.
		$composed_route = $this->restRouteFromUrl( $this->composeApiUrl( (string) $config['rest_root'], self::READ_PATH ) );
		self::assertSame(
			$existing_read_route,
			$composed_route,
			'Slice 2 N2: rest_root + the published read path must compose to the EXISTING route. Composed: ' . $composed_route
		);
		self::assertTrue(
			$this->routeIsRegistered( $composed_route ),
			'Slice 2 N2: no new endpoint may be assumed — ' . $composed_route . ' must already be registered.'
		);
		self::assertTrue(
			$this->routeAcceptsMethod( $composed_route, 'POST' ),
			'Slice 2 N2: ' . $composed_route . ' must already accept POST.'
		);

		$response = $this->restAs( (int) $fx['user_id'], 'POST', $composed_route, [ 'all' => true ], $nonce );
		self::assertSame(
			200,
			$response->get_status(),
			'Slice 2 N2: the config-published path + reused rest_root + reused wp_rest nonce must drive the EXISTING read route. Body: '
			. wp_json_encode( $response->get_data() )
		);
		self::assertSame(
			self::OWN_UNREAD,
			(int) ( $this->payload( $response )['marked'] ?? -1 ),
			'Slice 2 N2: the existing route must mark exactly the server-known unread notifications.'
		);
	}

	// =================================================================
	// G1 — POSITIVE-CONTROL BACKEND GUARD (must pass today)
	// =================================================================

	public function testPositiveControlExistingInboxAndMarkAllReadRoutesWorkForLinkedPatient(): void
	{
		$fx    = $this->buildLinkedPatientNotificationFixture( 'g1' );
		$inbox = $this->assertFixtureMaterialized( $fx );

		$inbox_route = '/' . self::NS . self::INBOX_PATH;
		$read_route  = '/' . self::NS . self::READ_PATH;
		$user_id     = (int) $fx['user_id'];

		// The routes are nonce-guarded — this is exactly why N2 must reuse the published wp_rest nonce.
		$no_nonce = $this->restAs( $user_id, 'GET', $inbox_route, [], null );
		self::assertSame( 403, $no_nonce->get_status(), 'GUARD: inbox without X-WP-Nonce must be rejected. Body: ' . wp_json_encode( $no_nonce->get_data() ) );
		self::assertSame( 'CLINIC_INVALID_NONCE', (string) ( $no_nonce->get_data()['code'] ?? '' ), 'GUARD: inbox without nonce → CLINIC_INVALID_NONCE.' );

		// G6 inbox over the real REST boundary returns the actor-owned notifications.
		$response = $this->restAs( $user_id, 'GET', $inbox_route, [], 'auto' );
		self::assertSame( 200, $response->get_status(), 'GUARD: the existing inbox route must accept the fixture. Body: ' . wp_json_encode( $response->get_data() ) );
		$data = $this->payload( $response );
		self::assertSame( self::OWN_UNREAD, (int) ( $data['unread_count'] ?? -1 ), 'GUARD: REST unread_count is exactly ' . self::OWN_UNREAD . '.' );
		self::assertArrayHasKey( 'notifications', $data, 'GUARD: REST inbox shape.' );
		$rest_rows = (array) $data['notifications'];
		self::assertCount( self::OWN_TOTAL, $rest_rows, 'GUARD: REST inbox returns every seeded notification.' );

		$service_rows = (array) $inbox['notifications'];
		self::assertSame(
			array_map( static fn ( array $row ): int => (int) $row['id'], $service_rows ),
			array_map( static fn ( array $row ): int => (int) $row['id'], $rest_rows ),
			'GUARD: the REST inbox and NotificationService::inbox() agree (the portal may use either).'
		);

		$by_id = [];
		foreach ( $rest_rows as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}
		self::assertArrayNotHasKey( (int) $fx['foreign_id'], $by_id, 'GUARD: another Patient\'s notification is never in this actor\'s inbox.' );
		foreach ( $fx['unread_ids'] as $unread_id ) {
			self::assertArrayHasKey( $unread_id, $by_id, 'GUARD: unread notification #' . $unread_id . ' is returned.' );
			self::assertNull( $by_id[ $unread_id ]['read_at'], 'GUARD: notification #' . $unread_id . ' is unread (read_at = null).' );
		}
		self::assertNotNull( $by_id[ (int) $fx['read_id'] ]['read_at'], 'GUARD: the seeded read notification has read_at set.' );

		// ?unread=true filters to exactly the unread rows (server-side read/unread semantics).
		$unread_only = $this->restAs( $user_id, 'GET', $inbox_route, [ 'unread' => true ], 'auto' );
		self::assertSame( 200, $unread_only->get_status(), 'GUARD: inbox?unread=true must be accepted. Body: ' . wp_json_encode( $unread_only->get_data() ) );
		$unread_rows = (array) ( $this->payload( $unread_only )['notifications'] ?? [] );
		self::assertCount( self::OWN_UNREAD, $unread_rows, 'GUARD: inbox?unread=true returns exactly the unread notifications.' );
		$unread_route_ids = array_map( static fn ( array $row ): int => (int) $row['id'], $unread_rows );
		sort( $unread_route_ids );
		$seeded_unread_ids = array_map( 'intval', $fx['unread_ids'] );
		sort( $seeded_unread_ids );
		self::assertSame(
			$seeded_unread_ids,
			$unread_route_ids,
			'GUARD: inbox?unread=true returns exactly the seeded unread ids.'
		);

		// Foreign ownership cannot be mutated through explicit ids either.
		$foreign_attempt = $this->restAs( $user_id, 'POST', $read_route, [ 'ids' => [ (int) $fx['foreign_id'] ] ], 'auto' );
		self::assertSame( 200, $foreign_attempt->get_status(), 'GUARD: POST read with a foreign id must be handled, not fatal. Body: ' . wp_json_encode( $foreign_attempt->get_data() ) );
		self::assertSame( 0, (int) ( $this->payload( $foreign_attempt )['marked'] ?? -1 ), 'GUARD: an actor cannot mark another Patient\'s notification read (marked=0).' );
		self::assertNull( $this->readAtOf( (int) $fx['foreign_id'] ), 'GUARD: the foreign notification is still unread after the attempted mutation.' );

		// POST read {all:true} works over the existing route.
		$mark_all = $this->restAs( $user_id, 'POST', $read_route, [ 'all' => true ], 'auto' );
		self::assertSame( 200, $mark_all->get_status(), 'GUARD: POST read {all:true} must be accepted. Body: ' . wp_json_encode( $mark_all->get_data() ) );
		self::assertSame( self::OWN_UNREAD, (int) ( $this->payload( $mark_all )['marked'] ?? -1 ), 'GUARD: mark-all marks exactly the unread notifications (' . self::OWN_UNREAD . ').' );

		// The identical second call is idempotent → marked = 0.
		$mark_all_again = $this->restAs( $user_id, 'POST', $read_route, [ 'all' => true ], 'auto' );
		self::assertSame( 200, $mark_all_again->get_status(), 'GUARD: the second identical mark-all must be accepted. Body: ' . wp_json_encode( $mark_all_again->get_data() ) );
		self::assertSame( 0, (int) ( $this->payload( $mark_all_again )['marked'] ?? -1 ), 'GUARD: the second identical mark-all is idempotent (marked=0).' );

		// Resulting state: nothing unread for this actor; every row read.
		$after = $this->restAs( $user_id, 'GET', $inbox_route, [], 'auto' );
		self::assertSame( 200, $after->get_status(), 'GUARD: inbox after mark-all must be accepted.' );
		$after_data = $this->payload( $after );
		self::assertSame( 0, (int) ( $after_data['unread_count'] ?? -1 ), 'GUARD: unread_count is 0 after mark-all.' );
		foreach ( (array) $after_data['notifications'] as $row ) {
			self::assertNotNull( $row['read_at'], 'GUARD: notification #' . (int) $row['id'] . ' is read after mark-all.' );
		}

		// Recipient isolation: the foreign Patient's notification is untouched.
		self::assertNull( $this->readAtOf( (int) $fx['foreign_id'] ), 'GUARD: mark-all must not mutate another Patient\'s notification.' );
		self::assertSame( self::OWN_TOTAL, count( (array) $after_data['notifications'] ), 'GUARD: mark-all changes read state only — no row disappears from the inbox.' );
	}

	// =================================================================
	// Fixture (real rows, real service publish path, dynamic ids)
	// =================================================================

	/**
	 * Seeds a pure-patient WP user, one active Patient link and 4 internal
	 * notifications (3 unread + 1 already read) for that Patient, plus one
	 * notification owned by a DIFFERENT Patient in the same install.
	 *
	 * Multi-Clinic note: the single primary link only makes the established
	 * backend resolution unambiguous for this fixture. It is not a product claim.
	 *
	 * @return array<string, mixed>
	 */
	private function buildLinkedPatientNotificationFixture( string $tag ): array
	{
		// PatientPortalPage::render() calls App::settings(), which needs the installation's
		// single seeded Clinic to resolve. Ids are read dynamically — never hardcoded.
		$clinic_count = (int) App::db()->fetchValue( 'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_clinics' ), [] );
		self::assertSame( 1, $clinic_count, 'precondition: exactly one seeded Clinic (the portal\'s App::settings() call requires a single-Clinic install).' );

		$scope = App::scope();
		self::assertSame( ClinicScope::SOURCE_SYSTEM_SINGLE, $scope->source, 'precondition: Scope resolves from the installation\'s real seeded Clinic.' );
		$clinic_id = $scope->clinicId;
		self::assertGreaterThan( 0, $clinic_id, 'precondition: seeded Clinic id.' );

		$now      = App::db()->nowUtcSql();
		$mobile   = $this->mobileFor( $tag );
		$patient_id = $this->insertPatient( $clinic_id, $mobile, $tag );

		// Pure patient: exactly the portal-expected role, nothing else.
		$user_id = (int) wp_create_user( 'p9s2_' . $tag . '_' . uniqid( '', false ), 'pass-not-used-123', uniqid( 'p9s2_' . $tag . '_', true ) . '@test.local' );
		self::assertGreaterThan( 0, $user_id, 'precondition: WP user created.' );
		$user = get_userdata( $user_id );
		self::assertNotFalse( $user, 'precondition: WP user readable.' );
		$user->set_role( RolesAndCapabilities::ROLE_PATIENT );

		$this->insertRow(
			'cpms_patient_user_links',
			[
				'clinic_id'      => $clinic_id,
				'patient_id'     => $patient_id,
				'wp_user_id'     => $user_id,
				'mobile_at_link' => $mobile,
				'is_primary'     => 1,
				'linked_at'      => $now,
			],
			[ '%d', '%d', '%d', '%s', '%d', '%s' ],
			'patient_user_link'
		);

		// Foreign recipient control: another Patient in the same install owns one
		// internal notification that must never surface here nor be mutable by this actor.
		$foreign_patient_id = $this->insertPatient( $clinic_id, $this->mobileFor( $tag . '_foreign' ), $tag . '_foreign' );

		$jalali_date = Jalali::formatYmd( $this->ymdDaysOffset( 4 ) );
		$events      = [
			NotificationEvents::APPT_CONFIRMED,
			NotificationEvents::APPT_CHANGED,
			NotificationEvents::APPT_REMINDER,
			NotificationEvents::FOLLOWUP_REMINDER,
		];

		$own_ids = [];
		$titles  = [];
		foreach ( $events as $index => $event ) {
			$vars = [
				'doctor_name'      => 'Dr P9S2 ' . $tag,
				'appointment_date' => $jalali_date,
				'appointment_time' => '10:0' . $index,
			];
			// Real domain publish path (N-1/N-2): INSERT status=queued, channel=internal,
			// payload rendered by the existing NotificationEvents registry.
			$notification_id = App::notificationService()->publishToPatient(
				$clinic_id,
				$patient_id,
				$event,
				$vars,
				'p9s2:' . $tag . ':' . $index . ':' . bin2hex( random_bytes( 4 ) )
			);
			self::assertNotNull( $notification_id, 'fixture: internal notification published for event ' . $event . '.' );
			self::assertGreaterThan( 0, (int) $notification_id, 'fixture: internal notification id for event ' . $event . '.' );
			$own_ids[]           = (int) $notification_id;
			$rendered            = NotificationEvents::render( $event, $vars );
			$titles[ (int) $notification_id ] = (string) $rendered['title'];
		}
		self::assertCount( self::OWN_TOTAL, $own_ids, 'fixture: exactly ' . self::OWN_TOTAL . ' notifications were seeded.' );

		// The LAST seeded notification is already read → unread_count (3) ≠ row count (4),
		// so an unread badge cannot be produced by counting rendered rows.
		$read_id = $own_ids[ self::OWN_TOTAL - 1 ];
		( new NotificationRepository( App::db() ) )->updateById( $read_id, [ 'read_at' => $now ] );

		$foreign_notification_id = App::notificationService()->publishToPatient(
			$clinic_id,
			$foreign_patient_id,
			NotificationEvents::APPT_CANCELLED,
			[
				'doctor_name'      => 'Dr Foreign ' . $tag,
				'appointment_date' => $jalali_date,
				'appointment_time' => '11:30',
			],
			'p9s2:' . $tag . ':foreign:' . bin2hex( random_bytes( 4 ) )
		);
		self::assertNotNull( $foreign_notification_id, 'fixture: the foreign internal notification was published.' );

		return [
			'clinic_id'          => $clinic_id,
			'user_id'            => $user_id,
			'patient_id'         => $patient_id,
			'mobile'             => $mobile,
			'foreign_patient_id' => $foreign_patient_id,
			'foreign_id'         => (int) $foreign_notification_id,
			'own_ids'            => $own_ids,
			'read_id'            => $read_id,
			'unread_ids'         => array_values( array_filter( $own_ids, static fn ( int $id ): bool => $id !== $read_id ) ),
			'titles'             => $titles,
		];
	}

	/**
	 * Positive controls executed BEFORE any HTML assertion — they prove the fixture
	 * is what the portal/backend expect, so R1/R2 can only fail for the missing UI.
	 *
	 * @param array<string, mixed> $fx
	 * @return array<string, mixed> the authoritative backend inbox response
	 */
	private function assertFixtureMaterialized( array $fx ): array
	{
		// (1) A pure-patient WP user exists.
		$user = get_userdata( (int) $fx['user_id'] );
		self::assertNotFalse( $user, 'positive control: WP user exists.' );
		self::assertSame( [ RolesAndCapabilities::ROLE_PATIENT ], array_values( (array) $user->roles ), 'positive control: pure Patient role only.' );
		self::assertTrue( PatientPortalPage::isPatientOnly( (int) $fx['user_id'] ), 'positive control: PatientPortalPage::isPatientOnly() accepts the user.' );

		// (2) A real ACTIVE Patient link exists for that user and Clinic.
		$links = App::db()->fetchAll(
			'SELECT l.clinic_id, l.patient_id, l.wp_user_id, l.is_primary, p.status
			 FROM ' . App::db()->table( 'cpms_patient_user_links' ) . ' l
			 JOIN ' . App::db()->table( 'cpms_patients' ) . ' p ON p.id = l.patient_id
			 WHERE l.wp_user_id = %d',
			[ (int) $fx['user_id'] ]
		);
		self::assertCount( 1, $links, 'positive control: exactly one Patient link for this WP user (unambiguous backend resolution).' );
		$link = $links[0];
		self::assertSame( (int) $fx['patient_id'], (int) $link['patient_id'], 'positive control: the link points at the fixture Patient.' );
		self::assertSame( (int) $fx['clinic_id'], (int) $link['clinic_id'], 'positive control: the link belongs to the seeded Clinic.' );
		self::assertSame( (int) $fx['user_id'], (int) $link['wp_user_id'], 'positive control: the link belongs to the fixture WP user.' );
		self::assertSame( 'active', (string) $link['status'], 'positive control: the linked Patient is active.' );
		self::assertSame( 1, (int) $link['is_primary'], 'positive control: the single seeded link is flagged primary.' );

		// (3) Internal notification rows exist for the backend-recognized Patient.
		$own_rows = App::db()->fetchAll(
			'SELECT id, clinic_id, channel, template, recipient_patient_id, recipient_wp_user_id, status, read_at
			 FROM ' . App::db()->table( 'cpms_notifications' ) . '
			 WHERE recipient_patient_id = %d ORDER BY id ASC',
			[ (int) $fx['patient_id'] ]
		);
		self::assertCount( self::OWN_TOTAL, $own_rows, 'positive control: internal notification rows exist for the fixture Patient.' );
		self::assertSame(
			$fx['own_ids'],
			array_map( static fn ( array $row ): int => (int) $row['id'], $own_rows ),
			'positive control: the seeded notification ids persisted.'
		);
		foreach ( $own_rows as $row ) {
			self::assertSame( 'internal', (string) $row['channel'], 'positive control: the seeded rows are INTERNAL notification records.' );
			self::assertSame( (int) $fx['clinic_id'], (int) $row['clinic_id'], 'positive control: the seeded rows belong to the seeded Clinic.' );
			self::assertSame( (int) $fx['patient_id'], (int) $row['recipient_patient_id'], 'positive control: the recipient is the fixture Patient.' );
			self::assertNotSame( 'cancelled', (string) $row['status'], 'positive control: no seeded row is cancelled (the inbox filter is status != cancelled).' );
		}
		$unread_in_db = (int) App::db()->fetchValue(
			'SELECT COUNT(*) FROM ' . App::db()->table( 'cpms_notifications' ) . '
			 WHERE recipient_patient_id = %d AND status != %s AND read_at IS NULL',
			[ (int) $fx['patient_id'], 'cancelled' ]
		);
		self::assertSame( self::OWN_UNREAD, $unread_in_db, 'positive control: exactly ' . self::OWN_UNREAD . ' unread rows in the database.' );
		self::assertNotNull( $this->readAtOf( (int) $fx['read_id'] ), 'positive control: the seeded read notification has read_at set in the database.' );
		self::assertNull( $this->readAtOf( (int) $fx['foreign_id'] ), 'positive control: the foreign notification starts unread.' );

		// (4) The backend inbox really returns those notifications with the exact unread_count.
		$inbox = App::notificationService()->inbox( (int) $fx['user_id'], false, 50 );
		self::assertArrayHasKey( 'notifications', $inbox, 'positive control: NotificationService::inbox() shape (notifications).' );
		self::assertArrayHasKey( 'unread_count', $inbox, 'positive control: NotificationService::inbox() shape (unread_count).' );
		self::assertSame( self::OWN_UNREAD, (int) $inbox['unread_count'], 'positive control: the server-known unread_count is exactly ' . self::OWN_UNREAD . '.' );

		$rows  = (array) $inbox['notifications'];
		$by_id = [];
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}
		self::assertCount( self::OWN_TOTAL, $rows, 'positive control: the backend inbox returns every seeded notification.' );
		foreach ( $fx['own_ids'] as $notification_id ) {
			self::assertArrayHasKey( $notification_id, $by_id, 'positive control: notification #' . $notification_id . ' is returned by the backend inbox.' );
			self::assertSame( (string) $fx['titles'][ $notification_id ], (string) $by_id[ $notification_id ]['title'], 'positive control: the backend publishes the rendered title of notification #' . $notification_id . '.' );
			self::assertNotSame( '', (string) $by_id[ $notification_id ]['body'], 'positive control: the backend publishes a non-empty body for notification #' . $notification_id . '.' );
		}
		self::assertArrayNotHasKey( (int) $fx['foreign_id'], $by_id, 'positive control: another Patient\'s notification is not returned to this actor.' );
		foreach ( $fx['unread_ids'] as $unread_id ) {
			self::assertNull( $by_id[ $unread_id ]['read_at'], 'positive control: notification #' . $unread_id . ' is unread server-side.' );
		}
		self::assertNotNull( $by_id[ (int) $fx['read_id'] ]['read_at'], 'positive control: notification #' . (int) $fx['read_id'] . ' is read server-side.' );

		return $inbox;
	}

	// =================================================================
	// Real portal render path
	// =================================================================

	private function renderPortalAs( int $user_id ): string
	{
		wp_set_current_user( $user_id );
		ob_start();
		try {
			PatientPortalPage::render();
		} finally {
			$html = (string) ob_get_clean();
		}
		self::assertStringContainsString( self::TITLE_MY_APPOINTMENTS, $html, 'positive control: the real PatientPortalPage::render() path was reached (page title).' );
		self::assertStringContainsString( self::HEADING_UPCOMING, $html, 'positive control: the existing portal still renders its upcoming section.' );
		self::assertStringContainsString( self::HEADING_HISTORY, $html, 'positive control: the existing portal still renders its history section.' );

		return $html;
	}

	// =================================================================
	// Markup helpers
	// =================================================================

	/**
	 * Opening tags carrying data-role="<role>", in document order.
	 *
	 * @return list<string>
	 */
	private function markedOpeningTags( string $html, string $role ): array
	{
		$matches = [];
		if ( preg_match_all( '/<[a-zA-Z][^>]*\sdata-role="' . preg_quote( $role, '/' ) . '"[^>]*>/su', $html, $matches ) === false ) {
			return [];
		}

		return (array) ( $matches[0] ?? [] );
	}

	/** The whole element (opening tag … matching close tag) marked with data-role="<role>". */
	private function markedElementFragment( string $html, string $role ): ?string
	{
		$tags = $this->markedOpeningTags( $html, $role );
		if ( count( $tags ) !== 1 ) {
			return null;
		}

		return $this->elementFragmentByOpeningTag( $html, $tags[0] );
	}

	private function elementFragmentByOpeningTag( string $html, string $opening_tag ): ?string
	{
		$at = strpos( $html, $opening_tag );
		if ( $at === false || preg_match( '/^<([a-zA-Z][a-zA-Z0-9]*)/', $opening_tag, $matches ) !== 1 ) {
			return null;
		}

		return $this->balancedFragment( $html, $at, strtolower( $matches[1] ) );
	}

	/** Depth-aware extraction starting at an opening tag, so nested same-name elements are handled. */
	private function balancedFragment( string $html, int $open_at, string $tag ): ?string
	{
		$open_end = strpos( $html, '>', $open_at );
		if ( $open_end === false ) {
			return null;
		}
		if ( substr( $html, $open_end - 1, 1 ) === '/' ) {
			return substr( $html, $open_at, $open_end - $open_at + 1 );
		}

		$depth  = 1;
		$pos    = $open_end + 1;
		$length = strlen( $html );
		while ( $depth > 0 && $pos < $length ) {
			$next_open  = stripos( $html, '<' . $tag, $pos );
			$next_close = stripos( $html, '</' . $tag, $pos );
			if ( $next_close === false ) {
				return null;
			}
			if ( $next_open !== false && $next_open < $next_close ) {
				$boundary = substr( $html, $next_open + strlen( $tag ) + 1, 1 );
				if ( $boundary === '' || preg_match( '/[\s>\/]/', $boundary ) === 1 ) {
					++$depth;
				}
				$pos = $next_open + strlen( $tag ) + 1;
				continue;
			}
			--$depth;
			$pos = $next_close + strlen( $tag ) + 2;
		}

		return $depth === 0 ? substr( $html, $open_at, $pos - $open_at ) : null;
	}

	private function attributeValue( string $tag, string $attribute ): ?string
	{
		if ( preg_match( '/\s' . preg_quote( $attribute, '/' ) . '="([^"]*)"/su', $tag, $matches ) !== 1 ) {
			return null;
		}

		return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private function hasBooleanAttribute( string $tag, string $attribute ): bool
	{
		return preg_match( '/\s' . preg_quote( $attribute, '/' ) . '(?=[\s\/>=])/iu', $tag ) === 1;
	}

	private function tagName( string $opening_tag ): string
	{
		return preg_match( '/^<([a-zA-Z][a-zA-Z0-9]*)/', $opening_tag, $matches ) === 1 ? strtolower( $matches[1] ) : '';
	}

	/** A landmark = <section>, or any element carrying role / aria-label / aria-labelledby. */
	private function isLandmarkElement( string $opening_tag ): bool
	{
		if ( $this->tagName( $opening_tag ) === 'section' ) {
			return true;
		}
		foreach ( [ 'role', 'aria-label', 'aria-labelledby' ] as $attribute ) {
			$value = $this->attributeValue( $opening_tag, $attribute );
			if ( $value !== null && trim( $value ) !== '' ) {
				return true;
			}
		}

		return false;
	}

	private function hasNonEmptyHeading( string $fragment ): bool
	{
		if ( preg_match_all( '/<h([2-6])\b[^>]*>(.*?)<\/h\1>/su', $fragment, $matches, PREG_SET_ORDER ) < 1 ) {
			return false;
		}
		foreach ( $matches as $heading ) {
			if ( $this->normalizeWhitespace( $this->textContent( (string) $heading[2] ) ) !== '' ) {
				return true;
			}
		}

		return false;
	}

	/** Accessible name: aria-label wins; otherwise the element's own text content. */
	private function accessibleName( string $container, string $opening_tag ): string
	{
		$aria_label = $this->attributeValue( $opening_tag, 'aria-label' );
		if ( $aria_label !== null && trim( $aria_label ) !== '' ) {
			return trim( $aria_label );
		}
		$fragment = $this->elementFragmentByOpeningTag( $container, $opening_tag );
		if ( $fragment === null ) {
			return '';
		}
		$tag_name = $this->tagName( $opening_tag );
		$inner    = preg_replace( '/^<[^>]*>/', '', $fragment, 1 );
		if ( $tag_name !== '' ) {
			$close_at = strrpos( (string) $inner, '</' . $tag_name );
			if ( $close_at !== false ) {
				$inner = substr( (string) $inner, 0, $close_at );
			}
		}

		return $this->normalizeWhitespace( $this->textContent( (string) $inner ) );
	}

	private function textContent( string $fragment ): string
	{
		return $this->normalizeWhitespace( html_entity_decode( strip_tags( $fragment ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	private function normalizeWhitespace( string $text ): string
	{
		$collapsed = preg_replace( '/\s+/u', ' ', $text );

		return trim( $collapsed === null ? $text : $collapsed );
	}

	/** Persian/Arabic-Indic digits → ASCII, so an exact numeric contract stays exact in any locale. */
	private function normalizeDigits( string $text ): string
	{
		return strtr(
			$text,
			[
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			]
		);
	}

	/**
	 * Raw payloads of <script type="application/json" class="…cpms-patient-portal__config…">.
	 *
	 * @return list<string>
	 */
	private function configScriptPayloads( string $html ): array
	{
		$payloads = [];
		if ( preg_match_all( '/<script\b([^>]*)>(.*?)<\/script>/su', $html, $matches, PREG_SET_ORDER ) < 1 ) {
			return $payloads;
		}
		foreach ( $matches as $script ) {
			$attributes = ' ' . $script[1];
			if ( preg_match( '/\stype="([^"]*)"/i', $attributes, $type ) !== 1 || strtolower( trim( $type[1] ) ) !== 'application/json' ) {
				continue;
			}
			if ( preg_match( '/\sclass="([^"]*)"/i', $attributes, $class ) !== 1 ) {
				continue;
			}
			$classes = preg_split( '/\s+/', trim( $class[1] ) );
			if ( $classes === false || ! in_array( self::CONFIG_CLASS, $classes, true ) ) {
				continue;
			}
			$payloads[] = (string) $script[2];
		}

		return $payloads;
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function assertNoAuthorityIdentifiers( array $config ): void
	{
		$forbidden = [
			'clinic_id',
			'patient_id',
			'user_id',
			'wp_user_id',
			'actor_id',
			'recipient',
			'recipient_id',
			'recipient_patient_id',
			'recipient_wp_user_id',
		];
		$stack = [ $config ];
		while ( $stack !== [] ) {
			$node = (array) array_pop( $stack );
			foreach ( $node as $key => $value ) {
				self::assertNotContains(
					strtolower( (string) $key ),
					$forbidden,
					'Slice 2 N2: the portal config must not expose "' . (string) $key
					. '" — recipient/ownership/Clinic authority is resolved server-side (G6 "نقش خود").'
				);
				if ( is_array( $value ) ) {
					$stack[] = $value;
				}
			}
		}
	}

	/**
	 * Every path-shaped string value published in the config (any nesting level).
	 *
	 * @param array<string, mixed> $config
	 * @return list<string>
	 */
	private function configPathValues( array $config ): array
	{
		$paths = [];
		$stack = [ $config ];
		while ( $stack !== [] ) {
			$node = (array) array_pop( $stack );
			foreach ( $node as $value ) {
				if ( is_array( $value ) ) {
					$stack[] = $value;
					continue;
				}
				if ( is_string( $value ) && str_starts_with( $value, '/' ) ) {
					$paths[] = $value;
				}
			}
		}

		return $paths;
	}

	/** Replaces client-side placeholders such as {id} so a published template can be route-matched. */
	private function concretePath( string $path ): string
	{
		$concrete = preg_replace( '/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', '123', $path );

		return $concrete === null ? $path : $concrete;
	}

	// =================================================================
	// URL composition (mirrors assets/js/cpms-patient-portal.js apiUrl()) + REST
	// =================================================================

	private function composeApiUrl( string $rest_root, string $path ): string
	{
		if ( str_contains( $rest_root, '?' ) && str_contains( $path, '?' ) ) {
			$path = (string) ( preg_replace( '/\?/', '&', $path, 1 ) ?? $path );
		}

		return $rest_root . $path;
	}

	/** Resolves the REST route a composed URL targets, under Plain (?rest_route=) or Pretty (/wp-json/) shape. */
	private function restRouteFromUrl( string $url ): string
	{
		$query = (string) ( wp_parse_url( $url, PHP_URL_QUERY ) ?? '' );
		if ( $query !== '' ) {
			parse_str( $query, $params );
			if ( isset( $params['rest_route'] ) && is_string( $params['rest_route'] ) ) {
				return '/' . ltrim( $params['rest_route'], '/' );
			}
		}
		$path   = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '' );
		$prefix = '/' . rest_get_url_prefix() . '/';
		$at     = strpos( $path, $prefix );
		if ( $at === false ) {
			return '';
		}

		return '/' . ltrim( substr( $path, $at + strlen( $prefix ) ), '/' );
	}

	/**
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function registeredRoutes(): array
	{
		return rest_get_server()->get_routes();
	}

	private function routeIsRegistered( string $path ): bool
	{
		if ( $path === '' ) {
			return false;
		}
		foreach ( array_keys( $this->registeredRoutes() ) as $route ) {
			// Same matching WP itself uses in WP_REST_Server::match_request_to_handler().
			if ( preg_match( '@^' . $route . '$@i', $path ) === 1 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalized HTTP methods of every handler registered for $path.
	 *
	 * Tolerates all shapes WP has used for `methods` (associative map keyed by the
	 * method name, plain list, or a single comma-separated string such as
	 * WP_REST_Server::READABLE = 'GET, HEAD, OPTIONS').
	 *
	 * @param array<string, mixed> $handler
	 * @return list<string>
	 */
	private function handlerMethods( array $handler ): array
	{
		$methods = $handler['methods'] ?? [];
		if ( is_string( $methods ) ) {
			$methods = [ $methods ];
		}
		if ( ! is_array( $methods ) ) {
			return [];
		}

		$tokens = [];
		foreach ( $methods as $key => $enabled ) {
			if ( is_string( $key ) && $enabled !== false ) {
				$tokens[] = $key;
			}
			if ( is_string( $enabled ) ) {
				$tokens[] = $enabled;
			}
		}

		$normalized = [];
		foreach ( $tokens as $token ) {
			foreach ( explode( ',', $token ) as $part ) {
				$part = strtoupper( trim( $part ) );
				if ( $part !== '' ) {
					$normalized[] = $part;
				}
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	private function routeAcceptsMethod( string $path, string $method ): bool
	{
		$wanted = strtoupper( $method );
		foreach ( $this->registeredRoutes() as $route => $handlers ) {
			if ( preg_match( '@^' . $route . '$@i', $path ) !== 1 ) {
				continue;
			}
			foreach ( (array) $handlers as $handler ) {
				if ( is_array( $handler ) && in_array( $wanted, $this->handlerMethods( $handler ), true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Dispatches a REST request as $user_id.
	 *
	 * @param array<string, mixed> $params
	 * @param string|null          $nonce 'auto' = a fresh wp_rest nonce; null = send none.
	 */
	private function restAs( int $user_id, string $method, string $route, array $params = [], ?string $nonce = 'auto' ): WP_REST_Response
	{
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		if ( $nonce === 'auto' ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		} elseif ( $nonce !== null ) {
			$request->set_header( 'X-WP-Nonce', $nonce );
		}

		return rest_do_request( $request );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function payload( WP_REST_Response $response ): array
	{
		$body = $response->get_data();
		if ( is_array( $body ) && array_key_exists( 'data', $body ) && is_array( $body['data'] ) ) {
			return $body['data'];
		}

		return is_array( $body ) ? $body : [];
	}

	// =================================================================
	// Row writers / readers (columns exist on live main — same sets as the Phase 7/8/9 RED suites)
	// =================================================================

	private function insertPatient( int $clinic_id, string $mobile, string $label ): int
	{
		$now = App::db()->nowUtcSql();

		return $this->insertRow(
			'cpms_patients',
			[
				'clinic_id'  => $clinic_id,
				'mrn'        => 'MR-P9S2-' . strtoupper( substr( bin2hex( random_bytes( 6 ) ), 0, 10 ) ),
				'first_name' => 'Portal',
				'last_name'  => 'Patient ' . $label,
				'mobile'     => $mobile,
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
			'patient(' . $label . ')'
		);
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string>         $formats
	 */
	private function insertRow( string $table, array $data, array $formats, string $label ): int
	{
		global $wpdb;
		$ok = $wpdb->insert( $wpdb->prefix . $table, $data, $formats );
		self::assertTrue( (bool) $ok, 'fixture ' . $label . ' insert failed: ' . $wpdb->last_error );
		$insert_id = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $insert_id, 'fixture ' . $label . ' id must be positive/dynamic.' );

		return $insert_id;
	}

	private function readAtOf( int $notification_id ): ?string
	{
		$row = App::db()->fetchRow(
			'SELECT read_at FROM ' . App::db()->table( 'cpms_notifications' ) . ' WHERE id = %d LIMIT 1',
			[ $notification_id ]
		);
		self::assertNotNull( $row, 'positive control: notification #' . $notification_id . ' exists.' );

		return $row['read_at'] === null ? null : (string) $row['read_at'];
	}

	/** Deterministic valid-shaped Iranian mobile per suffix (u_pat_mobile is UNIQUE per Clinic). */
	private function mobileFor( string $suffix ): string
	{
		$hash = substr( md5( 'p9s2-' . $suffix ), 0, 7 );
		$hash = strtr(
			$hash,
			[ 'a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6' ]
		);

		return '0919' . substr( $hash, 0, 7 );
	}

	private function ymdDaysOffset( int $days ): string
	{
		return gmdate( 'Y-m-d', time() + $days * 86400 );
	}
}
