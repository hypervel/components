<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Data;

class EnvUsageClass
{
    use EnvUsageTrait;

    public function test(): void
    {
        $this->callEnv();
    }
}
