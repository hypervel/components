<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Mocking;

use Hypervel\Saloon\Http\Faking\Fixture;

class SuperheroFixture extends Fixture
{
    /**
     * Define the name of the fixture.
     */
    protected function defineName(): string
    {
        return 'superhero';
    }

    /**
     * Swap any sensitive JSON parameters.
     */
    protected function defineSensitiveJsonParameters(): array
    {
        return [
            'publisher' => 'REDACTED',
        ];
    }
}
