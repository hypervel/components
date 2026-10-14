<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Sentry\ClientBuilder;

class ClientBuilderDecoratorTest extends TestCase
{
    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->extend(ClientBuilder::class, function (ClientBuilder $clientBuilder): ClientBuilder {
            $clientBuilder->getOptions()->setEnvironment('from_service_container');

            return $clientBuilder;
        });
    }

    public function testClientHasEnvironmentSetFromDecorator(): void
    {
        $this->assertEquals(
            'from_service_container',
            $this->getSentryClientFromContainer()->getOptions()->getEnvironment()
        );
    }
}
