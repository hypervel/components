<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation;

use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\PHPUnit\TestCase;
use Hypervel\Tests\Testbench\Fixtures\Providers\ChildServiceProvider;
use Hypervel\Tests\Testbench\Fixtures\Providers\ConfigTestServiceProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class ConfigTest extends TestCase
{
    #[Test]
    public function itCanLoadConfigurationFile(): void
    {
        $config = Config::loadFromYaml(__DIR__ . '/Fixtures/');

        $this->assertNull($config['hypervel']);
        $this->assertSame(['APP_DEBUG=(false)'], $config['env']);
        $this->assertSame([], $config['bootstrappers']);
        $this->assertSame([ConfigTestServiceProvider::class], $config['providers']);
        $this->assertSame([], $config['dont-discover']);
        $this->assertSame([], $config['migrations']);
        $this->assertFalse($config['seeders']);

        $this->assertSame([
            'env' => [
                'APP_DEBUG=(false)',
            ],
            'bootstrappers' => [],
            'providers' => [
                ConfigTestServiceProvider::class,
            ],
            'dont-discover' => [],
        ], $config->getExtraAttributes());

        $this->assertSame([
            'directories' => [],
            'files' => [],
        ], $config->getPurgeAttributes());

        $this->assertSame([
            'start' => '/workbench',
            'user' => 'crynobone@gmail.com',
            'guard' => null,
            'install' => true,
            'auth' => false,
            'welcome' => null,
            'health' => null,
            'sync' => [],
            'build' => [],
            'assets' => [],
            'discovers' => [
                'config' => false,
                'factories' => false,
                'web' => false,
                'api' => false,
                'commands' => false,
                'components' => false,
                'views' => false,
            ],
        ], $config->getWorkbenchAttributes());

        $this->assertSame([
            'config' => false,
            'factories' => false,
            'web' => false,
            'api' => false,
            'commands' => false,
            'components' => false,
            'views' => false,
        ], $config->getWorkbenchDiscoversAttributes());
    }

    #[Test]
    public function itCanLoadDefaultConfiguration(): void
    {
        $config = new Config();

        $this->assertNull($config['hypervel']);
        $this->assertSame([], $config['env']);
        $this->assertSame([], $config['bootstrappers']);
        $this->assertSame([], $config['providers']);
        $this->assertSame([], $config['dont-discover']);
        $this->assertSame([], $config['migrations']);
        $this->assertFalse($config['seeders']);

        $this->assertSame([
            'env' => [],
            'bootstrappers' => [],
            'providers' => [],
            'dont-discover' => [],
        ], $config->getExtraAttributes());

        $this->assertSame([
            'directories' => [],
            'files' => [],
        ], $config->getPurgeAttributes());

        $this->assertSame([
            'start' => '/',
            'user' => null,
            'guard' => null,
            'install' => true,
            'auth' => false,
            'welcome' => null,
            'health' => null,
            'sync' => [],
            'build' => [],
            'assets' => [],
            'discovers' => [
                'config' => false,
                'factories' => false,
                'web' => false,
                'api' => false,
                'commands' => false,
                'components' => false,
                'views' => false,
            ],
        ], $config->getWorkbenchAttributes());

        $this->assertSame([
            'config' => false,
            'factories' => false,
            'web' => false,
            'api' => false,
            'commands' => false,
            'components' => false,
            'views' => false,
        ], $config->getWorkbenchDiscoversAttributes());
    }

    #[Test]
    public function itCanAddAdditionalProvidersToConfigurationFile(): void
    {
        $config = Config::loadFromYaml(__DIR__ . '/Fixtures/');

        $this->assertSame([
            ConfigTestServiceProvider::class,
        ], $config['providers']);

        $config->addProviders([
            ChildServiceProvider::class,
        ]);

        $this->assertSame([
            ConfigTestServiceProvider::class,
            ChildServiceProvider::class,
        ], $config['providers']);
    }

    #[Test]
    public function itCantAddDuplicatedProvidersToConfigurationFile(): void
    {
        $config = Config::loadFromYaml(__DIR__ . '/Fixtures/');

        $this->assertSame([
            ConfigTestServiceProvider::class,
        ], $config['providers']);

        $config->addProviders([
            ConfigTestServiceProvider::class,
        ]);

        $this->assertSame([
            ConfigTestServiceProvider::class,
        ], $config['providers']);
    }
}
