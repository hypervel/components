<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon;

use GuzzleHttp\TransportSharing;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Factory;
use Hypervel\Saloon\Exceptions\PendingRequestException;
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

    public function testOmittedFixtureSettingsUsePackageDefaults(): void
    {
        $fixturePath = base_path('tests/Fixtures/Saloon');
        $this->assertSame($fixturePath, config()->string('saloon.fixtures.path'));

        config()->set('saloon.fixtures', []);

        $manager = $this->app->make(SaloonManager::class);

        $this->assertSame($fixturePath, $manager->getFixturePath());
        $this->assertFalse($manager->throwsOnMissingFixtures());
    }

    public function testProviderReloadsConnectionOptionsAndHandler(): void
    {
        $factory = $this->app->make(Factory::class);
        $oldHandler = $factory->getConnectionHandler('saloon');
        $options = [
            'connect_timeout' => 5,
            'timeout' => 15,
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ];
        config()->set('saloon.connection.options', $options);

        $provider = $this->app->getProvider(SaloonServiceProvider::class);
        $this->assertInstanceOf(SaloonServiceProvider::class, $provider);
        $provider->reloadConfiguration();

        $this->assertSame($options, $factory->getConnectionConfig('saloon'));
        $this->assertNotSame($oldHandler, $factory->getConnectionHandler('saloon'));
    }

    public function testProviderRegistersChangedConnectionNameWithoutRemovingApplicationPreset(): void
    {
        $factory = $this->app->make(Factory::class);
        $applicationOptions = ['timeout' => 60];
        $saloonOptions = ['timeout' => 15];
        $factory->registerConnection('saloon', $applicationOptions);
        config()->set('saloon.connection.name', 'saloon-refreshed');
        config()->set('saloon.connection.options', $saloonOptions);

        $provider = $this->app->getProvider(SaloonServiceProvider::class);
        $this->assertInstanceOf(SaloonServiceProvider::class, $provider);
        $provider->reloadConfiguration();

        $this->assertSame($applicationOptions, $factory->getConnectionConfig('saloon'));
        $this->assertSame($saloonOptions, $factory->getConnectionConfig('saloon-refreshed'));
    }

    public function testProviderRejectsInvalidReloadedOptionsBeforeChangingConnection(): void
    {
        $factory = $this->app->make(Factory::class);
        $originalOptions = $factory->getConnectionConfig('saloon');
        config()->set('saloon.connection.options', ['headers' => ['X-Test' => 'value']]);

        $provider = $this->app->getProvider(SaloonServiceProvider::class);
        $this->assertInstanceOf(SaloonServiceProvider::class, $provider);

        try {
            $provider->reloadConfiguration();
            $this->fail('Expected invalid reloaded Saloon options to be rejected.');
        } catch (PendingRequestException $exception) {
            $this->assertSame(
                'The [headers] option cannot be set in HTTP connection [saloon]; use the Saloon request API instead.',
                $exception->getMessage(),
            );
        }

        $this->assertSame($originalOptions, $factory->getConnectionConfig('saloon'));
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
