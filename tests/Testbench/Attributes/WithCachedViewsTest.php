<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Attributes\WithCachedViews;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\workbench_path;

class WithCachedViewsTest extends TestCase
{
    use WithWorkbench;

    /**
     * Configure the workbench view paths.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        tap($app->make('config'), function (ConfigRepository $config) {
            $config->set('view.paths', [workbench_path('resources', 'views')]);
        });
    }

    #[Test]
    #[WithCachedViews]
    public function itCanCachedViews(): void
    {
        $compiledPath = realpath($this->getCompiledPathForView('testbench'));

        $this->assertNotFalse($compiledPath);
        $this->assertFileExists($compiledPath);
    }

    #[Test]
    #[Depends('itCanCachedViews')]
    public function itDoesNotPersistCacheAfterTest(): void
    {
        $compiledPath = $this->getCompiledPathForView('testbench');

        $this->assertFalse(realpath($compiledPath));
        $this->assertFileDoesNotExist($compiledPath);
    }

    /**
     * Get the compiled path for a view name.
     */
    protected function getCompiledPathForView(string $name): string
    {
        $path = view($name)->getPath();

        return $this->app->make('blade.compiler')->getCompiledPath(realpath($path) ?: $path);
    }
}
