<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Mocking;

use Hypervel\Saloon\Http\Faking\Fixture;

class SafeUserFixture extends Fixture
{
    /**
     * Define the name of the fixture.
     */
    protected function defineName(): string
    {
        return 'user';
    }

    /**
     * Define the sensitive headers.
     */
    protected function defineSensitiveHeaders(): array
    {
        return [
            'Server' => 'secret',

            // Check for case-insensitive
            'cache-control' => function (string $value): string {
                return $value . ', yeehaw';
            },
        ];
    }

    /**
     * Swap any sensitive JSON parameters.
     */
    protected function defineSensitiveJsonParameters(): array
    {
        return [
            // You can also define callables that should be run to replace the value!

            'name' => static function (string $value): string {
                return substr_replace($value, 'xxx', 1);
            },
            'actual_name' => 'REDACTED',
            'twitter' => '@saloonphp',
        ];
    }
}
