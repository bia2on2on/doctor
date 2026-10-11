<?php

declare(strict_types=1);

/**
 * Upgrade-path schema comparison — shared by upgrade-path-fixture.php (verify
 * mode) and the focused Unit regression test UpgradePathSchemaCompareTest.
 *
 * Contract enforced on the snapshotted shared application tables:
 *  - every pre-existing column survives with its exact definition and relative
 *    order (fail-closed on removal, retyping, nullability drift, reordering);
 *  - the ONLY tolerated drift is the explicitly reviewed additive evolution of
 *    the candidate ($expectedAdditions), pinned with its exact definition;
 *  - anything else fails closed: an unexpected new column, a mis-shaped
 *    reviewed column, a missing reviewed column, or a disappeared table.
 *
 * This is the normalized form of the previous strict snapshot equality, which
 * conflated "pre-existing structures changed" (must fail) with "a reviewed
 * migration added a nullable column" (the standard forward-migration shape,
 * e.g. cpms_clinics.first_setup_claimed_at from 2026_10_11_0026).
 *
 * @param array<string, array<string, array{type: string, nullable: string}>> $previous         Snapshot at the pinned previous schema.
 * @param array<string, array<string, array{type: string, nullable: string}>> $current          Snapshot after the candidate migrated forward.
 * @param array<string, array<string, array{type: string, nullable: string}>> $expectedAdditions Reviewed additive columns of the candidate.
 *
 * @return string|null null when the drift is exactly the reviewed additive set; otherwise the precise reason.
 */
function cpms_upgrade_schema_compare(array $previous, array $current, array $expectedAdditions): ?string
{
    foreach ($previous as $table => $previousColumns) {
        if (!isset($current[$table]) || !is_array($current[$table])) {
            return "shared table {$table} disappeared during upgrade";
        }
        $currentColumns = $current[$table];

        // 1) Pre-existing columns are preserved exactly and in their original order.
        $previousNames  = array_keys($previousColumns);
        $preservedNames = array_values(array_filter(
            array_keys($currentColumns),
            static function ($name) use ($previousColumns): bool {
                return array_key_exists((string) $name, $previousColumns);
            }
        ));
        if ($preservedNames !== $previousNames) {
            return "pre-existing columns of {$table} were removed or reordered: expected ["
                . implode(', ', $previousNames) . '], found [' . implode(', ', $preservedNames) . ']';
        }

        foreach ($previousColumns as $name => $definition) {
            if ($currentColumns[$name] !== $definition) {
                return "pre-existing column {$table}.{$name} changed definition: expected ["
                    . json_encode($definition) . '], found [' . json_encode($currentColumns[$name]) . ']';
            }
        }

        // 2) New columns must be exactly the reviewed additive set.
        $expectedForTable = $expectedAdditions[$table] ?? [];
        foreach ($currentColumns as $name => $definition) {
            if (array_key_exists((string) $name, $previousColumns)) {
                continue;
            }
            if (!array_key_exists((string) $name, $expectedForTable)) {
                return "unexpected new column {$table}.{$name} appeared during upgrade";
            }
            $expected = $expectedForTable[$name];
            if (
                strtolower((string) ($definition['type'] ?? '')) !== strtolower((string) ($expected['type'] ?? ''))
                || (string) ($definition['nullable'] ?? '') !== (string) ($expected['nullable'] ?? '')
            ) {
                return "reviewed additive column {$table}.{$name} has the wrong definition: expected ["
                    . json_encode($expected) . '], found [' . json_encode($definition) . ']';
            }
        }

        // 3) The reviewed additive set must actually be present (fail closed if
        //    the candidate never applied its migration).
        foreach ($expectedForTable as $name => $definition) {
            if (!array_key_exists((string) $name, $currentColumns)) {
                return "reviewed additive column {$table}.{$name} is missing after upgrade";
            }
        }
    }

    return null;
}
