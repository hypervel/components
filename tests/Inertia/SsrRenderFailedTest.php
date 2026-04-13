<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Inertia\Ssr\SsrErrorType;
use Hypervel\Inertia\Ssr\SsrRenderFailed;

/**
 * @internal
 * @coversNothing
 */
class SsrRenderFailedTest extends TestCase
{
    public function testItExtractsComponentFromPage(): void
    {
        $event = new SsrRenderFailed(
            page: ['component' => 'Dashboard', 'url' => '/dashboard'],
            error: 'Test error',
        );

        $this->assertEquals('Dashboard', $event->component());
    }

    public function testItExtractsUrlFromPage(): void
    {
        $event = new SsrRenderFailed(
            page: ['component' => 'Dashboard', 'url' => '/dashboard'],
            error: 'Test error',
        );

        $this->assertEquals('/dashboard', $event->url());
    }

    public function testItStoresSourceLocation(): void
    {
        $event = new SsrRenderFailed(
            page: ['component' => 'Dashboard'],
            error: 'window is not defined',
            sourceLocation: '/path/to/Dashboard.vue:10:5',
        );

        $this->assertEquals('/path/to/Dashboard.vue:10:5', $event->sourceLocation);
    }

    public function testItIncludesSourceLocationInToArray(): void
    {
        $event = new SsrRenderFailed(
            page: ['component' => 'Dashboard', 'url' => '/dashboard'],
            error: 'window is not defined',
            type: SsrErrorType::BrowserApi,
            hint: 'Wrap in lifecycle hook',
            browserApi: 'window',
            sourceLocation: '/path/to/Dashboard.vue:10:5',
        );

        $array = $event->toArray();

        $this->assertEquals('/path/to/Dashboard.vue:10:5', $array['source_location']);
        $this->assertEquals('Dashboard', $array['component']);
        $this->assertEquals('/dashboard', $array['url']);
        $this->assertEquals('window is not defined', $array['error']);
        $this->assertEquals('browser-api', $array['type']);
        $this->assertEquals('Wrap in lifecycle hook', $array['hint']);
        $this->assertEquals('window', $array['browser_api']);
    }

    public function testToArrayExcludesNullValues(): void
    {
        $event = new SsrRenderFailed(
            page: ['component' => 'Dashboard', 'url' => '/dashboard'],
            error: 'Something went wrong',
        );

        $array = $event->toArray();

        $this->assertArrayNotHasKey('hint', $array);
        $this->assertArrayNotHasKey('browser_api', $array);
        $this->assertArrayNotHasKey('source_location', $array);
    }
}
