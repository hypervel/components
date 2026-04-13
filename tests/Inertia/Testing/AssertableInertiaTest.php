<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Testing;

use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Middleware;
use Hypervel\Inertia\Testing\AssertableInertia;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Inertia\TestCase;
use PHPUnit\Framework\AssertionFailedError;

/**
 * @internal
 * @coversNothing
 */
class AssertableInertiaTest extends TestCase
{
    public function testTheViewIsServedByInertia(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo')
        );

        $response->assertInertia();
    }

    public function testTheViewIsNotServedByInertia(): void
    {
        $response = $this->makeMockRequest(view('welcome'));
        $response->assertOk(); // Make sure we can render the built-in Orchestra 'welcome' view..

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Not a valid Inertia response.');

        $response->assertInertia();
    }

    public function testTheComponentMatches(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo')
        );

        $response->assertInertia(function ($inertia) {
            $inertia->component('foo');
        });
    }

    public function testTheComponentDoesNotMatch(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo')
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Unexpected Inertia page component.');

        $response->assertInertia(function ($inertia) {
            $inertia->component('bar');
        });
    }

    public function testTheComponentExistsOnTheFilesystem(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('Fixtures/ExamplePage')
        );

        config()->set('inertia.testing.ensure_pages_exist', true);
        $response->assertInertia(function ($inertia) {
            $inertia->component('Fixtures/ExamplePage');
        });
    }

    public function testTheComponentExistsOnTheFilesystemWhenAComponentResolverIsConfigured(): void
    {
        $calledWith = null;

        Inertia::transformComponentUsing(static function (string $name) use (&$calledWith): string {
            $calledWith = $name;

            return "{$name}/Page";
        });

        $response = $this->makeMockRequest(
            Inertia::render('Fixtures/Example')
        );

        config()->set('inertia.testing.ensure_pages_exist', true);

        $response->assertInertia(function ($inertia) {
            $inertia->component('Fixtures/Example/Page');
        });

        $this->assertSame('Fixtures/Example', $calledWith);
    }

    public function testTheComponentDoesNotExistOnTheFilesystem(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo')
        );

        config()->set('inertia.testing.ensure_pages_exist', true);
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Inertia page component file [foo] does not exist.');

        $response->assertInertia(function ($inertia) {
            $inertia->component('foo');
        });
    }

    public function testItCanForceEnableTheComponentFileExistence(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo')
        );

        config()->set('inertia.testing.ensure_pages_exist', false);
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Inertia page component file [foo] does not exist.');

        $response->assertInertia(function ($inertia) {
            $inertia->component('foo', true);
        });
    }

    public function testItCanForceDisableTheComponentFileExistenceCheck(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo')
        );

        config()->set('inertia.testing.ensure_pages_exist', true);

        $response->assertInertia(function ($inertia) {
            $inertia->component('foo', false);
        });
    }

    public function testTheComponentDoesNotExistOnTheFilesystemWhenItDoesNotExistRelativeToAnyOfTheGivenPaths(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('fixtures/ExamplePage')
        );

        config()->set('inertia.testing.ensure_pages_exist', true);
        config()->set('inertia.pages.paths', [realpath(__DIR__)]);
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Inertia page component file [fixtures/ExamplePage] does not exist.');

        $response->assertInertia(function ($inertia) {
            $inertia->component('fixtures/ExamplePage');
        });
    }

    public function testTheComponentDoesNotExistOnTheFilesystemWhenItDoesNotHaveOneOfTheConfiguredExtensions(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('fixtures/ExamplePage')
        );

        config()->set('inertia.testing.ensure_pages_exist', true);
        config()->set('inertia.pages.extensions', ['bin', 'exe', 'svg']);
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Inertia page component file [fixtures/ExamplePage] does not exist.');

        $response->assertInertia(function ($inertia) {
            $inertia->component('fixtures/ExamplePage');
        });
    }

    public function testThePageUrlMatches(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo')
        );

        $response->assertInertia(function ($inertia) {
            $inertia->url('/example-url');
        });
    }

    public function testThePageUrlDoesNotMatch(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo')
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Unexpected Inertia page url.');

        $response->assertInertia(function ($inertia) {
            $inertia->url('/invalid-page');
        });
    }

    public function testTheAssetVersionMatches(): void
    {
        Inertia::version('example-version');

        $response = $this->makeMockRequest(
            Inertia::render('foo')
        );

        $response->assertInertia(function ($inertia) {
            $inertia->version('example-version');
        });
    }

    public function testTheAssetVersionDoesNotMatch(): void
    {
        Inertia::version('example-version');

        $response = $this->makeMockRequest(
            Inertia::render('foo')
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Unexpected Inertia asset version.');

        $response->assertInertia(function ($inertia) {
            $inertia->version('different-version');
        });
    }

    public function testReloadingAVisit(): void
    {
        $foo = 0;

        $response = $this->makeMockRequest(function () use (&$foo) {
            return Inertia::render('foo', [
                'foo' => $foo++,
            ]);
        });

        $called = false;

        $response->assertInertia(function ($inertia) use (&$called) {
            $inertia->where('foo', 0);

            $inertia->reload(function ($inertia) use (&$called) {
                $inertia->where('foo', 1);
                $called = true;
            });
        });

        $this->assertTrue($called);
    }

    public function testOptionalPropsCanBeEvaluated(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo', [
                'foo' => 'bar',
                'optional1' => Inertia::optional(fn () => 'baz'),
                'optional2' => Inertia::optional(fn () => 'qux'),
            ])
        );

        $called = false;

        $response->assertInertia(function ($inertia) use (&$called) {
            $inertia->where('foo', 'bar');
            $inertia->missing('optional1');
            $inertia->missing('optional2');

            $result = $inertia->reloadOnly('optional1', function ($inertia) use (&$called) {
                $inertia->missing('foo');
                $inertia->where('optional1', 'baz');
                $inertia->missing('optional2');
                $called = true;
            });

            $this->assertSame($result, $inertia);
        });

        $this->assertTrue($called);
    }

    public function testOptionalPropsCanBeEvaluatedWithExcept(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo', [
                'foo' => 'bar',
                'lazy1' => Inertia::optional(fn () => 'baz'),
                'lazy2' => Inertia::optional(fn () => 'qux'),
            ])
        );

        $called = false;

        $response->assertInertia(function ($inertia) use (&$called) {
            $inertia->where('foo', 'bar');
            $inertia->missing('lazy1');
            $inertia->missing('lazy2');

            $result = $inertia->reloadOnly(['lazy1'], function ($inertia) use (&$called) {
                $inertia->missing('foo');
                $inertia->where('lazy1', 'baz');
                $inertia->missing('lazy2');
                $called = true;
            });

            $this->assertSame($result, $inertia);
        });

        $this->assertTrue($called);
    }

    public function testLazyPropsCanBeEvaluatedWithExcept(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo', [
                'foo' => 'bar',
                'optional1' => Inertia::optional(fn () => 'baz'),
                'optional2' => Inertia::optional(fn () => 'qux'),
            ])
        );

        $called = false;

        $response->assertInertia(function (AssertableInertia $inertia) use (&$called) {
            $inertia->where('foo', 'bar');
            $inertia->missing('optional1');
            $inertia->missing('optional2');

            $inertia->reloadExcept('optional1', function ($inertia) use (&$called) {
                $inertia->where('foo', 'bar');
                $inertia->missing('optional1');
                $inertia->where('optional2', 'qux');
                $called = true;
            });
        });

        $this->assertTrue($called);
    }

    public function testLazyPropsCanBeEvaluatedWithExceptWhenExceptIsArray(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo', [
                'foo' => 'bar',
                'lazy1' => Inertia::optional(fn () => 'baz'),
                'lazy2' => Inertia::optional(fn () => 'qux'),
            ])
        );

        $called = false;

        $response->assertInertia(function ($inertia) use (&$called) {
            $inertia->where('foo', 'bar');
            $inertia->missing('lazy1');
            $inertia->missing('lazy2');

            $inertia->reloadExcept(['lazy1'], function ($inertia) use (&$called) {
                $inertia->where('foo', 'bar');
                $inertia->missing('lazy1');
                $inertia->where('lazy2', 'qux');
                $called = true;
            });
        });

        $this->assertTrue($called);
    }

    public function testAssertAgainstDeferredProps(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo', [
                'foo' => 'bar',
                'deferred1' => Inertia::defer(fn () => 'baz'),
                'deferred2' => Inertia::defer(fn () => 'qux', 'custom'),
                'deferred3' => Inertia::defer(fn () => 'quux', 'custom'),
            ])
        );

        $called = 0;

        $response->assertInertia(function (AssertableInertia $inertia) use (&$called) {
            $inertia->where('foo', 'bar');
            $inertia->missing('deferred1');
            $inertia->missing('deferred2');
            $inertia->missing('deferred3');

            $inertia->loadDeferredProps(function (AssertableInertia $inertia) use (&$called) {
                $inertia->where('deferred1', 'baz');
                $inertia->where('deferred2', 'qux');
                $inertia->where('deferred3', 'quux');
                ++$called;
            });

            $inertia->loadDeferredProps('default', function (AssertableInertia $inertia) use (&$called) {
                $inertia->where('deferred1', 'baz');
                $inertia->missing('deferred2');
                $inertia->missing('deferred3');
                ++$called;
            });

            $inertia->loadDeferredProps('custom', function (AssertableInertia $inertia) use (&$called) {
                $inertia->missing('deferred1');
                $inertia->where('deferred2', 'qux');
                $inertia->where('deferred3', 'quux');
                ++$called;
            });

            $inertia->loadDeferredProps(['default', 'custom'], function (AssertableInertia $inertia) use (&$called) {
                $inertia->where('deferred1', 'baz');
                $inertia->where('deferred2', 'qux');
                $inertia->where('deferred3', 'quux');
                ++$called;
            });
        });

        $this->assertSame(4, $called);
    }

    public function testTheFlashDataCanBeAsserted(): void
    {
        $response = $this->makeMockRequest(
            fn () => Inertia::render('foo')->flash([
                'message' => 'Hello World',
                'notification' => ['type' => 'success'],
            ]),
            StartSession::class
        );

        $response->assertInertia(function (AssertableInertia $inertia) {
            $inertia->hasFlash('message');
            $inertia->hasFlash('message', 'Hello World');
            $inertia->hasFlash('notification.type', 'success');
            $inertia->missingFlash('other');
            $inertia->missingFlash('notification.other');
        });
    }

    public function testTheFlashAssertionFailsWhenKeyIsMissing(): void
    {
        $response = $this->makeMockRequest(Inertia::render('foo'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Inertia Flash Data is missing key [message].');

        $response->assertInertia(fn (AssertableInertia $inertia) => $inertia->hasFlash('message'));
    }

    public function testTheFlashAssertionFailsWhenValueDoesNotMatch(): void
    {
        $response = $this->makeMockRequest(
            fn () => Inertia::render('foo')->flash('message', 'Hello World'),
            StartSession::class
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Inertia Flash Data [message] does not match expected value.');

        $response->assertInertia(fn (AssertableInertia $inertia) => $inertia->hasFlash('message', 'Different'));
    }

    public function testTheMissingFlashAssertionFailsWhenKeyExists(): void
    {
        $response = $this->makeMockRequest(
            fn () => Inertia::render('foo')->flash('message', 'Hello World'),
            StartSession::class
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Inertia Flash Data has unexpected key [message].');

        $response->assertInertia(fn (AssertableInertia $inertia) => $inertia->missingFlash('message'));
    }

    public function testTheFlashDataIsAvailableAfterRedirect(): void
    {
        $middleware = [StartSession::class, Middleware::class];

        Route::middleware($middleware)->get('/action', function () {
            Inertia::flash('message', 'Success!');

            return redirect('/dashboard');
        });

        Route::middleware($middleware)->get('/dashboard', function () {
            return Inertia::render('Dashboard');
        });

        $this->get('/action')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $inertia) => $inertia->hasFlash('message', 'Success!'));
    }

    public function testTheFlashDataIsAvailableAfterDoubleRedirect(): void
    {
        $middleware = [StartSession::class, Middleware::class];

        Route::middleware($middleware)->get('/action', function () {
            Inertia::flash('message', 'Success!');

            return redirect('/intermediate');
        });

        Route::middleware($middleware)->get('/intermediate', function () {
            return redirect('/dashboard');
        });

        Route::middleware($middleware)->get('/dashboard', function () {
            return Inertia::render('Dashboard');
        });

        $this->get('/action')->assertRedirect('/intermediate');
        $this->get('/intermediate')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $inertia) => $inertia->hasFlash('message', 'Success!'));
    }
}
