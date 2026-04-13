<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Inertia\EncryptHistoryMiddleware;
use Hypervel\Inertia\Inertia;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Inertia\Fixtures\ExampleMiddleware;

/**
 * @internal
 * @coversNothing
 */
class HistoryTest extends TestCase
{
    public function testTheHistoryIsNotEncryptedOrClearedByDefault(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJsonMissing(['encryptHistory' => true]);
        $response->assertJsonMissing(['clearHistory' => true]);
    }

    public function testTheHistoryCanBeEncrypted(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::encryptHistory();

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'encryptHistory' => true,
        ]);
    }

    public function testTheHistoryCanBeEncryptedViaMiddleware(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class, EncryptHistoryMiddleware::class])->get('/', function () {
            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'encryptHistory' => true,
        ]);
    }

    public function testTheHistoryCanBeEncryptedViaMiddlewareAlias(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class, 'inertia.encrypt'])->get('/', function () {
            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'encryptHistory' => true,
        ]);
    }

    public function testTheHistoryCanBeEncryptedGlobally(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Config::set('inertia.history.encrypt', true);

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'encryptHistory' => true,
        ]);
    }

    public function testTheHistoryCanBeEncryptedGloballyAndOverridden(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Config::set('inertia.history.encrypt', true);

            Inertia::encryptHistory(false);

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJsonMissing(['encryptHistory' => true]);
    }

    public function testTheHistoryCanBeCleared(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::clearHistory();

            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'component' => 'User/Edit',
            'clearHistory' => true,
        ]);
    }

    public function testTheHistoryCanBeClearedWhenRedirecting(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::clearHistory();

            return redirect('/users');
        });

        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/users', function () {
            return Inertia::render('User/Edit');
        });

        $this->followingRedirects();

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertContent('<script data-page="app" type="application/json">{"component":"User\/Edit","props":{"errors":{}},"url":"\/users","version":"","sharedProps":["errors"],"clearHistory":true}</script><div id="app"></div>');
    }

    public function testTheFragmentIsNotPreservedByDefault(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            return Inertia::render('User/Edit');
        });

        $response = $this->withoutExceptionHandling()->get('/', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJsonMissing([
            'preserveFragment' => true,
        ]);
    }

    public function testTheFragmentCanBePreservedViaInertiaFacade(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            Inertia::preserveFragment();

            return redirect('/users');
        });

        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/users', function () {
            return Inertia::render('User/Edit');
        });

        $this->withoutExceptionHandling()->get('/');

        $response = $this->withoutExceptionHandling()->get('/users', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'preserveFragment' => true,
        ]);
    }

    public function testTheFragmentCanBePreservedViaRedirectMacro(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/', function () {
            return redirect('/users')->preserveFragment(); /* @phpstan-ignore method.notFound */
        });

        Route::middleware([StartSession::class, ExampleMiddleware::class])->get('/users', function () {
            return Inertia::render('User/Edit');
        });

        $this->withoutExceptionHandling()->get('/');

        $response = $this->withoutExceptionHandling()->get('/users', [
            'X-Inertia' => 'true',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'preserveFragment' => true,
        ]);
    }
}
