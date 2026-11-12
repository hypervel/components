<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Integration;

/**
 * Render existing and missing views through the view() helper.
 */
function viewHelper(): void
{
    view('home');
    view('home-does-not-exist');
    view();
}
