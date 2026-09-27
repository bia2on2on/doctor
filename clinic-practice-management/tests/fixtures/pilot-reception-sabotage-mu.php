<?php
/**
 * TEST-ONLY pilot infrastructure — NOT part of the shipped plugin.
 *
 * Installed into mu-plugins by tests/fixtures/pilot-reception-fixture.php on
 * the disposable Pilot CI WordPress only. build-release.sh ships no tests/ or
 * pilot-* path and fails the build if any appears in its manifest, so this
 * file can never leak into a release.
 *
 * Purpose: cookie-triggered failure of EXACTLY the enqueue transition UPDATE
 * (table cpms_visits, value 'waiting') by throwing — the same failure mode the
 * established machine/lock guards produce — so the real-browser journey can
 * witness a partial arrival (check-in committed, enqueue failed) and its
 * recovery without any production test hook. Requests without the rp_sabotage
 * cookie are untouched, byte for byte.
 */

add_filter(
	'query',
	static function ($q) {
		if (!is_string($q) || ($_COOKIE['rp_sabotage'] ?? '') !== '1') {
			return $q;
		}
		if (preg_match('/^\s*UPDATE\b/i', $q) && false !== strpos($q, 'cpms_visits') && false !== strpos($q, "'waiting'")) {
			throw new RuntimeException('rp-sabotage: simulated enqueue failure');
		}
		return $q;
	}
);
