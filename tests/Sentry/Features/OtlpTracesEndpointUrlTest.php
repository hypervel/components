<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\JsonResponse;
use Hypervel\Routing\Router;
use Hypervel\Tests\Sentry\TestCase;
use Sentry\Integration\OTLPIntegration;

use function Sentry\getOtlpTracesEndpointUrl;

class OtlpTracesEndpointUrlTest extends TestCase
{
    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('sentry.integrations', [
            OTLPIntegration::class,
        ]);
    }

    /**
     * Define the test routes.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->get('/sentry-test/otlp-traces-endpoint-url', function (): JsonResponse {
            return response()->json([
                'url' => getOtlpTracesEndpointUrl(),
            ]);
        });
    }

    public function testReturnsDsnDerivedOtlpTracesEndpointUrlDuringHttpRequest(): void
    {
        $dsn = $this->getSentryClientFromContainer()->getOptions()->getDsn();
        $this->assertNotNull($dsn);
        $this->assertNotNull($this->getSentryClientFromContainer()->getIntegration(OTLPIntegration::class));

        $response = $this->get('/sentry-test/otlp-traces-endpoint-url');

        $response->assertOk();
        $response->assertJson([
            'url' => $dsn->getOtlpTracesEndpointUrl(),
        ]);
    }
}
