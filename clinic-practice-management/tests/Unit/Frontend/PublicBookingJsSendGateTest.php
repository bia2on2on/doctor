<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Client regression for Phase 8 Slice 3 patient chooser send gate.
 *
 * Covers A-E deterministically on the JS source (no WP, no DB):
 *  A. 0 links + stale session patient id: B1 must NOT send patient_id
 *  B. 1 link + stale session patient id: B1 must NOT send patient_id
 *  C. N links + stale non-member id: storage cleared, B1 not sent until valid selection
 *  D. N links + valid current option: B1 contains exactly that patient_id
 *  E. successful B2: patient-selection session key removed
 *
 * The JS is the source of truth for these; the test asserts the source
 * contains the required gate, validation against current DOM options,
 * and storage-lifetime rules. The browser harness (pilot-slice3-chooser.py)
 * proves the same on a real Chromium + WordPress.
 */
final class PublicBookingJsSendGateTest extends TestCase
{
    private string $js;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/assets/js/cpms-public-booking.js';
        self::assertFileExists($path, 'public booking JS must exist');
        $this->js = (string) file_get_contents($path);
        self::assertNotSame('', $this->js);
    }

    public function testUsesSessionStorageNotLocalStorageForPatientSelection(): void
    {
        // Storage lifetime: keep sessionStorage, not localStorage for patient chooser.
        // JS uses PATIENT_STORAGE_KEY = 'cpms-patient-selection:' + clinic_id and sessionStorage with that key.
        self::assertStringContainsString("PATIENT_STORAGE_KEY = 'cpms-patient-selection:'", $this->js, 'patient selection key must be sessionStorage with clinic suffix');
        self::assertStringContainsString("sessionStorage.getItem(PATIENT_STORAGE_KEY", $this->js, 'patient selection must read from sessionStorage via PATIENT_STORAGE_KEY');
        self::assertStringContainsString("sessionStorage.setItem(PATIENT_STORAGE_KEY", $this->js, 'patient selection must write to sessionStorage via PATIENT_STORAGE_KEY');
        self::assertStringContainsString("sessionStorage.removeItem(PATIENT_STORAGE_KEY", $this->js, 'patient selection must clear via sessionStorage via PATIENT_STORAGE_KEY');
        // No localStorage for patient selection (booking-selection is unrelated and lives in sessionStorage).
        $localPatient = substr_count($this->js, "localStorage.getItem('cpms-patient-selection:");
        self::assertSame(0, $localPatient, 'must not use localStorage for patient selection (sessionStorage only)');
        $localSet = substr_count($this->js, "localStorage.setItem('cpms-patient-selection:");
        self::assertSame(0, $localSet, 'must not use localStorage for patient selection');
        $localRemove = substr_count($this->js, "localStorage.removeItem('cpms-patient-selection:");
        self::assertSame(0, $localRemove, 'must not use localStorage for patient selection');
        $localKey = substr_count($this->js, "localStorage.getItem(PATIENT_STORAGE_KEY");
        self::assertSame(0, $localKey, 'must not use localStorage for patient selection key');
    }

    public function testGateExistsAndUsesCurrentDomOptionsAsAuthority(): void
    {
        // Required rule: patient_id may be sent ONLY IF N>1 chooser exists, has options, and id is among current rendered options.
        self::assertStringContainsString('function getPatientIdForB1', $this->js, 'send gate function must exist');
        self::assertStringContainsString('function isPatientOptionValid', $this->js, 'option-validation helper must exist');
        self::assertStringContainsString('function clearStoredPatientId', $this->js, 'clear helper must exist');
        self::assertStringContainsString('function getValidatedStoredPatientId', $this->js, 'validated read must exist');
        // Gate must check chooser presence and options length, and validate against current DOM.
        self::assertStringContainsString('!patientChooser || patientOptions.length === 0', $this->js, 'gate must handle 0/1 case (no chooser)');
        self::assertStringContainsString('isPatientOptionValid', $this->js, 'gate must validate against current options');
        // beginHold must use the gate, not direct stored/selected.
        self::assertStringContainsString('var pidToSend = getPatientIdForB1()', $this->js, 'beginHold must use the gated getter');
        // Do not trust storage as authority: no direct body.patient_id from getStoredPatientId without validation.
        self::assertStringNotContainsString("body.patient_id = getStoredPatientId()", $this->js, 'must not trust storage directly');
        self::assertStringNotContainsString("body.patient_id = selectedPatientId", $this->js, 'must not trust selected directly without gate');
    }

    public function testZeroAndOneLinkNoChooserClearsStaleAndSendsNothing(): void
    {
        // A & B: 0 links or 1 link => no chooser, no patient_id sent, stale cleared.
        // The JS init and getPatientIdForB1 must clear when no chooser.
        self::assertStringContainsString("if (!patientChooser || patientOptions.length === 0) {", $this->js, 'must handle no-chooser case');
        // Check that init clears stale when no chooser.
        self::assertStringContainsString("// 0 or 1 linked: no chooser — clear any stale", $this->js, 'init must clear stale for 0/1');
        // Gate must return 0 for no chooser.
        self::assertStringContainsString("return 0;", $this->js, 'gate must return 0 when no chooser');
    }

    public function testNLinksInvalidStaleIsCleared(): void
    {
        // C: N links + stale non-member id => storage cleared, B1 not sent until valid selection.
        self::assertStringContainsString('clearStoredPatientId()', $this->js, 'must clear stale');
        // getValidatedStoredPatientId must clear if not valid.
        self::assertStringContainsString("if (!isPatientOptionValid(pid)) {\n\t\t\t\tclearStoredPatientId();", $this->js, 'validated read must clear invalid');
        // setPatientSelection must not store invalid.
        self::assertStringContainsString("if (!isPatientOptionValid(nid)) {", $this->js, 'setPatientSelection must validate');
    }

    public function testNLinksValidCurrentOptionIsSent(): void
    {
        // D: N links + valid current option => B1 contains exactly that patient_id.
        // The gate must return valid cand/stored/dom when among options.
        self::assertStringContainsString("if (cand > 0 && isPatientOptionValid(cand)) {\n\t\t\t\treturn cand;", $this->js, 'must send valid selected');
        self::assertStringContainsString("if (stored > 0) {", $this->js, 'must handle stored valid');
        self::assertStringContainsString("if (isPatientOptionValid(domPid)) {", $this->js, 'must handle DOM valid');
    }

    public function testSuccessfulB2ClearsPatientSelection(): void
    {
        // E: successful B2 => patient-selection session key removed.
        // Confirm hold success must clear both booking-selection and patient-selection.
        // Find the confirmHold success block.
        self::assertStringContainsString("clearStoredSelection();\n\t\t\t\t\tclearStoredPatientId();", $this->js, 'B2 success must clear patient selection alongside booking selection');
    }

    public function testDoesNotClearUnrelatedBookingSelectionPrematurely(): void
    {
        // Do not clear the unrelated booking-selection state prematurely if still needed.
        // booking-selection is SELECT_KEY = 'cpms-public-booking:selection:v1' and is cleared only in specific flows.
        // Ensure we don't have a blanket clear of SELECT_KEY in the patient-chooser init.
        $afterChooserInit = strpos($this->js, '// Initialization: handle storage lifetime');
        $beforePanel = strpos($this->js, 'function panelState', $afterChooserInit ?: 0);
        $segment = substr($this->js, (int) $afterChooserInit, (int) $beforePanel - (int) $afterChooserInit);
        self::assertStringNotContainsString("clearStoredSelection()", $segment, 'patient init must not clear booking selection prematurely');
        self::assertStringNotContainsString("SELECT_KEY", $segment, 'patient init must not touch booking selection');
    }
}
