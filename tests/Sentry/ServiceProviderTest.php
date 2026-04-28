<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Sentry\Facade;
use Hypervel\Support\Facades\Artisan;
use Sentry\State\HubInterface;

class ServiceProviderTest extends SentryTestCase
{
    protected array $setupConfig = [
        'sentry.error_types' => E_ALL ^ E_DEPRECATED ^ E_USER_DEPRECATED,
    ];

    public function testIsBound(): void
    {
        $this->assertTrue(app()->bound('sentry'));
        $this->assertSame(app('sentry'), Facade::getFacadeRoot());
        $this->assertInstanceOf(HubInterface::class, app('sentry'));
    }

    public function testEnvironment(): void
    {
        $this->assertEquals('testing', app('sentry')->getClient()->getOptions()->getEnvironment());
    }

    public function testDsnWasSetFromConfig(): void
    {
        $options = app('sentry')->getClient()->getOptions();

        $this->assertEquals('https://sentry.dev', $options->getDsn()->getScheme() . '://' . $options->getDsn()->getHost());
        $this->assertEquals(123, $options->getDsn()->getProjectId());
        $this->assertEquals('publickey', $options->getDsn()->getPublicKey());
    }

    public function testErrorTypesWasSetFromConfig(): void
    {
        $this->assertEquals(
            E_ALL ^ E_DEPRECATED ^ E_USER_DEPRECATED,
            app('sentry')->getClient()->getOptions()->getErrorTypes()
        );
    }

    public function testArtisanCommandsAreRegistered(): void
    {
        $this->assertArrayHasKey('sentry:test', Artisan::all());
        $this->assertArrayHasKey('sentry:publish', Artisan::all());
    }
}
