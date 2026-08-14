<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Foundation\Console\CliDumper;
use Hypervel\Foundation\Console\EventListCommand;
use Hypervel\Foundation\Console\VendorPublishCommand;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Output\BufferedOutput;

class StaticStateTest extends TestCase
{
    public function testApplicationFlushStateClearsMacros(): void
    {
        Application::macro('testMacro', function () {
            return 'test';
        });

        $this->assertTrue(Application::hasMacro('testMacro'));

        Application::flushState();

        $this->assertFalse(Application::hasMacro('testMacro'));
    }

    public function testApplicationFlushStatePreservesContainerStaticCleanup(): void
    {
        $container = new class extends Application {
            public function fillBuildRecipeCache(string $concrete): void
            {
                $this->build($concrete);
            }

            public function buildRecipeCache(): array
            {
                return (new ReflectionClass(Container::class))
                    ->getProperty('buildRecipes')
                    ->getValue();
            }
        };

        $container->fillBuildRecipeCache(FoundationStaticStateTestBuildRecipeStub::class);

        $this->assertArrayHasKey(FoundationStaticStateTestBuildRecipeStub::class, $container->buildRecipeCache());

        Application::flushState();

        $this->assertSame([], $container->buildRecipeCache());
    }

    public function testLoadConfigurationFlushStateClearsAlwaysUseConfig(): void
    {
        LoadConfiguration::alwaysUse(fn () => ['app' => ['name' => 'Static Test']]);

        $app = new Application;
        (new LoadConfiguration)->bootstrap($app);

        $this->assertSame('Static Test', $app['config']['app.name']);

        LoadConfiguration::flushState();

        $app = new Application;
        (new LoadConfiguration)->bootstrap($app);

        $this->assertSame('Hypervel', $app['config']['app.name']);
    }

    public function testCliDumperFlushStateClearsDumpSourceResolver(): void
    {
        CliDumper::resolveDumpSourceUsing(fn () => ['/tmp/example.php', 'example.php', 1]);

        $this->assertSame(
            ['/tmp/example.php', 'example.php', 1],
            $this->newCliDumper()->resolveDumpSource()
        );

        CliDumper::flushState();

        $this->assertNull($this->newCliDumper()->resolveDumpSource());
    }

    public function testVendorPublishCommandFlushStateRestoresMigrationDateUpdates(): void
    {
        $property = (new ReflectionClass(VendorPublishCommand::class))->getProperty('updateMigrationDates');

        VendorPublishCommand::dontUpdateMigrationDates();

        $this->assertFalse($property->getValue());

        VendorPublishCommand::flushState();

        $this->assertTrue($property->getValue());
    }

    public function testEventListCommandFlushStateClearsEventsResolver(): void
    {
        $property = (new ReflectionClass(EventListCommand::class))->getProperty('eventsResolver');

        EventListCommand::resolveEventsUsing(fn () => 'events');

        $this->assertNotNull($property->getValue());

        EventListCommand::flushState();

        $this->assertNull($property->getValue());
    }

    protected function newCliDumper(): CliDumper
    {
        return new CliDumper(new BufferedOutput, __DIR__, '');
    }
}

class FoundationStaticStateTestBuildRecipeStub
{
}
