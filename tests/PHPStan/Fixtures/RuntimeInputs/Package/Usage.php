<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\RuntimeInputs\Package;

class Usage
{
    public function greeting(): string
    {
        return app(Greeter::class)->bonjour();
    }
}
