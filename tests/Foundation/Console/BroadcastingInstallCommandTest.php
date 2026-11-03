<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Console\BroadcastingInstallCommand;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class BroadcastingInstallCommandTest extends TestCase
{
    protected string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = ParallelTesting::tempDir('BroadcastingInstallCommandTest');

        (new Filesystem)->deleteDirectory($this->basePath);
        (new Filesystem)->ensureDirectoryExists($this->basePath . '/resources/js');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    #[DataProvider('frameworkFiles')]
    public function testEchoConfigurationIsInjectedIntoTheApplicationFile(string $package, string $file): void
    {
        file_put_contents($this->basePath . '/package.json', json_encode(['devDependencies' => [$package => '*']]));
        file_put_contents($this->basePath . '/resources/js/' . $file, "import './bootstrap';\n");

        $app = new Application($this->basePath);
        $app->instance('files', new Filesystem);

        $command = new BroadcastingInstallCommandTestStub;
        $command->setHypervel($app);
        $command->injectEchoConfiguration('reverb');

        $this->assertStringContainsString(
            "import { configureEcho } from '@laravel/echo-{$package}';",
            file_get_contents($this->basePath . '/resources/js/' . $file)
        );
    }

    public static function frameworkFiles(): array
    {
        // REMOVED: Vue's app.ts and app.js cases. Hypervel's command configures Echo
        // inside the React starter kit only; Vue apps use the standard echo.js setup.
        return [
            ['react', 'app.tsx'],
            ['react', 'app.jsx'],
        ];
    }
}

class BroadcastingInstallCommandTestStub extends BroadcastingInstallCommand
{
    /**
     * Inject the Echo configuration for the given driver.
     */
    public function injectEchoConfiguration(string $driver): void
    {
        $this->driver = $driver;
        $this->components = new Factory(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        $this->injectFrameworkSpecificConfiguration();
    }
}
