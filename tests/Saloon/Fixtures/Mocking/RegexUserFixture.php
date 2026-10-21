<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Mocking;

use Hypervel\Saloon\Http\Faking\Fixture;

class RegexUserFixture extends Fixture
{
    /**
     * Define the name of the fixture.
     */
    protected function defineName(): string
    {
        return 'user';
    }

    /**
     * Define regex patterns that should be replaced.
     */
    protected function defineSensitiveRegexPatterns(): array
    {
        return [
            // Twitter Handle
            '/@[a-z0-9_]{0,100}/' => '**REDACTED-TWITTER**',
            // The name Sam
            '/Sam/' => fn (string $value): string => substr_replace($value, 'xxx', 1),
        ];
    }
}
