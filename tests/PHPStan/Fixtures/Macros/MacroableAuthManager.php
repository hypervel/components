<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Macros;

use Hypervel\Auth\AuthManager;
use Hypervel\Support\Traits\Macroable;

/**
 * An auth manager whose Macroable __call() runs its own macros instead of forwarding to the guard.
 */
class MacroableAuthManager extends AuthManager
{
    use Macroable;
}
