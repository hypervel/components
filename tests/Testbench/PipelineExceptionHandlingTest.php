<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Router\Router;
use Hypervel\Testbench\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

/**
 * Regression coverage for exceptions being converted to responses inside the
 * middleware pipeline rather than after it has fully unwound.
 *
 * @internal
 * @coversNothing
 */
class PipelineExceptionHandlingTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function defineRoutes(Router $router): void
    {
        $router->group('', function () use ($router) {
            $router->get('/pipeline-model-missing', fn () => throw new ModelNotFoundException());
            $router->get('/pipeline-server-error', fn () => throw new RuntimeException('Bad route!'));
            $router->get('/pipeline-ok', fn () => 'fine');
        }, ['middleware' => [StatusSpyMiddleware::class]]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        StatusSpyMiddleware::reset();
    }

    public function testMiddlewareObservesTheMappedStatusForDomainExceptions(): void
    {
        // ModelNotFoundException renders as 404. Before the pipeline handled
        // exceptions, middleware saw the throwable instead of the response and
        // status-based instrumentation recorded a 500 for a 404 request.
        $this->get('/pipeline-model-missing')->assertStatus(404);

        $this->assertSame(404, StatusSpyMiddleware::$observedStatus);
        $this->assertNull(StatusSpyMiddleware::$observedThrowable);
    }

    public function testMiddlewareObservesTheStatusForUnhandledExceptions(): void
    {
        $this->get('/pipeline-server-error')->assertStatus(500);

        $this->assertSame(500, StatusSpyMiddleware::$observedStatus);
        $this->assertNull(StatusSpyMiddleware::$observedThrowable);
    }

    public function testSuccessfulRequestsAreUnaffected(): void
    {
        $this->get('/pipeline-ok')->assertStatus(200);

        $this->assertSame(200, StatusSpyMiddleware::$observedStatus);
        $this->assertNull(StatusSpyMiddleware::$observedThrowable);
    }
}

class StatusSpyMiddleware implements MiddlewareInterface
{
    public static ?int $observedStatus = null;

    public static ?Throwable $observedThrowable = null;

    public static function reset(): void
    {
        static::$observedStatus = null;
        static::$observedThrowable = null;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            static::$observedThrowable = $e;

            throw $e;
        }

        static::$observedStatus = $response->getStatusCode();

        return $response;
    }
}
