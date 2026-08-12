<?php

declare(strict_types=1);

namespace Hypervel\Tests\Image;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Image\Drivers\GdDriver;
use Hypervel\Image\ImageManager;
use Hypervel\Image\ImageServiceProvider;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;

class ImageServiceProviderTest extends TestCase
{
    protected function getPackageProviders(Application $app): array
    {
        return [ImageServiceProvider::class];
    }

    public function testMergesDefaultConfiguration(): void
    {
        $this->assertSame('gd', $this->app->make('config')->string('images.default'));
    }

    public function testPublishesConfiguration(): void
    {
        $this->assertSame([
            dirname(__DIR__, 2) . '/src/image/config/images.php' => config_path('images.php'),
        ], ServiceProvider::pathsToPublish(ImageServiceProvider::class, 'image-config'));
    }

    public function testCanonicalAliasSharesTheWorkerLifetimeManagerAndDriver(): void
    {
        $manager = $this->app->make('image');
        $driver = $manager->driver();

        $this->assertInstanceOf(ImageManager::class, $manager);
        $this->assertInstanceOf(GdDriver::class, $driver);
        $this->assertSame($manager, $this->app->make(ImageManager::class));
        $this->assertSame($manager, $this->app->make('image'));
        $this->assertSame($driver, $this->app->make('image')->driver());
    }
}
