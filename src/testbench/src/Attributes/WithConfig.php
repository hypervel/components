<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Contracts\Attributes\Invokable;

/**
 * Sets a config value directly.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class WithConfig implements Invokable
{
    public function __construct(
        public readonly string $key,
        public readonly mixed $value
    ) {
    }

    /**
     * Handle the attribute.
     */
    public function __invoke(ApplicationContract $app): mixed
    {
        $app->get('config')->set($this->key, $this->value);

        return null;
    }
}
