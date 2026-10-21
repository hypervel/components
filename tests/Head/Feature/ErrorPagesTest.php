<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature;

use Closure;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Foundation\Exceptions\Handler;
use Hypervel\Head\ErrorPages;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadBuilder;
use Hypervel\Http\Exceptions\HttpResponseException;
use Hypervel\Http\Request;
use Hypervel\Inertia\ExceptionResponse;
use Hypervel\Inertia\Inertia;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Head\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ErrorPagesTest extends TestCase
{
    /**
     * Register an application renderer before Head's status callback.
     */
    protected function resolveApplicationExceptionHandler(Application $app): void
    {
        parent::resolveApplicationExceptionHandler($app);

        /** @var Handler $handler */
        $handler = $app->make(ExceptionHandler::class);
        $handler->renderable(fn (HeadEarlyRenderedException $exception): Response => new Response('', 404));
    }

    public function testLetsErrorHeadBeatTheResolvedPageHead(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->title('Acme', suffix: ' - Acme'));

        Head::errors(function (ErrorPages $errors): void {
            $errors->defaults(robots: 'noindex, follow');

            $errors->status(
                404,
                title: 'Page Not Found',
                description: 'The page could not be found.',
            );
        });

        Route::get('/missing', fn (): string => Head::toHtml(404))->withHead(
            title: 'Original Page',
            description: 'Original description.',
        );

        $this->get('/missing')
            ->assertOk()
            ->assertSee('<title>Page Not Found - Acme</title>', false)
            ->assertSee('<meta name="description" content="The page could not be found.">', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function testAcceptsHeadBuilderCallbacksForErrorMetadata(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->title('Acme', suffix: ' - Acme'));

        Head::errors(function (ErrorPages $errors): void {
            $errors->defaults(fn (HeadBuilder $head): HeadBuilder => $head->hiddenFromRobots());

            $errors->status(404, fn (HeadBuilder $head): HeadBuilder => $head
                ->title('Page Not Found')
                ->description('The page could not be found.'));
        });

        Route::get('/missing', fn (): string => Head::toHtml(404))->withHead(
            title: 'Original Page',
        );

        $this->get('/missing')
            ->assertOk()
            ->assertSee('<title>Page Not Found - Acme</title>', false)
            ->assertSee('<meta name="description" content="The page could not be found.">', false)
            ->assertSee('<meta name="robots" content="none">', false);
    }

    public function testAppliesErrorHeadWhenAnAbortedRequestRendersAnErrorView(): void
    {
        config(['view.paths' => [__DIR__ . '/../Fixtures/views', ...config('view.paths')]]);

        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->title('Acme', suffix: ' - Acme'));

        Head::errors(function (ErrorPages $errors): void {
            $errors->defaults(robots: 'noindex, follow');

            $errors->status(404, title: 'Page Not Found');
        });

        $this->get('/definitely-missing')
            ->assertNotFound()
            ->assertSee('<title>Page Not Found - Acme</title>', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    #[DataProvider('exceptionResponses')]
    public function testUsesTheActualStatusBeforeInertiaRendersAnException(Closure $makeException): void
    {
        Head::errors(function (ErrorPages $errors): void {
            $errors->status(404, title: 'Page Not Found');
            $errors->status(500, title: 'Server Error');
        });
        Head::resolveErrorsUsing(function (HeadBuilder $head, int $status): void {
            $head->description('Error status ' . $status);
        });

        Inertia::handleExceptionsUsing(fn (ExceptionResponse $response): ExceptionResponse => $response->render('Error'));
        Route::aliasMiddleware('head.error', function (Request $request, Closure $next) use ($makeException): never {
            throw $makeException();
        });
        Route::get('/error-status', fn (): string => 'Unreachable')->middleware('head.error');

        $response = $this->get('/error-status', ['X-Inertia' => 'true'])
            ->assertNotFound()
            ->assertJsonPath('component', 'Error');

        $this->assertContains('<title data-inertia="title">Page Not Found</title>', $response->json('props.head'));
        $this->assertContains('<meta data-inertia="description" name="description" content="Error status 404">', $response->json('props.head'));
    }

    /**
     * Provide exception paths that infer the wrong status or bypass render callbacks.
     *
     * @return array<string, array{Closure(): Throwable}>
     */
    public static function exceptionResponses(): array
    {
        return [
            'embedded response' => [fn (): Throwable => new HttpResponseException(new Response('', 404))],
            'responsable exception' => [fn (): Throwable => new class extends RuntimeException implements Responsable {
                /**
                 * Render the exception's embedded response.
                 */
                public function toResponse(Request $request): Response
                {
                    return new Response('', 404);
                }
            }],
            'renderable exception' => [fn (): Throwable => new class extends RuntimeException {
                /**
                 * Render the exception's custom response.
                 */
                public function render(Request $request): Response
                {
                    return new Response('', 404);
                }
            }],
            'earlier application renderer' => [fn (): Throwable => new HeadEarlyRenderedException],
        ];
    }
}

class HeadEarlyRenderedException extends RuntimeException
{
}
