<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/bin/upgrade-path-schema-compare.php';

/**
 * Focused regression evidence for the upgrade-path schema comparison.
 *
 * The pre-existing comparison required strict equality between the pre-upgrade
 * and post-upgrade snapshots of the shared application tables, conflating
 * "pre-existing structures changed" (must fail closed) with "a reviewed
 * migration added a nullable column" (the normal forward-migration shape —
 * e.g. cpms_clinics.first_setup_claimed_at from 2026_10_11_0026, which is what
 * made the Pilot/Staging upgrade path fail). These cases pin both halves of
 * the corrected contract: additive evolution is accepted ONLY when it matches
 * the reviewed pin exactly, and every damage class still fails closed.
 */
final class UpgradePathSchemaCompareTest extends TestCase
{
    /** @var array<string, array<string, array{type: string, nullable: string}>> */
    private const PREVIOUS = [
        'cpms_clinics' => [
            'id'         => ['type' => 'bigint unsigned', 'nullable' => 'NO'],
            'name'       => ['type' => 'varchar(190)', 'nullable' => 'NO'],
            'updated_at' => ['type' => 'datetime(3)', 'nullable' => 'NO'],
        ],
    ];

    /** @var array<string, array<string, array{type: string, nullable: string}>> */
    private const REVIEWED_ADDITIONS = [
        'cpms_clinics' => [
            'first_setup_claimed_at' => ['type' => 'datetime(3)', 'nullable' => 'YES'],
        ],
    ];

    public function testUnchangedSharedSchemaIsAccepted(): void
    {
        self::assertNull(cpms_upgrade_schema_compare(self::PREVIOUS, self::PREVIOUS, []));
    }

    /**
     * The actual cause: migration 0026's reviewed additive marker column must
     * be accepted while the previous columns stay byte-identical.
     */
    public function testReviewedAdditiveMarkerColumnIsAccepted(): void
    {
        $current = self::PREVIOUS;
        $current['cpms_clinics']['first_setup_claimed_at'] = ['type' => 'datetime(3)', 'nullable' => 'YES'];

        self::assertNull(cpms_upgrade_schema_compare(self::PREVIOUS, $current, self::REVIEWED_ADDITIONS));
    }

    public function testRemovedPreExistingColumnFailsClosed(): void
    {
        $current = self::PREVIOUS;
        unset($current['cpms_clinics']['name']);

        $error = cpms_upgrade_schema_compare(self::PREVIOUS, $current, []);
        self::assertIsString($error);
        self::assertStringContainsString('removed or reordered', $error);
    }

    public function testRetypedPreExistingColumnFailsClosed(): void
    {
        $current = self::PREVIOUS;
        $current['cpms_clinics']['name'] = ['type' => 'varchar(255)', 'nullable' => 'NO'];

        $error = cpms_upgrade_schema_compare(self::PREVIOUS, $current, []);
        self::assertIsString($error);
        self::assertStringContainsString('changed definition', $error);
    }

    public function testNullabilityDriftOnPreExistingColumnFailsClosed(): void
    {
        $current = self::PREVIOUS;
        $current['cpms_clinics']['updated_at'] = ['type' => 'datetime(3)', 'nullable' => 'YES'];

        $error = cpms_upgrade_schema_compare(self::PREVIOUS, $current, []);
        self::assertIsString($error);
        self::assertStringContainsString('changed definition', $error);
    }

    public function testReorderedPreExistingColumnsFailClosed(): void
    {
        $current = [
            'cpms_clinics' => [
                'updated_at' => self::PREVIOUS['cpms_clinics']['updated_at'],
                'id'         => self::PREVIOUS['cpms_clinics']['id'],
                'name'       => self::PREVIOUS['cpms_clinics']['name'],
            ],
        ];

        $error = cpms_upgrade_schema_compare(self::PREVIOUS, $current, []);
        self::assertIsString($error);
        self::assertStringContainsString('removed or reordered', $error);
    }

    public function testUnexpectedAdditiveColumnFailsClosed(): void
    {
        $current = self::PREVIOUS;
        $current['cpms_clinics']['rogue_column'] = ['type' => 'int', 'nullable' => 'YES'];

        $error = cpms_upgrade_schema_compare(self::PREVIOUS, $current, self::REVIEWED_ADDITIONS);
        self::assertIsString($error);
        self::assertStringContainsString('unexpected new column', $error);
    }

    public function testMisshapedReviewedAdditiveColumnFailsClosed(): void
    {
        $current = self::PREVIOUS;
        $current['cpms_clinics']['first_setup_claimed_at'] = ['type' => 'varchar(32)', 'nullable' => 'YES'];

        $error = cpms_upgrade_schema_compare(self::PREVIOUS, $current, self::REVIEWED_ADDITIONS);
        self::assertIsString($error);
        self::assertStringContainsString('wrong definition', $error);
    }

    public function testMissingReviewedAdditiveColumnFailsClosed(): void
    {
        // The candidate never applied its migration: nothing drifted, but the
        // reviewed additive column is absent — that must still fail closed.
        $error = cpms_upgrade_schema_compare(self::PREVIOUS, self::PREVIOUS, self::REVIEWED_ADDITIONS);
        self::assertIsString($error);
        self::assertStringContainsString('missing after upgrade', $error);
    }

    public function testDisappearedSharedTableFailsClosed(): void
    {
        $error = cpms_upgrade_schema_compare(self::PREVIOUS, [], []);
        self::assertIsString($error);
        self::assertStringContainsString('disappeared', $error);
    }
}
