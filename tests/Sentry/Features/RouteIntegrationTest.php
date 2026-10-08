<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Routing\Router;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Tests\Sentry\TestCase;

class RouteIntegrationTest extends TestCase
{
    /**
     * Define the test routes.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->group(['prefix' => 'sentry'], function (Router $router): void {
            $router->get('/ok', function (): string {
                return 'ok';
            });

            $router->get('/abort/{code}', function (string $code): never {
                abort((int) $code);
            });
        });
    }

    #[DefineEnvironment('envSamplingAllTransactions')]
    public function testTransactionIsRecordedForRoute(): void
    {
        $this->get('/sentry/ok')->assertOk();

        $this->assertSentryTransactionCount(1);
    }

    #[DefineEnvironment('envSamplingAllTransactions')]
    public function testTransactionIsRecordedForNotFound(): void
    {
        $this->get('/sentry/abort/404')->assertNotFound();

        $this->assertSentryTransactionCount(1);
    }

    #[DefineEnvironment('envSamplingAllTransactions')]
    public function testTransactionIsDroppedForUndefinedRoute(): void
    {
        $this->get('/sentry/non-existent-route')->assertNotFound();

        $this->assertSentryTransactionCount(0);
    }
}
