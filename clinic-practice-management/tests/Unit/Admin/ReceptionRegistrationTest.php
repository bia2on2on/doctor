<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Admin;

use ClinicCore\Admin\ClinicReceptionRecoveryPage;
use PHPUnit\Framework\TestCase;

/** Boot must only register hooks; admin menu functions are not yet available. */
final class ReceptionRegistrationTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRegistrationWorksBeforeWordPressLoadsAdminMenuFunctions(): void
    {
        self::assertFalse(function_exists('add_submenu_page'));
        require dirname(__DIR__, 2) . '/Support/reception-hook-stubs.php';

        ClinicReceptionRecoveryPage::register();

        self::assertSame(
            [[ClinicReceptionRecoveryPage::class, 'menu']],
            $GLOBALS['cpms_reception_test_hooks']['admin_menu'] ?? null
        );
        self::assertSame(
            [[ClinicReceptionRecoveryPage::class, 'handleAssign']],
            $GLOBALS['cpms_reception_test_hooks']['admin_post_cpms_reception_recovery_assign'] ?? null
        );
    }
}
