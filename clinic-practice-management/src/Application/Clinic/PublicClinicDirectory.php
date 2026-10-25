<?php

/**
 * Phase 8 Slice 4 — Public Clinic directory (server-rendered discovery).
 *
 * ============================================================================
 * Why this class exists
 * ============================================================================
 *
 * The public booking shortcode historically accepted ONE explicit Clinic
 * (`[cpms_public_booking clinic_id="N"]`). A visitor therefore had to already
 * know which Clinic to book. The discovery mode introduced with this slice
 * (`clinic_id="all"`) needs a bounded, server-authoritative list of Clinics
 * that are allowed to be publicly advertised — plus the eligible clinicians of
 * each of them — WITHOUT adding a public REST endpoint and WITHOUT ever
 * falling back to "every active Clinic".
 *
 * ============================================================================
 * Public-listing consent policy (FAIL-CLOSED, per-Clinic, auditable)
 * ============================================================================
 *
 * A Clinic is listed only when ALL of the following hold:
 *
 *  1. `cpms_settings['public.listing_opt_in']` of THAT Clinic is explicitly a
 *     true value (`true`, `1`, `"1"`, `"on"`, `"yes"`). The key defaults to
 *     `false` (Settings::DEFAULTS) and an absent row means "not listed".
 *  2. The Clinic row has `status = 'active'` (migration 0029 lifecycle).
 *  3. The owning Organization has `status = 'active'`.
 *  4. At least one `cpms_locations` row of that Clinic has `is_active = 1`.
 *  5. At least one eligible ACTIVE clinician (home Clinic or active durable
 *     participation) can actually be booked in that Clinic.
 *
 * Reasons 2–5 alone are NOT consent: an active, healthy Clinic stays private
 * until its own configuration row opts in. That is the smallest maintainable
 * mechanism consistent with ADR-0031 AD-13 (no implicit tenant selection) and
 * with the existing Clinic-scoped `cpms_settings` table — no migration, no new
 * public endpoint, and the switch is a normal audited Settings write.
 *
 * ============================================================================
 * Data minimisation
 * ============================================================================
 *
 * Only publication-safe fields leave this class: Clinic id/name/timezone and,
 * per clinician, id, `full_name`, `specialty` and the set of active working
 * weekdays IN THAT CLINIC. No patient data, no internal identifiers beyond the
 * booking-contract ids the public surface already uses, no schedule-exception
 * reasons, no home-Clinic identity of a shared clinician, no contact details.
 *
 * Ordering is deterministic (`name`, then `id`) and every query is bounded, so
 * the rendered page has a predictable, capped size.
 */

declare(strict_types=1);

namespace ClinicCore\Application\Clinic;

use ClinicCore\Bootstrap\App;
use Throwable;

/**
 * Bounded, consent-gated public directory of Clinics and their clinicians.
 */
final class PublicClinicDirectory
{
	/** Per-Clinic consent key (see class docblock). */
	public const CONSENT_SETTING = 'public.listing_opt_in';

	/** Hard bound on the number of publicly listed Clinics. */
	public const MAX_CLINICS = 20;

	/** Hard bound on clinicians published per Clinic. */
	public const MAX_CLINICIANS_PER_CLINIC = 100;

	/**
	 * INTERIM SAFETY GATE — shared (non-home-Clinic) clinicians are NOT
	 * advertised in the public DIRECTORY yet.
	 *
	 * The directory is only truthful if the public booking contracts can book a
	 * clinician in the SELECTED Clinic. Today A1/A4/B1 derive the Clinic from
	 * `cpms_clinicians.clinic_id` (the clinician's HOME Clinic); the optional
	 * `clinic_id` scoping those contracts need is NOT implemented in this
	 * change set. Listing a shared clinician here would therefore show Clinic B
	 * while quoting/holding against Clinic A — a misleading and unsafe
	 * affordance.
	 *
	 * Until that scoping lands (see the PR report's "Remaining risks"), the
	 * directory deliberately falls back to home-Clinic clinicians only. The
	 * participation rule itself is already implemented and used by the
	 * explicitly bound single-Clinic surface, which is safe because the
	 * shortcode's Clinic attribute and the clinician's home Clinic can only
	 * differ for a shared clinician — and such a clinician is not published
	 * there either until this flag is turned on.
	 *
	 * Flip to true in the SAME change that adds server-side `clinic_id` scoping
	 * to A1/A4/A2/B1, together with the cross-Clinic tests.
	 */
	public const PUBLIC_SHARED_CLINICIAN_LISTING = false;

