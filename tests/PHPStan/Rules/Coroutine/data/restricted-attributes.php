<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedAttributes;

use Hypervel\Support\Attributes\BootOnly;
use Hypervel\Support\Attributes\BootOrTestsOnly;
use Hypervel\Support\Attributes\TestsOnly;

class Registry
{
    #[BootOnly(whenNotDefault: 'map', whenProvided: 'map')]
    public function both(?array $map = null): void
    {
    }

    #[BootOnly(whenNotDefault: 'missing')]
    public function missingDefault(?array $map = null): void
    {
    }

    #[TestsOnly(whenProvided: 'missing')]
    public function missingProvided(?array $map = null): void
    {
    }

    #[BootOnly(whenNotDefault: 'map')]
    public function required(array $map): void
    {
    }

    #[BootOrTestsOnly(whenShared: true)]
    public static function staticShared(): void
    {
    }

    #[BootOnly(whenNotDefault: 'map', whenShared: true)]
    public function valid(?array $map = null): void
    {
    }

    #[BootOnly]
    public static function unconditional(): void
    {
    }
}
