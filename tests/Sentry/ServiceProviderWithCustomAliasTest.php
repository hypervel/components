<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Sentry\Facade;
use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Testbench\TestCase;
use Sentry\State\HubInterface;

class ServiceProviderWithCustomAliasTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('custom-sentry.dsn', 'http://publickey@sentry.dev/123');
        $app['config']->set('custom-sentry.error_types', E_ALL ^ E_DEPRECATED ^ E_USER_DEPRECATED);
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            CustomSentryServiceProvider::class,
        ];
    }

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
    }

    public function testEnvironment(): void
    {
        $this->assertEquals('testing', app('custom-sentry')->getClient()->getOptions()->getEnvironment());
    }

    public function testDsnWasSetFromConfig(): void
    {
        $options = app('custom-sentry')->getClient()->getOptions();

        $this->assertEquals('http://sentry.dev', $options->getDsn()->getScheme() . '://' . $options->getDsn()->getHost());
        $this->assertEquals(123, $options->getDsn()->getProjectId());
        $this->assertEquals('publickey', $options->getDsn()->getPublicKey());
    }

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
    protected static function getFacadeAccessor(): string
    {
        return 'custom-sentry';
    }
}
