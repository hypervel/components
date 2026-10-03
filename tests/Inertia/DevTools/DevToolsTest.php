<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Http\Request;
use Hypervel\Inertia\DevTools\DevTools;
use Hypervel\Tests\Inertia\TestCase;

class DevToolsTest extends TestCase
{
    public function testExplicitConfigTakesPrecedenceOverTheEnvironment(): void
    {
        config()->set('inertia.devtools.enabled', true);
        $this->app->instance('env', 'production');
        $this->assertTrue(DevTools::enabled());

        config()->set('inertia.devtools.enabled', false);
        $this->app->instance('env', 'local');
        $this->assertFalse(DevTools::enabled());
    }

    public function testDefaultsToTheLocalEnvironmentWhenUnconfigured(): void
    {
        config()->set('inertia.devtools.enabled', null);

        $this->app->instance('env', 'local');
        $this->assertTrue(DevTools::enabled());

        $this->app->instance('env', 'production');
        $this->assertFalse(DevTools::enabled());
    }

    public function testNoRecorderIsResolvedForAnExcludedPath(): void
    {
        config()->set('inertia.devtools.enabled', true);
        config()->set('inertia.devtools.except', ['health']);

        // Without the request, recording is on; with it, an excluded path skips the whole
        // lifecycle rather than collecting sources and props only to drop the entry later.
        $this->assertNotNull(DevTools::recorder());
        $this->assertNotNull(DevTools::recorder(Request::create('/dashboard')));
        $this->assertNull(DevTools::recorder(Request::create('/health')));
    }

    public function testNoRecorderIsResolvedWhenDevtoolsIsDisabled(): void
    {
        config()->set('inertia.devtools.enabled', false);

        $this->assertNull(DevTools::recorder());
        $this->assertNull(DevTools::recorder(Request::create('/dashboard')));
    }

    public function testStringExceptPatternsAreHonored(): void
    {
        config()->set('inertia.devtools.enabled', true);
        config()->set('inertia.devtools.except', ['admin/*', 42, null]);

        $this->assertFalse(DevTools::enabledForRequest(Request::create('/admin/users')));
        $this->assertTrue(DevTools::enabledForRequest(Request::create('/dashboard')));
    }

    public function testShippedListsMatchTheDefaultsUsedWhenOmitted(): void
    {
        $this->assertSame(DevTools::DEFAULT_EXCEPT, config('inertia.devtools.except'));
        $this->assertSame(DevTools::DEFAULT_REDACT_KEYS, config('inertia.devtools.redact.keys'));
        $this->assertSame(DevTools::DEFAULT_REDACT_HEADERS, config('inertia.devtools.redact.headers'));
    }

    public function testOmittedExceptListKeepsTheDefaultExclusionsAndAnEmptyListRecordsEveryPath(): void
    {
        config()->set('inertia.devtools', ['enabled' => true]);

        $this->assertFalse(DevTools::enabledForRequest(Request::create('/_inertia/devtools/entries')));
        $this->assertTrue(DevTools::enabledForRequest(Request::create('/dashboard')));

        config()->set('inertia.devtools.except', []);

        $this->assertTrue(DevTools::enabledForRequest(Request::create('/_inertia/devtools/entries')));
    }
}
