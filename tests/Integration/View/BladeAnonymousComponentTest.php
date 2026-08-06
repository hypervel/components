<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\View;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\Blade;
use Hypervel\Support\Facades\View;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;

class BladeAnonymousComponentTest extends TestCase
{
    public function testAnonymousComponentsWithCustomPathsCanBeRendered(): void
    {
        Blade::anonymousComponentPath(__DIR__ . '/anonymous-components-1', 'layouts');
        Blade::anonymousComponentPath(__DIR__ . '/anonymous-components-2');

        $view = View::make('page')->render();

        $this->assertStringContainsString('Panel content.', $view);
        $this->assertStringContainsString('class="app-layout"', $view);
        $this->assertStringContainsString('class="danger-button"', $view);
    }

    public function testAnonymousComponentsWithCustomPathsCantBeRenderedAsNormalViews(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Blade::anonymousComponentPath(__DIR__ . '/anonymous-components-1', 'layouts');
        Blade::anonymousComponentPath(__DIR__ . '/anonymous-components-2');

        View::make('layouts::app')->render();
    }

    public function testAnonymousComponentsWithCustomPathsCantBeRenderedAsNormalViewsEvenWithNoPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Blade::anonymousComponentPath(__DIR__ . '/anonymous-components-1', 'layouts');
        Blade::anonymousComponentPath(__DIR__ . '/anonymous-components-2');

        View::make('panel')->render();
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('view.paths', [__DIR__ . '/anonymous-components-templates']);
    }
}
