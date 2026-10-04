<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Support;

class FakeInjectable
{
    /**
     * Create the injectable fixture.
     */
    public function __construct(
        public readonly mixed $value,
    ) {
    }

    /**
     * Bind an injectable holding the given value.
     */
    public static function setup(mixed $value): void
    {
        app()->bind(self::class, fn (): self => new self($value));
    }
}
