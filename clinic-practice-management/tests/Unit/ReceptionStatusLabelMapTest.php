<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Machine\VisitMachine;
use PHPUnit\Framework\TestCase;

/**
 * Item 5 — no raw English status inside Persian messages (reception family).
 *
 * The reception walk-in refusal composes «(وضعیت فعلی: X)» client-side through
 * the template's existing statusLabel() helper. Its map missed the post
 * consultation statuses, so a stored awaiting_payment fell through to the raw
 * enum. The map must cover every visit status from the state machine with a
 * Persian label, and the refusal must keep using that one helper (no second
 * map invented).
 */
final class ReceptionStatusLabelMapTest extends TestCase
{
    private static function templateSource(): string
    {
        $src = file_get_contents(
            dirname(__DIR__, 2) . '/templates/staff-reception.php'
        );
        self::assertIsString($src, 'templates/staff-reception.php must be readable');

        return $src;
    }

    /**
     * @return array<string, string> status => Persian label from statusLabel().
     */
    private static function statusLabelMap(): array
    {
        $src = self::templateSource();
        self::assertSame(
            1,
            preg_match('/function statusLabel\(status\) \{(.*?)\};/s', $src, $m),
            'statusLabel() helper must exist in staff-reception.php'
        );
        // The outer match consumed the first `};`, i.e. the end of the map
        // itself — so inside $m[1] the map runs from its opening `{` to the end.
        self::assertSame(
            1,
            preg_match('/var labels = \{(.*)$/s', (string) $m[1], $m2),
            'the single labels map must exist inside statusLabel()'
        );

        preg_match_all(
            '/([a-z_]+):\s*\'([^\']+)\'/u',
            (string) $m2[1],
            $pairs,
            PREG_SET_ORDER
        );

        $map = [];
        foreach ($pairs as $pair) {
            $map[$pair[1]] = $pair[2];
        }

        return $map;
    }

    public function testEveryVisitStatusHasAPersianLabel(): void
    {
        $statuses = [
            VisitMachine::CHECKED_IN, VisitMachine::WAITING, VisitMachine::CALLED,
            VisitMachine::IN_CONSULTATION, VisitMachine::CONSULTATION_COMPLETED,
            VisitMachine::AWAITING_PAYMENT, VisitMachine::PAID,
            VisitMachine::CHECKED_OUT, VisitMachine::CANCELLED, VisitMachine::SKIPPED,
        ];

        $map = self::statusLabelMap();
        foreach ($statuses as $status) {
            self::assertArrayHasKey($status, $map, 'statusLabel() must cover ' . $status);
            // The label must be Persian, never the raw enum token itself.
            self::assertNotSame($status, $map[$status]);
            self::assertSame(
                1,
                preg_match('/[\x{0600}-\x{06FF}]/u', $map[$status]),
                'label for ' . $status . ' must contain Persian text'
            );
        }

        // The audited combination: walk-in refusal with an awaiting_payment visit.
        self::assertSame('در انتظار پرداخت', $map[VisitMachine::AWAITING_PAYMENT]);
    }

    public function testWalkInRefusalComposesViaTheSingleLabelHelper(): void
    {
        $src = self::templateSource();

        self::assertStringContainsString(
            "' (وضعیت فعلی: ' + statusLabel(String(data.visit_status))",
            $src,
            'the walk-in refusal must localize visit_status through statusLabel()'
        );
        self::assertStringContainsString(
            'این بیمار امروز نزد همین پزشک مراجعهٔ فعال دارد',
            $src
        );
    }
}
