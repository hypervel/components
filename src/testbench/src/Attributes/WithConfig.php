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
    // Orchestra's deferred `defer` parameter is intentionally not ported. Hypervel's
    // long-lived Swoole workers share process-global configuration, so values must be
    // applied before providers register. Set post-boot values explicitly in the test body.

    /**
     * Create a new attribute instance.
     */
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
    ) {
    }

    /**
     * Handle the attribute.
     */
    public function __invoke(ApplicationContract $app): mixed
    {
        $app->make('config')->set($this->key, $this->value);

        return null;
    }
}
