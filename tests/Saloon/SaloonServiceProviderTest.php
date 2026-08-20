<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon;

use GuzzleHttp\TransportSharing;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Factory;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;

class SaloonServiceProviderTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testProviderRegistersTheManagerAndFacadeAlias(): void
    {
        $manager = $this->app->make('saloon');

        $this->assertInstanceOf(SaloonManager::class, $manager);
        $this->assertSame($manager, $this->app->make(SaloonManager::class));
        $this->assertSame($manager, Saloon::getFacadeRoot());
    }

    public function testProviderMergesConfigurationAndRegistersTheNamedConnection(): void
    {
        $this->assertSame('saloon', config('saloon.connection.name'));
        $this->assertTrue($this->app->make(Factory::class)->hasConnection('saloon'));
        $this->assertSame([
            'connect_timeout' => 10,
            'timeout' => 30,
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ], $this->app->make(Factory::class)->getConnectionConfig('saloon'));
    }

    public function testProviderPublishesConfigurationAndGeneratorStubs(): void
    {
        $packageSource = dirname(__DIR__, 2) . '/src/saloon/src/../';

        $this->assertSame([
            $packageSource . 'config/saloon.php' => config_path('saloon.php'),
        ], ServiceProvider::pathsToPublish(SaloonServiceProvider::class, 'saloon-config'));

        $source = $packageSource . 'stubs/';
        $target = base_path('stubs/');

        $this->assertSame([
            $source . 'saloon.authenticator.stub' => $target . 'saloon.authenticator.stub',
            $source . 'saloon.connector.stub' => $target . 'saloon.connector.stub',
            $source . 'saloon.oauth-connector.stub' => $target . 'saloon.oauth-connector.stub',
            $source . 'saloon.plugin.stub' => $target . 'saloon.plugin.stub',
            $source . 'saloon.request.stub' => $target . 'saloon.request.stub',
            $source . 'saloon.response.stub' => $target . 'saloon.response.stub',
        ], ServiceProvider::pathsToPublish(SaloonServiceProvider::class, 'saloon-stubs'));
    }
}
