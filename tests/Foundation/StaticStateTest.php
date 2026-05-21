<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Foundation\Console\EventListCommand;
use Hypervel\Foundation\Console\VendorPublishCommand;
use Hypervel\Foundation\Exceptions\Renderer\Frame;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

class StaticStateTest extends TestCase
{
    public function testApplicationFlushStateClearsMacros()
    {
        Application::macro('testMacro', function () {
            return 'test';
        });

        $this->assertTrue(Application::hasMacro('testMacro'));

        Application::flushState();

        $this->assertFalse(Application::hasMacro('testMacro'));
    }

    public function testApplicationFlushStatePreservesContainerStaticCleanup()
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

    public function testLoadConfigurationFlushStateClearsAlwaysUseConfig()
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

    public function testFrameFlushStateClearsDumpSourceResolver()
    {
        Frame::resolveDumpSourceUsing(fn () => ['/tmp/example.php', 'example.php', 1]);

        $this->assertSame(
            ['/tmp/example.php', 'example.php', 1],
            $this->newFrame()->resolveDumpSource()
        );

        Frame::flushState();

        $this->assertNull($this->newFrame()->resolveDumpSource());
    }

    public function testVendorPublishCommandFlushStateRestoresMigrationDateUpdates()
    {
        $property = (new ReflectionClass(VendorPublishCommand::class))->getProperty('updateMigrationDates');

        VendorPublishCommand::dontUpdateMigrationDates();

        $this->assertFalse($property->getValue());

        VendorPublishCommand::flushState();

        $this->assertTrue($property->getValue());
    }

    public function testEventListCommandFlushStateClearsEventsResolver()
    {
        $property = (new ReflectionClass(EventListCommand::class))->getProperty('eventsResolver');

        EventListCommand::resolveEventsUsing(fn () => 'events');

        $this->assertNotNull($property->getValue());

        EventListCommand::flushState();

        $this->assertNull($property->getValue());
    }

    protected function newFrame(): Frame
    {
        return new Frame(
            $this->createStub(FlattenException::class),
            [],
            ['file' => __FILE__, 'line' => 1],
            __DIR__,
        );
    }
}

class FoundationStaticStateTestBuildRecipeStub
{
}
