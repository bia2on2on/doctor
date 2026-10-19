<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\SecretaryFinancePage;
use ClinicCore\Auth\RolesAndCapabilities;
use WP_UnitTestCase;

/**
 * HTML-validity regression (independent review, Priority 2): a real
 * calendar-date `<input type="date">` enhanced by cpms-jalali-date.js must
 * never be a DESCENDANT of a `<label>` element. The script moves the native
 * input (plus its own "شمسی:" companion `<label>`) into a new wrapper placed
 * where the input used to sit; if that used to be INSIDE an admin page's own
 * `<label>...</label>` text wrapper, the result is an invalid nested
 * `<label>` inside a `<label>`. The fix is structural: the admin page must
 * associate its label via `for`/`id` as a SIBLING of the input, never a
 * parent of it.
 */
final class JalaliDateInputMarkupTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Secretary role: has FINANCE_READ (required by SecretaryFinancePage::
        // render()'s capability gate) without needing a Clinic-scope fixture.
        wp_set_current_user(self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_SECRETARY]));
    }

    protected function tearDown(): void
    {
        unset($_GET['visit']);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testFinanceFromAndToDateInputsAreNotNestedInsideTheirLabels(): void
    {
        ob_start();
        SecretaryFinancePage::render();
        $html = (string) ob_get_clean();
        self::assertNotSame('', $html, 'precondition: the finance page rendered');

        foreach (['cpms-fin-from', 'cpms-fin-to'] as $id) {
            $inputPos = strpos($html, 'id="' . $id . '"');
            self::assertNotFalse($inputPos, "precondition: the {$id} date input is present");

            $preceding      = substr($html, 0, $inputPos);
            $lastLabelOpen  = strripos($preceding, '<label');
            $lastLabelClose = strripos($preceding, '</label>');
            self::assertNotFalse($lastLabelOpen, "a <label for=\"{$id}\"> must exist before the {$id} input");
            self::assertTrue(
                $lastLabelClose !== false && $lastLabelClose > $lastLabelOpen,
                "the {$id} input must NOT be nested inside its <label> (the label must already be closed before the input)"
            );
            self::assertStringContainsString(
                '<label for="' . $id . '">',
                $html,
                "the {$id} field must use an explicit for/id association instead of wrapping the input"
            );
        }
    }
}
