<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation;

use Exception;
use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\Access\Response;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Debug\ShouldntReport;
use Hypervel\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\ResponseFactory;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\Process\PhpProcess;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class ExceptionHandlerTest extends TestCase
{
    public function testItRendersAuthorizationExceptions()
    {
        Route::get('test-route', fn () => Response::deny('expected message', 321)->authorize());

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(403)
            ->assertSeeText('expected message');

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'expected message',
            ]);
    }

    public function testItDoesntReportExceptionsWithShouldntReportInterface()
    {
        Config::set('app.debug', true);
        $reported = [];
        $this->app[ExceptionHandler::class]->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;
        });

        $exception = new class extends Exception implements ShouldntReport, Responsable {
            public function toResponse(Request $request): SymfonyResponse
            {
                return response('shouldnt report', 500);
            }
        };

        Route::get('test-route', fn () => throw $exception);

        $this->getJson('test-route')
            ->assertStatus(500)
            ->assertSee('shouldnt report');

        $this->assertEquals([], $reported);
    }

    public function testItRendersAuthorizationExceptionsWithCustomStatusCode()
    {
        Route::get('test-route', fn () => Response::deny('expected message', 321)->withStatus(404)->authorize());

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(404)
            ->assertSeeText('Not Found');

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(404)
            ->assertExactJson([
                'message' => 'expected message',
            ]);
    }

    public function testItRendersAuthorizationExceptionsWithStatusCodeTextWhenNoMessageIsSet()
    {
        Route::get('test-route', fn () => Response::denyWithStatus(404)->authorize());

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(404)
            ->assertSeeText('Not Found');

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(404)
            ->assertExactJson([
                'message' => 'Not Found',
            ]);

        Route::get('test-route', fn () => Response::denyWithStatus(418)->authorize());

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(418)
            ->assertSeeText("I'm a teapot", escape: false);

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(418)
            ->assertExactJson([
                'message' => "I'm a teapot",
            ]);
    }

    public function testItRendersAuthorizationExceptionsWithStatusButWithoutResponse()
    {
        Route::get('test-route', fn () => throw (new AuthorizationException)->withStatus(418));

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(418)
            ->assertSeeText("I'm a teapot", escape: false);

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(418)
            ->assertExactJson([
                'message' => "I'm a teapot",
            ]);
    }

    public function testItHasFallbackErrorMessageForUnknownStatusCodes()
    {
        Route::get('test-route', fn () => throw (new AuthorizationException)->withStatus(399));

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(399)
            ->assertSeeText('Whoops, looks like something went wrong.');

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(399)
            ->assertExactJson([
                'message' => 'Whoops, looks like something went wrong.',
            ]);
    }

    public function testItReturns400CodeOnMalformedRequests()
    {
        // HTTP request...
        $this->post('test-route', ['_method' => '__construct'])
            ->assertStatus(400)
            ->assertSeeText('Bad Request'); // see https://github.com/symfony/symfony/blob/1d439995eb6d780531b97094ff5fa43e345fc42e/src/Symfony/Component/ErrorHandler/Resources/views/error.html.php#L12

        // JSON request...
        $this->postJson('test-route', ['_method' => '__construct'])
            ->assertStatus(400)
            ->assertExactJson([
                'message' => 'Bad request.',
            ]);
    }

    public function testItHandlesMalformedErrorViewsInProduction()
    {
        Config::set('view.paths', [__DIR__ . '/Fixtures/MalformedErrorViews']);
        Config::set('app.debug', false);
        $reported = [];
        $this->app[ExceptionHandler::class]->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;
        });

        try {
            $response = $this->get('foo');
        } catch (Throwable) {
            $response ??= null;
        }

        $this->assertCount(1, $reported);
        $this->assertSame('Undefined variable $foo (View: ' . __DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'MalformedErrorViews' . DIRECTORY_SEPARATOR . 'errors' . DIRECTORY_SEPARATOR . '404.blade.php)', $reported[0]->getMessage());
        $this->assertNotNull($response);
        $response->assertStatus(404);
    }

    public function testItHandlesMalformedErrorViewsInDevelopment()
    {
        Config::set('view.paths', [__DIR__ . '/Fixtures/MalformedErrorViews']);
        Config::set('app.debug', true);
        $reported = [];
        $this->app[ExceptionHandler::class]->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;
        });

        try {
            $response = $this->get('foo');
        } catch (Throwable) {
            $response ??= null;
        }

        $this->assertCount(1, $reported);
        $this->assertSame('Undefined variable $foo (View: ' . __DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'MalformedErrorViews' . DIRECTORY_SEPARATOR . 'errors' . DIRECTORY_SEPARATOR . '404.blade.php)', $reported[0]->getMessage());
        $this->assertNotNull($response);
        $response->assertStatus(500);
    }

    public function testItUseCustomJsonResponseFactoryInExceptionHandler()
    {
        $this->app->singleton(ResponseFactoryContract::class, function ($app) {
            return new class($app['view'], $app['redirect']) extends ResponseFactory {
                public function json(mixed $data = [], int $status = 200, array $headers = [], int $options = 0): JsonResponse
                {
                    $msg = $data['message'] ?? $data['msg'] ?? null;
                    if ($msg) {
                        unset($data['message']);
                        $wrapData = [
                            'msg' => $msg,
                            'success' => $status >= 200 && $status < 300,
                        ] + $data;
                    } else {
                        $wrapData = [
                            'msg' => 'success',
                            'success' => true,
                            'data' => $data,
                        ];
                    }

                    return new JsonResponse($wrapData, 200, $headers, $options);
                }
            };
        });

        Route::get('test-exception', function () {
            throw new Exception('Test exception');
        });

        $response = $this->getJson('test-exception');

        $response->assertStatus(200);
        $response->assertJson([
            'msg' => 'Server Error',
            'success' => false,
        ]);
    }

    public function testItDoesNotLeakSensitiveInfoInHtmlWhenDebugIsFalse()
    {
        Config::set('app.debug', false);

        Route::get('test-route', fn () => throw new Exception('Super secret database password'));

        $response = $this->get('test-route');

        $response->assertStatus(500);
        $content = $response->getContent();
        $this->assertStringNotContainsString('Super secret database password', $content);
        $this->assertStringNotContainsString('Exception', $content);
        $this->assertStringNotContainsString('ExceptionHandlerTest.php', $content);
        $this->assertStringNotContainsString('Stack Trace', $content);
    }

    public function testItReportsRequestExceptions()
    {
        config(['logging.default' => 'test_log']);
        config(['logging.channels.test_log' => [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
        ]]);
        Log::setDefaultDriver('test_log');
        Http::fake([
            '*' => Http::response('a really long message is being returned', status: 500),
        ]);

        RequestException::truncateAt(8);
        try {
            Http::throw()->get('http://laravel.test');
        } catch (RequestException $requestException) {
            report($requestException);
        }

        $recordedLogs = Log::getLogger()->getHandlers()[0]->getRecords();
        $this->assertCount(1, $recordedLogs);
        $this->assertStringContainsString('a really (truncated...)', $recordedLogs[0]['message']);
    }

    #[DataProvider('exitCodesProvider')]
    public function testItReturnsNonZeroExitCodesForUncaughtExceptions($providers, $successful)
    {
        $basePath = static::applicationBasePath();
        $providers = json_encode($providers);

        $process = new PhpProcess(<<<EOF
<?php

require 'vendor/autoload.php';

\$app = Hypervel\\Testbench\\Foundation\\Application::create(basePath: '{$basePath}', options: ['extra' => ['providers' => {$providers}]]);
\$app->singleton('Hypervel\\Contracts\\Debug\\ExceptionHandler', 'Hypervel\\Foundation\\Exceptions\\Handler');

\$kernel = \$app[Hypervel\\Contracts\\Console\\Kernel::class];

return \$kernel->call('throw-exception-command');
EOF, __DIR__ . '/../../../', ['APP_RUNNING_IN_CONSOLE' => true]);

        $process->run();

        $this->assertSame($successful, $process->isSuccessful());
    }

    public static function exitCodesProvider(): array
    {
        return [
            'Throw exception' => [[Fixtures\Providers\ThrowUncaughtExceptionServiceProvider::class], false],
            'Do not throw exception' => [[Fixtures\Providers\ThrowExceptionServiceProvider::class], true],
        ];
    }
}
