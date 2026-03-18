<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Middleware\EnsureEmailIsVerified;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class EnsureEmailIsVerifiedTest extends TestCase
{
    public function testItCanGenerateDefinitionViaStaticMethod()
    {
        $signature = EnsureEmailIsVerified::redirectTo('route.name');
        $this->assertSame('Hypervel\Auth\Middleware\EnsureEmailIsVerified:route.name', $signature);
    }
}