	/** Persian weekday names, indexed by ISO-8601 weekday number (1 = Monday). */
	public const WEEKDAY_LABELS = [
		1 => 'دوشنبه',
		2 => 'سه‌شنبه',
		3 => 'چهارشنبه',
		4 => 'پنجشنبه',
		5 => 'جمعه',
		6 => 'شنبه',
		7 => 'یک‌شنبه',
	];

	/**
	 * Clinics explicitly opted in to public listing, with their clinicians.
	 *
	 * @return array{
	 *   clinics: list<array{id:int,name:string,timezone:string,clinicians:list<array{id:int,name:string,specialty:string,weekdays:list<int>}>}>,
	 *   specialties: list<string>
	 * }
	 */
	public function listed_clinics(int $limit = self::MAX_CLINICS): array
	{
		$limit = max(1, min($limit, self::MAX_CLINICS));

		try {
			$candidates = $this->candidate_clinics($limit);
		} catch (Throwable $e) {
			$this->log_failure($e);

			return ['clinics' => [], 'specialties' => []];
		}

		if ($candidates === []) {
			return ['clinics' => [], 'specialties' => []];
		}

		$consent = $this->consent_map(array_column($candidates, 'id'));
		$ids = [];
		foreach ($candidates as $candidate) {
			if ($consent[(int) $candidate['id']] === true) {
				$ids[] = (int) $candidate['id'];
			}
		}
		if ($ids === []) {
			return ['clinics' => [], 'specialties' => []];
		}

		try {
			$byClinic = $this->clinicians_by_clinic($ids);
		} catch (Throwable $e) {
			$this->log_failure($e);

			return ['clinics' => [], 'specialties' => []];
		}

		$clinics = [];
		$specialties = [];
		foreach ($candidates as $candidate) {
			$clinic_id = (int) $candidate['id'];
			$clinicians = $byClinic[$clinic_id] ?? [];
			if ($clinicians === []) {
				// Consent without a bookable clinician advertises nothing: an
				// empty public listing is noise, not discovery.
				continue;
			}
			foreach ($clinicians as $clinician) {
				if ($clinician['specialty'] !== '') {
					$specialties[$clinician['specialty']] = true;
				}
			}
			$clinics[] = [
				'id' => $clinic_id,
				'name' => (string) $candidate['name'],
				'timezone' => (string) ($candidate['timezone'] ?? ''),
				'clinicians' => $clinicians,
			];
		}

		$specialty_list = array_keys($specialties);
		sort($specialty_list, SORT_STRING);

		return ['clinics' => $clinics, 'specialties' => array_values($specialty_list)];
	}

	/**
	 * Eligible active clinicians of ONE Clinic (home-Clinic or active durable
	 * participation). NO consent gate here: an explicitly bound shortcode
	 * (`[cpms_public_booking clinic_id="N"]`) is the publication consent for
	 * that single Clinic, exactly as before this slice. Consent gating belongs
	 * to the directory listing above.
	 *
	 * @return list<array{id:int,name:string,specialty:string,weekdays:list<int>}>
	 */
	public function clinicians_for_clinic(int $clinic_id): array
	{
		if ($clinic_id <= 0) {
			return [];
		}

		try {
			$map = $this->clinicians_by_clinic([$clinic_id]);
		} catch (Throwable $e) {
			$this->log_failure($e);

			return [];
		}

		return $map[$clinic_id] ?? [];
	}

	/**
	 * Consent value of one Clinic (public, read-only helper for tests/admin).
	 */
	public function is_listed(int $clinic_id): bool
	{
		if ($clinic_id <= 0) {
			return false;
		}

		try {
			$row = App::db()->fetchRow(
				'SELECT value_json FROM ' . App::db()->table('cpms_settings')
				. ' WHERE clinic_id = %d AND `key` = %s LIMIT 1',
				[$clinic_id, self::CONSENT_SETTING]
			);
		} catch (Throwable $e) {
			// Fail closed: an unreadable consent row is never "consented".
			$this->log_failure($e);

			return false;
		}

		return $row === null ? false : self::normalize_consent($row['value_json'] ?? null);
	}

