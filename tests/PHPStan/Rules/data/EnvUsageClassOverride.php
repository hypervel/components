<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Data;

class EnvUsageClassOverride
{
    use EnvUsageTrait;

    public function test(): void
    {
        $this->callEnv();
    }

    protected function callEnv(): void
    {
        //
    }
}
