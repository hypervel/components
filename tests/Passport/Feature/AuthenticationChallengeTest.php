<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Passport\Http\Middleware\CheckToken;
use Hypervel\Passport\Passport;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Passport\Fixtures\User;
use PHPUnit\Framework\Attributes\TestWith;

class AuthenticationChallengeTest extends PassportTestCase
{
    #[TestWith([false, null, 'Bearer'])]
    #[TestWith([false, 'Bearer invalid', 'Bearer error="invalid_token"'])]
    #[TestWith([true, null, 'Bearer, DPoP algs="RS256 ES256"'])]
    #[TestWith([true, 'Basic invalid', 'Bearer, DPoP algs="RS256 ES256"'])]
    #[TestWith([true, 'DPoP invalid', 'Bearer, DPoP algs="RS256 ES256", error="invalid_token"'])]
    #[TestWith([false, 'DPoP invalid', 'Bearer'])]
    public function testGuardChallengesDescribeOnlyEnabledSchemes(bool $enabled, ?string $authorization, string $challenge): void
    {
        config(['passport.dpop.enabled' => $enabled]);
        Route::get('/protected', static fn (): string => 'Allowed')->middleware('auth:passport');

        $this->getJson('/protected', $authorization === null ? [] : ['Authorization' => $authorization])
            ->assertUnauthorized()->assertHeader('WWW-Authenticate', $challenge);
    }

    public function testMixedGuardBrowserFailuresKeepTheirLoginRedirect(): void
    {
        config(['passport.dpop.enabled' => true]);
        Route::get('/login', static fn (): string => 'Login')->name('login');
        Route::get('/protected', static fn (): string => 'Allowed')->middleware('auth:web,passport');

        $this->get('/protected')->assertRedirect('/login')->assertHeaderMissing('WWW-Authenticate');
    }

    public function testChallengePreservesTheApplicationsAuthenticationResponse(): void
    {
        Route::get('/protected', static fn (): string => 'Allowed')->middleware('auth:passport');
        $this->app->make(ExceptionHandler::class)->renderable(static fn (AuthenticationException $exception): JsonResponse => new JsonResponse(['message' => 'Please sign in'], 401));

        $this->getJson('/protected')->assertUnauthorized()->assertExactJson(['message' => 'Please sign in'])
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function testApplicationChallengesAreNotReplaced(): void
    {
        Route::get('/protected', static fn (): string => 'Allowed')->middleware('auth:passport');
        $this->app->make(ExceptionHandler::class)->renderable(static fn (AuthenticationException $exception): JsonResponse => new JsonResponse(
            ['message' => 'Please sign in'],
            401,
            ['WWW-Authenticate' => 'Bearer realm="application"'],
        ));

        $this->getJson('/protected')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer realm="application"');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testSuccessfulRequestsDoNotResolveChallengesAfterAnEmptyGuardLookup(bool $anotherGuard): void
    {
        $calls = 0;
        Passport::resolveResourceMetadataUsing(static function (Request $request) use (&$calls): ?string {
            ++$calls;

            return 'https://resource.example/metadata';
        });

        if ($anotherGuard) {
            $this->actingAs(new User, 'web');
            Route::get('/optional', static fn (): string => 'Allowed')->middleware('auth:passport,web');
        } else {
            Route::get('/optional', function (): string {
                $this->assertNull($this->app->make('auth')->guard('passport')->user());

                return 'Allowed';
            });
        }

        $this->getJson('/optional')->assertOk()->assertHeaderMissing('WWW-Authenticate');
        $this->assertSame(0, $calls);
    }

    public function testScopeFailuresReceiveChallengesThroughTheHttpExceptionWrapper(): void
    {
        Passport::actingAs(new User, [], 'passport');
        Route::get('/scoped', static fn (): string => 'Allowed')->middleware(['auth:passport', CheckToken::using('read')]);

        $this->getJson('/scoped', ['Authorization' => 'Bearer test-token'])->assertForbidden()
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="read"');
    }

    public function testMiddlewareAuthenticationFailuresReceiveChallenges(): void
    {
        Route::get('/machine', static fn (): string => 'Allowed')->middleware(CheckToken::using('read'));

        $this->getJson('/machine', ['Authorization' => 'Bearer invalid'])->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    }

    public function testGuardCallbacksDoNotAffectOtherGuardsOrReplacementRequests(): void
    {
        $request = Request::create('/protected');
        $request->headers->set('Accept', 'application/json');
        RequestContext::set($request);
        $this->assertNull($this->app->make('auth')->guard('passport')->user());
        $handler = $this->app->make(ExceptionHandler::class);

        $this->assertFalse($handler->render($request, new AuthenticationException(guards: ['web']))->headers->has('WWW-Authenticate'));
        config(['auth.defaults.guard' => 'passport']);
        $replacement = Request::create('/another');
        $replacement->headers->set('Accept', 'application/json');
        RequestContext::set($replacement);
        $this->assertFalse($handler->render($replacement, new AuthenticationException(guards: ['passport']))->headers->has('WWW-Authenticate'));
        $this->assertNull($this->app->make('auth')->guard('passport')->user());
        $this->assertSame('Bearer', $handler->render($replacement, new AuthenticationException(guards: [null]))->headers->get('WWW-Authenticate'));
    }
}
