<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Clinic\ClinicLifecycleState;
use PHPUnit\Framework\TestCase;

/**
 * The pure lifecycle decision table behind the NEW-participation gate.
 *
 * `cpms_clinics.status='suspended'` carries two meanings. The security contract is that
 * HELD (pending initial activation, authorized setup permitted) is established ONLY from
 * positive durable evidence, and that every missing/corrupt/ambiguous case fails CLOSED.
 * The absence of a record must never be read as authorization - that is the exact defect
 * this table closes.
 */
final class ClinicLifecycleStateTest extends TestCase
{
    private const PROVISIONED = ClinicLifecycleState::ACTION_PROVISIONED;
    private const REACTIVATED = ClinicLifecycleState::ACTION_REACTIVATED;
    private const DEACTIVATED = ClinicLifecycleState::ACTION_DEACTIVATED;

    /**
     * @param array<string, mixed>|null $latest
     */
    private function state(?string $status, ?array $latest): string
    {
        return ClinicLifecycleState::classify($status, $latest);
    }

    private function heldRecord(): array
    {
        return [
            'action'     => self::PROVISIONED,
            'meta_json'  => '{"location_id":7,"held":true}',
            'after_json' => '{"organization_id":3,"slug":"held-clinic","status":"suspended"}',
        ];
    }

    public function testActiveClinicAcceptsNewParticipation(): void
    {
        self::assertSame(ClinicLifecycleState::ACTIVE, $this->state('active', null));
        self::assertTrue(ClinicLifecycleState::accepts_new_membership(ClinicLifecycleState::ACTIVE));
    }

    public function testMissingStatusValueIsTheDocumentedPreMigrationActiveRow(): void
    {
        // Migration 2026_10_18_0029 defines status NOT NULL DEFAULT 'active'; a row with
        // no readable value is that legacy shape, not a suspension.
        self::assertSame(ClinicLifecycleState::ACTIVE, $this->state(null, null));
        self::assertSame(ClinicLifecycleState::ACTIVE, $this->state('', null));
        self::assertSame(ClinicLifecycleState::ACTIVE, $this->state('   ', null));
    }

    public function testUnknownStatusValueIsNeverReadPermissively(): void
    {
        foreach (['deleted', 'ACTIVE ', 'pending', '0'] as $garbage) {
            self::assertSame(
                ClinicLifecycleState::UNVERIFIED,
                $this->state($garbage, $this->heldRecord()),
                'an unknown status value must not be read as active or held: ' . $garbage
            );
        }
        self::assertFalse(ClinicLifecycleState::accepts_new_membership(ClinicLifecycleState::UNVERIFIED));
    }

    /**
     * THE AUDITED DEFECT: suspended + no lifecycle record used to be read as HELD, which
     * authorized new enrollment from the ABSENCE of evidence.
     */
    public function testSuspendedWithoutAnyLifecycleRecordIsUnverifiedAndRefuses(): void
    {
        self::assertSame(ClinicLifecycleState::UNVERIFIED, $this->state('suspended', null));
        self::assertFalse(ClinicLifecycleState::accepts_new_membership($this->state('suspended', null)));
    }

    public function testSuspendedAfterARecordedDeactivationIsOperationalAndRefuses(): void
    {
        $latest = ['action' => self::DEACTIVATED, 'meta_json' => '{"op":"clinic_deactivate"}', 'after_json' => '{"clinic.status":"suspended"}'];
        self::assertSame(ClinicLifecycleState::SUSPENDED_OPERATIONAL, $this->state('suspended', $latest));
        self::assertFalse(ClinicLifecycleState::accepts_new_membership(ClinicLifecycleState::SUSPENDED_OPERATIONAL));
    }

    public function testReactivationAsLatestRecordWhileSuspendedIsInconsistentAndRefuses(): void
    {
        $latest = ['action' => self::REACTIVATED, 'meta_json' => '{"op":"clinic_reactivate"}', 'after_json' => '{"clinic.status":"active"}'];
        self::assertSame(ClinicLifecycleState::UNVERIFIED, $this->state('suspended', $latest));
        self::assertFalse(ClinicLifecycleState::accepts_new_membership(ClinicLifecycleState::UNVERIFIED));
    }

    public function testPositiveHeldAssertionIsTheOnlyWayToBeHeld(): void
    {
        self::assertSame(ClinicLifecycleState::HELD, $this->state('suspended', $this->heldRecord()));
        self::assertTrue(ClinicLifecycleState::accepts_new_membership(ClinicLifecycleState::HELD));
    }

    public function testHeldToleratesOnlyEquivalentEncodingsOfTheSameAssertion(): void
    {
        $variants = [
            ['held' => true],
            ['held' => 1],
            ['held' => '1'],
            ['held' => 'true'],
        ];
        foreach ($variants as $i => $meta) {
            $record            = $this->heldRecord();
            $record['meta_json'] = json_encode($meta, JSON_UNESCAPED_UNICODE);
            self::assertSame(
                ClinicLifecycleState::HELD,
                $this->state('suspended', $record),
                'encoding variant ' . $i . ' of the same positive held assertion'
            );
        }
    }