	/**
	 * Normalise a `cpms_settings.value_json` payload to a strict boolean.
	 *
	 * Only explicit truthy values opt IN; everything else (absent, `false`,
	 * `"0"`, `"off"`, malformed JSON, arrays) stays OUT.
	 */
	public static function normalize_consent(mixed $raw): bool
	{
		if ($raw === true) {
			return true;
		}
		if ($raw === false || $raw === null) {
			return false;
		}
		if (is_int($raw)) {
			return $raw === 1;
		}
		if (is_array($raw)) {
			return false;
		}
		if (!is_string($raw)) {
			return false;
		}

		$decoded = json_decode($raw, true);
		if (is_bool($decoded)) {
			return $decoded;
		}
		if (is_int($decoded)) {
			return $decoded === 1;
		}

		$token = strtolower(trim($decoded === null ? $raw : (string) $decoded));

		return in_array($token, ['1', 'true', 'on', 'yes'], true);
	}

	/**
	 * Publication-safe Persian weekday label.
	 */
	public static function weekday_label(int $iso_weekday): string
	{
		return self::WEEKDAY_LABELS[$iso_weekday] ?? '';
	}

	/**
	 * Lifecycle-eligible Clinic rows (consent is applied separately so both
	 * gates stay independently testable).
	 *
	 * @return list<array{id:int,name:string,timezone:string}>
	 */
	private function candidate_clinics(int $limit): array
	{
		$db = App::db();

		$rows = $db->fetchAll(
			'SELECT c.id, c.name, c.timezone'
			. ' FROM ' . $db->table('cpms_clinics') . ' c'
			. ' INNER JOIN ' . $db->table('cpms_organizations') . ' o'
			. '   ON o.id = c.organization_id AND o.status = \'active\''
			. ' WHERE c.status = \'active\''
			. '   AND EXISTS (SELECT 1 FROM ' . $db->table('cpms_locations') . ' l'
			. '               WHERE l.clinic_id = c.id AND l.is_active = 1)'
			. ' ORDER BY c.name ASC, c.id ASC LIMIT %d',
			[$limit]
		);

		if (!is_array($rows)) {
			return [];
		}

		$clinics = [];
		foreach ($rows as $row) {
			$id = (int) ($row['id'] ?? 0);
			if ($id <= 0) {
				continue;
			}
			$clinics[] = [
				'id' => $id,
				'name' => trim((string) ($row['name'] ?? '')),
				'timezone' => trim((string) ($row['timezone'] ?? '')),
			];
		}

		return $clinics;
	}

	/**
	 * One bounded read for every consent flag (no N+1 per Clinic).
	 *
	 * @param list<int> $clinic_ids
	 * @return array<int, bool>
	 */
	private function consent_map(array $clinic_ids): array
	{
		$map = [];
		foreach ($clinic_ids as $id) {
			$map[(int) $id] = false;
		}

		$placeholders = implode(',', array_fill(0, count($clinic_ids), '%d'));
		$rows = App::db()->fetchAll(
			'SELECT clinic_id, value_json FROM ' . App::db()->table('cpms_settings')
			. ' WHERE `key` = %s AND clinic_id IN (' . $placeholders . ')',
			array_merge([self::CONSENT_SETTING], array_map('intval', $clinic_ids))
		);

		foreach ((array) $rows as $row) {
			$clinic_id = (int) ($row['clinic_id'] ?? 0);
			if ($clinic_id > 0) {
				$map[$clinic_id] = self::normalize_consent($row['value_json'] ?? null);
			}
		}

		return $map;
	}

