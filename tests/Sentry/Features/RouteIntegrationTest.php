<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Routing\Router;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Tests\Sentry\SentryTestCase;

/**
 * @internal
 * @coversNothing
 */
class RouteIntegrationTest extends SentryTestCase
{
    protected function defineRoutes(Router $router): void
    {
        $router->group(['prefix' => 'sentry'], function (Router $router) {
            $router->get('/ok', function () {
                return 'ok';
            });

            $router->get('/abort/{code}', function (string $code) {
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
