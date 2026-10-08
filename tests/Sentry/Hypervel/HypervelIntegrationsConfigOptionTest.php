<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Hypervel;

use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Sentry\Integration;
use Hypervel\Sentry\Integration\ContextIntegration;
use Hypervel\Sentry\Integration\ExceptionContextIntegration;
use Hypervel\Tests\Sentry\TestCase;
use RuntimeException;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\Integration\FrameContextifierIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\Integration\OTLPIntegration;
use Sentry\Integration\RequestIntegration;
use Sentry\Integration\TransactionIntegration;

class HypervelIntegrationsConfigOptionTest extends TestCase
{
    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->singleton('custom-sentry-integration', static function () {
            return new IntegrationsOptionTestIntegrationStub;
        });
    }

    public function testCustomIntegrationIsResolvedFromContainerByAlias(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                'custom-sentry-integration',
            ],
        ]);

        $this->assertNotNull($this->getSentryClientFromContainer()->getIntegration(IntegrationsOptionTestIntegrationStub::class));
    }

    public function testCustomIntegrationIsResolvedFromContainerByClass(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                IntegrationsOptionTestIntegrationStub::class,
            ],
        ]);

        $this->assertNotNull($this->getSentryClientFromContainer()->getIntegration(IntegrationsOptionTestIntegrationStub::class));
    }

    public function testOtlpIntegrationClassIsRegisteredFromIntegrationsConfig(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                OTLPIntegration::class,
            ],
        ]);

        $this->assertNotNull($this->getSentryClientFromContainer()->getIntegration(OTLPIntegration::class));
    }

    public function testCustomIntegrationByInstance(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                new IntegrationsOptionTestIntegrationStub,
            ],
        ]);

        $this->assertNotNull($this->getSentryClientFromContainer()->getIntegration(IntegrationsOptionTestIntegrationStub::class));
    }

    public function testCustomIntegrationThrowsExceptionIfNotResolvable(): void
    {
        $this->expectException(BindingResolutionException::class);

        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                'this-will-not-resolve',
            ],
        ]);
    }

    public function testIncorrectIntegrationEntryThrowsException(): void
    {
        $this->expectException(RuntimeException::class);

        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                static function () {
                },
            ],
        ]);
    }

    public function testDisabledIntegrationsAreNotPresent(): void
    {
        $client = $this->getSentryClientFromContainer();

        $this->assertNull($client->getIntegration(ErrorListenerIntegration::class));
        $this->assertNull($client->getIntegration(ExceptionListenerIntegration::class));
        $this->assertNull($client->getIntegration(FatalErrorListenerIntegration::class));
    }

    public function testDisabledIntegrationsAreNotPresentWithCustomIntegrations(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                new IntegrationsOptionTestIntegrationStub,
            ],
        ]);

        $client = $this->getSentryClientFromContainer();

        $this->assertNotNull($client->getIntegration(IntegrationsOptionTestIntegrationStub::class));

        $this->assertNull($client->getIntegration(ErrorListenerIntegration::class));
        $this->assertNull($client->getIntegration(ExceptionListenerIntegration::class));
        $this->assertNull($client->getIntegration(FatalErrorListenerIntegration::class));
    }

    public function testDisablingDefaultIntegrationsKeepsOnlyTheHypervelIntegrations(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.default_integrations' => false,
        ]);

        $client = $this->getSentryClientFromContainer();

        $this->assertNull($client->getIntegration(RequestIntegration::class));
        $this->assertNull($client->getIntegration(TransactionIntegration::class));
        $this->assertNull($client->getIntegration(FrameContextifierIntegration::class));
        $this->assertNull($client->getIntegration(EnvironmentIntegration::class));

        $this->assertNotNull($client->getIntegration(Integration::class));
        $this->assertNotNull($client->getIntegration(ContextIntegration::class));
        $this->assertNotNull($client->getIntegration(ExceptionContextIntegration::class));
    }

    public function testCallableIntegrationsReceiveTheResolvedIntegrations(): void
    {
        $receivedIntegrations = [];

        $this->resetApplicationWithConfig([
            'sentry.integrations' => static function (array $integrations) use (&$receivedIntegrations): array {
                $receivedIntegrations = $integrations;

                return [new IntegrationsOptionTestIntegrationStub];
            },
        ]);

        $client = $this->getSentryClientFromContainer();

        $this->assertContains(Integration::class, array_map(get_class(...), $receivedIntegrations));
        $this->assertContains(RequestIntegration::class, array_map(get_class(...), $receivedIntegrations));
        $this->assertNotNull($client->getIntegration(IntegrationsOptionTestIntegrationStub::class));
        $this->assertNull($client->getIntegration(Integration::class));
    }
}

class IntegrationsOptionTestIntegrationStub implements IntegrationInterface
{
    /**
     * Set up the integration.
     */
    public function setupOnce(): void
    {
    }
}
