<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Testing;

use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Middleware;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Facades\Route;
use Hypervel\Testing\Fluent\AssertableJson;
use Hypervel\Testing\TestResponse;
use Hypervel\Tests\Inertia\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class TestResponseMacrosTest extends TestCase
{
    public function testItCanMakeInertiaAssertions(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo')
        );

        $success = false;
        $response->assertInertia(function ($page) use (&$success) {
            $this->assertInstanceOf(AssertableJson::class, $page);
            $success = true;
        });

        $this->assertTrue($success);
    }

    public function testItPreservesTheAbilityToContinueChainingLaravelTestResponseCalls(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo')
        );

        $this->assertInstanceOf(
            TestResponse::class,
            $response->assertInertia()
        );
    }

    public function testItCanRetrieveTheInertiaPage(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo', ['bar' => 'baz'])
        );

        tap($response->inertiaPage(), function (array $page) {
            $this->assertSame('foo', $page['component']);
            $this->assertSame(['bar' => 'baz'], $page['props']);
            $this->assertSame('/example-url', $page['url']);
            $this->assertSame('', $page['version']);
            $this->assertArrayNotHasKey('encryptHistory', $page);
            $this->assertArrayNotHasKey('clearHistory', $page);
        });
    }

    public function testItCanRetrieveTheInertiaProps(): void
    {
        $props = ['bar' => 'baz'];
        $response = $this->makeMockRequest(
            Inertia::render('foo', $props)
        );

        $this->assertSame($props, $response->inertiaProps());
    }

    public function testItCanRetrieveNestedInertiaPropValuesWithDotNotation(): void
    {
        $response = $this->makeMockRequest(
            Inertia::render('foo', [
                'bar' => ['baz' => 'qux'],
                'users' => [
                    ['name' => 'John'],
                    ['name' => 'Jane'],
                ],
            ])
        );

        $this->assertSame('qux', $response->inertiaProps('bar.baz'));
        $this->assertSame('John', $response->inertiaProps('users.0.name'));
    }

    public function testItCanAssertFlashDataOnRedirectResponses(): void
    {
        $middleware = [StartSession::class, Middleware::class];

        Route::middleware($middleware)->post('/users', function () {
            return Inertia::flash([
                'message' => 'User created!',
                'notification' => ['type' => 'success'],
            ])->back();
        });

        $this->post('/users')
            ->assertRedirect()
            ->assertInertiaFlash('message')
            ->assertInertiaFlash('message', 'User created!')
            ->assertInertiaFlash('notification.type', 'success')
            ->assertInertiaFlashMissing('error')
            ->assertInertiaFlashMissing('notification.other');
    }

    public function testAssertHasInertiaFlashFailsWhenKeyIsMissing(): void
    {
        $middleware = [StartSession::class, Middleware::class];

        Route::middleware($middleware)->post('/users', function () {
            return Inertia::flash('message', 'Hello')->back();
        });

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Inertia Flash Data is missing key [other].');

        $this->post('/users')->assertInertiaFlash('other');
    }

    public function testAssertHasInertiaFlashFailsWhenValueDoesNotMatch(): void
    {
        $middleware = [StartSession::class, Middleware::class];

        Route::middleware($middleware)->post('/users', function () {
            return Inertia::flash('message', 'Hello')->back();
        });

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Inertia Flash Data [message] does not match expected value.');

        $this->post('/users')->assertInertiaFlash('message', 'Different');
    }

    public function testAssertMissingInertiaFlashFailsWhenKeyExists(): void
    {
        $middleware = [StartSession::class, Middleware::class];

        Route::middleware($middleware)->post('/users', function () {
            return Inertia::flash('message', 'Hello')->back();
        });

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Inertia Flash Data has unexpected key [message].');

        $this->post('/users')->assertInertiaFlashMissing('message');
    }
}