	/**
	 * Eligible active clinicians per Clinic.
	 *
	 * "Eligible" = active clinician row whose HOME Clinic is this Clinic, or
	 * whose linked WordPress user holds an ACTIVE durable membership here
	 * (the same participation rule as MembershipRepository::clinician_participates_in).
	 * A shared clinician's home-Clinic identity is never published.
	 *
	 * @param list<int> $clinic_ids
	 * @return array<int, list<array{id:int,name:string,specialty:string,weekdays:list<int>}>>
	 */
	private function clinicians_by_clinic(array $clinic_ids): array
	{
		if ($clinic_ids === []) {
			return [];
		}

		$db = App::db();
		$placeholders = implode(',', array_fill(0, count($clinic_ids), '%d'));
		$args = array_map('intval', $clinic_ids);

		$home = $db->fetchAll(
			'SELECT c.id, c.clinic_id, c.full_name, c.specialty'
			. ' FROM ' . $db->table('cpms_clinicians') . ' c'
			. ' WHERE c.is_active = 1 AND c.clinic_id IN (' . $placeholders . ')'
			. ' ORDER BY c.full_name ASC, c.id ASC',
			$args
		);

		$shared = self::PUBLIC_SHARED_CLINICIAN_LISTING ? $db->fetchAll(
			'SELECT c.id, m.clinic_id, c.full_name, c.specialty'
			. ' FROM ' . $db->table('cpms_clinicians') . ' c'
			. ' INNER JOIN ' . $db->table('cpms_clinic_memberships') . ' m'
			. '   ON m.wp_user_id = c.wp_user_id AND m.status = \'active\''
			. ' WHERE c.is_active = 1 AND c.wp_user_id IS NOT NULL'
			. '   AND c.clinic_id <> m.clinic_id AND m.clinic_id IN (' . $placeholders . ')'
			. ' ORDER BY c.full_name ASC, c.id ASC',
			$args
		) : [];
		$shared = is_array($shared) ? $shared : [];

		$weekdays = $this->working_days($clinic_ids);

		$result = [];
		foreach ([$home, $shared] as $rows) {
			foreach ((array) $rows as $row) {
				$clinic_id = (int) ($row['clinic_id'] ?? 0);
				$clinician_id = (int) ($row['id'] ?? 0);
				if ($clinic_id <= 0 || $clinician_id <= 0) {
					continue;
				}
				$name = trim((string) ($row['full_name'] ?? ''));
				if ($name === '') {
					// An unnamed button is unusable; never publish it.
					continue;
				}
				if (isset($result[$clinic_id][$clinician_id])) {
					continue;
				}
				if (count($result[$clinic_id] ?? []) >= self::MAX_CLINICIANS_PER_CLINIC) {
					continue;
				}

				$result[$clinic_id][$clinician_id] = [
					'id' => $clinician_id,
					'name' => $name,
					'specialty' => trim((string) ($row['specialty'] ?? '')),
					'weekdays' => $weekdays[$clinic_id][$clinician_id] ?? [],
				];
			}
		}

		$ordered = [];
		foreach ($result as $clinic_id => $rows) {
			$list = array_values($rows);
			usort(
				$list,
				static fn (array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]
			);
			$ordered[(int) $clinic_id] = $list;
		}

		return $ordered;
	}

	/**
	 * Active working weekdays per (Clinic, clinician) — one bounded read.
	 *
	 * Only the weekday number is returned: schedule-exception reasons and any
	 * other internal scheduling detail stay private.
	 *
	 * @param list<int> $clinic_ids
	 * @return array<int, array<int, list<int>>>
	 */
	private function working_days(array $clinic_ids): array
	{
		if ($clinic_ids === []) {
			return [];
		}

		$db = App::db();
		$placeholders = implode(',', array_fill(0, count($clinic_ids), '%d'));

		$rows = $db->fetchAll(
			'SELECT DISTINCT clinic_id, clinician_id, day_of_week'
			. ' FROM ' . $db->table('cpms_schedule')
			. ' WHERE is_active = 1 AND clinic_id IN (' . $placeholders . ')'
			. ' ORDER BY clinic_id ASC, clinician_id ASC, day_of_week ASC',
			array_map('intval', $clinic_ids)
		);

		$map = [];
		foreach ((array) $rows as $row) {
			$clinic_id = (int) ($row['clinic_id'] ?? 0);
			$clinician_id = (int) ($row['clinician_id'] ?? 0);
			$day = (int) ($row['day_of_week'] ?? -1);
			if ($clinic_id <= 0 || $clinician_id <= 0 || $day < 0 || $day > 6) {
				continue;
			}
			$map[$clinic_id][$clinician_id][$day] = $day;
		}

		// Sort each weekday list ascending (Saturday-first Persian week order is
		// a presentation concern; the list stays a stable ISO-weekday set).
		$sorted = [];
		foreach ($map as $clinic_id => $clinicians) {
			foreach ($clinicians as $clinician_id => $days) {
				$list = array_values($days);
				sort($list, SORT_NUMERIC);
				$sorted[(int) $clinic_id][(int) $clinician_id] = $list;
			}
		}

		return $sorted;
	}

	private function log_failure(Throwable $e): void
	{
		try {
			App::op()->warning('CPMS_PUBLIC_DIRECTORY_FAILED', ['reason' => $e->getMessage()]);
		} catch (Throwable) {
			// A logging failure must never surface a Clinic that failed a gate.
		}
	}
}
