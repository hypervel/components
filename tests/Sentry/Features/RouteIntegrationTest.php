<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Routing\Router;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Tests\Sentry\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

        foreach (['/horizon/api/stats', '/telescope/{view?}', '/horizontal'] as $uri) {
            $router->get($uri, function (): string {
                return 'ok';
            });
        }
    }

    #[DataProvider('developmentToolRouteDataProvider')]
    #[DefineEnvironment('envSamplingAllTransactions')]
    public function testTransactionIsDroppedForDevelopmentToolRoutesByDefault(string $uri, int $expectedTransactionCount): void
    {
        $this->get($uri)->assertOk();

        $this->assertSentryTransactionCount($expectedTransactionCount);
    }

    /**
     * Provide development tool routes and how many transactions each records.
     */
    public static function developmentToolRouteDataProvider(): iterable
    {
        yield 'horizon' => ['/horizon/api/stats', 0];
        yield 'telescope' => ['/telescope', 0];
        // REMOVED: debugbar case; Hypervel has no Debugbar package.
        yield 'route only sharing the start of its name' => ['/horizontal', 1];
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
