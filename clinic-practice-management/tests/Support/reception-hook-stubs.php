<?php

declare(strict_types=1);

// Loaded only in an isolated unit-test process. Deliberately do not define
// add_submenu_page(): WordPress does not load it during ordinary plugin boot.
$GLOBALS['cpms_reception_test_hooks'] = [];

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['cpms_reception_test_hooks'][$hook][] = $callback;
    return true;
}
