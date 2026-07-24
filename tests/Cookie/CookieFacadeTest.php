<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cookie;

use Hypervel\Container\Container;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Cookie;
use Hypervel\Tests\TestCase;
use stdClass;

enum CookieFacadeTestStringName: string
{
    case Session = 'session_id';
}

enum CookieFacadeTestUnitName
{
    case theme;
}

enum CookieFacadeTestIntegerName: int
{
    case Zero = 0;
}

class CookieFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cookie::clearResolvedInstances();
        Cookie::setFacadeApplication(null);

        $app = new Container;
        $app->instance('request', Request::create('/', 'GET', [], [
            'session_id' => 'session-value',
            'theme' => 'dark',
            '0' => 'zero-value',
        ]));

        Cookie::setFacadeApplication($app);
    }

    public function testHasAcceptsEnumCookieNames(): void
    {
        $this->assertTrue(Cookie::has(CookieFacadeTestStringName::Session));
        $this->assertTrue(Cookie::has(CookieFacadeTestUnitName::theme));
        $this->assertTrue(Cookie::has(CookieFacadeTestIntegerName::Zero));
    }

    public function testGetAcceptsEnumCookieNames(): void
    {
        $this->assertSame('session-value', Cookie::get(CookieFacadeTestStringName::Session));
        $this->assertSame('dark', Cookie::get(CookieFacadeTestUnitName::theme));
        $this->assertSame('zero-value', Cookie::get(CookieFacadeTestIntegerName::Zero));
    }

    public function testGetReturnsMixedDefaultsWithoutChangingAllCookieReads(): void
    {
        $default = new stdClass;

        $this->assertSame($default, Cookie::get('missing', $default));
        $this->assertSame('session-value', Cookie::get('session_id', $default));
        $this->assertSame([
            'session_id' => 'session-value',
            'theme' => 'dark',
            0 => 'zero-value',
        ], Cookie::get());
    }
}
