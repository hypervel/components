<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Middleware\RedirectIfAuthenticated;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class RedirectIfAuthenticatedMiddlewareTest extends TestCase
{
    public function testItCanGenerateDefinitionViaStaticMethod()
    {
        $signature = RedirectIfAuthenticated::using('foo');
        $this->assertSame('Hypervel\Auth\Middleware\RedirectIfAuthenticated:foo', $signature);

        $signature = RedirectIfAuthenticated::using('foo', 'bar');
        $this->assertSame('Hypervel\Auth\Middleware\RedirectIfAuthenticated:foo,bar', $signature);

        $signature = RedirectIfAuthenticated::using('foo', 'bar', 'baz');
        $this->assertSame('Hypervel\Auth\Middleware\RedirectIfAuthenticated:foo,bar,baz', $signature);
    }
}
