<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Type;

use Hypervel\Http\RedirectResponse;

class CustomRedirectResponse extends RedirectResponse
{
    /**
     * Get a value through a native method whose name starts with "with".
     */
    public function withFlag(): string
    {
        return 'flag';
    }
}