    public function testProvisioningWithoutTheHeldAssertionIsNotHeld(): void
    {
        // provision() (active immediately) writes no held key and no status in after_json.
        $record = [
            'action'     => self::PROVISIONED,
            'meta_json'  => '{"location_id":7}',
            'after_json' => '{"organization_id":3,"slug":"plain-clinic","timezone":"Asia/Tehran"}',
        ];
        self::assertSame(ClinicLifecycleState::SUSPENDED_OPERATIONAL, $this->state('suspended', $record));

        // An explicit held=false is likewise not a held assertion.
        $record['meta_json'] = '{"location_id":7,"held":false}';
        self::assertSame(ClinicLifecycleState::SUSPENDED_OPERATIONAL, $this->state('suspended', $record));
    }

    public function testHeldIntentWithoutTheRecordedSuspendedStatusIsNotHeld(): void
    {
        $record               = $this->heldRecord();
        $record['after_json'] = '{"organization_id":3,"slug":"held-clinic"}';
        self::assertSame(ClinicLifecycleState::SUSPENDED_OPERATIONAL, $this->state('suspended', $record));

        $record['after_json'] = '{"organization_id":3,"status":"active"}';
        self::assertSame(ClinicLifecycleState::SUSPENDED_OPERATIONAL, $this->state('suspended', $record));
    }

    public function testCorruptOrUnreadableEvidenceFailsClosed(): void
    {
        $corrupt = [
            ['meta_json' => '{"held":true', 'after_json' => '{"status":"suspended"}'],
            ['meta_json' => 'not json at all', 'after_json' => '{"status":"suspended"}'],
            ['meta_json' => null, 'after_json' => '{"status":"suspended"}'],
            ['meta_json' => '', 'after_json' => '{"status":"suspended"}'],
            ['meta_json' => '{"held":true}', 'after_json' => '{"status":"suspended"'],
            ['meta_json' => '[]', 'after_json' => '{"status":"suspended"}'],
            ['meta_json' => '{"held":true}', 'after_json' => null],
        ];
        foreach ($corrupt as $i => $json) {
            $record               = $this->heldRecord();
            $record['meta_json']  = $json['meta_json'];
            $record['after_json'] = $json['after_json'];
            self::assertSame(
                ClinicLifecycleState::UNVERIFIED,
                $this->state('suspended', $record),
                'corrupt evidence variant ' . $i . ' must fail closed'
            );
        }
    }

    public function testRecordWithoutAnActionOrAnUnrelatedActionIsNotLifecycleEvidence(): void
    {
        self::assertSame(ClinicLifecycleState::UNVERIFIED, $this->state('suspended', ['meta_json' => '{"held":true}', 'after_json' => '{"status":"suspended"}']));

        $unrelated            = $this->heldRecord();
        $unrelated['action']  = 'CLINIC_PROFILE_UPDATED';
        self::assertSame(ClinicLifecycleState::UNVERIFIED, $this->state('suspended', $unrelated));

        $manager               = $this->heldRecord();
        $manager['action']     = 'ORGANIZATION_CLINIC_MANAGER_DESIGNATED';
        self::assertSame(ClinicLifecycleState::UNVERIFIED, $this->state('suspended', $manager));
    }

    public function testOnlyTheWritersLifecycleVocabularyIsConsulted(): void
    {
        self::assertSame(
            [self::PROVISIONED, self::REACTIVATED, self::DEACTIVATED],
            ClinicLifecycleState::lifecycle_actions()
        );
        self::assertSame('ORGANIZATION_CLINIC_PROVISIONED', self::PROVISIONED);
        self::assertSame('CLINIC_REACTIVATED', self::REACTIVATED);
        self::assertSame('CLINIC_DEACTIVATED', self::DEACTIVATED);
    }

    public function testExactlyTwoStatesAcceptNewParticipation(): void
    {
        $accepted = [];
        foreach ([ClinicLifecycleState::ACTIVE, ClinicLifecycleState::HELD, ClinicLifecycleState::SUSPENDED_OPERATIONAL, ClinicLifecycleState::UNVERIFIED] as $state) {
            if (ClinicLifecycleState::accepts_new_membership($state)) {
                $accepted[] = $state;
            }
        }
        self::assertSame([ClinicLifecycleState::ACTIVE, ClinicLifecycleState::HELD], $accepted);
        // An unknown state string is never accepted.
        self::assertFalse(ClinicLifecycleState::accepts_new_membership(''));
        self::assertFalse(ClinicLifecycleState::accepts_new_membership('held '));
    }
}
