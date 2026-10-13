<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Sentry\Aspects\GuzzleHttpClientAspect;
use Hypervel\Sentry\Facade;
use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Depends;
use Sentry\State\HubInterface;

class ServiceProviderWithCustomAliasTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        $config = $app->make('config');

        // Sentry selects its AOP integrations during provider registration, before Testbench runs defineEnvironment().
        $config->set('custom-sentry.dsn', 'http://publickey@sentry.dev/123');
        $config->set('custom-sentry.error_types', E_ALL ^ E_DEPRECATED ^ E_USER_DEPRECATED);

        return [
            CustomSentryServiceProvider::class,
        ];
    }

    /**
     * Get the package aliases.
     */
    protected function getPackageAliases(ApplicationContract $app): array
    {
        return [
            'CustomSentry' => CustomSentryFacade::class,
        ];
    }

    public function testIsBound(): void
    {
        $this->assertTrue(app()->bound('custom-sentry'));
        $this->assertInstanceOf(HubInterface::class, app('custom-sentry'));
        $this->assertSame(app('custom-sentry'), CustomSentryFacade::getFacadeRoot());
        $this->assertNotEmpty(AspectCollector::getRule(GuzzleHttpClientAspect::class));
    }

    #[Depends('testIsBound')]
    public function testEnvironment(): void
    {
        $this->assertEquals('testing', app('custom-sentry')->getClient()->getOptions()->getEnvironment());
    }

    #[Depends('testIsBound')]
    public function testDsnWasSetFromConfig(): void
    {
        $options = app('custom-sentry')->getClient()->getOptions();

        $this->assertEquals('http://sentry.dev', $options->getDsn()->getScheme() . '://' . $options->getDsn()->getHost());
        $this->assertEquals(123, $options->getDsn()->getProjectId());
        $this->assertEquals('publickey', $options->getDsn()->getPublicKey());
    }

    #[Depends('testIsBound')]
    public function testErrorTypesWasSetFromConfig(): void
    {
        $this->assertEquals(
            E_ALL ^ E_DEPRECATED ^ E_USER_DEPRECATED,
            app('custom-sentry')->getClient()->getOptions()->getErrorTypes()
        );
    }
}

class CustomSentryServiceProvider extends SentryServiceProvider
{
    public static string $abstract = 'custom-sentry';
}

class CustomSentryFacade extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'custom-sentry';
    }
}
