<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use DateInterval;
use Hypervel\Auth\PasswordConfirmation;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Http\Exceptions\HttpResponseException;
use Hypervel\Http\Request;
use Hypervel\Passport\AuthCode;
use Hypervel\Passport\Consent;
use Hypervel\Passport\Contracts\ReauthenticationResponse;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Enums\AuthorizationApproval;
use Hypervel\Passport\Events\AuthorizationApproved;
use Hypervel\Passport\Events\AuthorizationRequestValidated;
use Hypervel\Passport\Events\AuthorizationUserResolved;
use Hypervel\Passport\Passport;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use League\OAuth2\Server\Exception\OAuthServerException;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;

class AuthorizationInteractionTest extends PassportTestCase
{
    use WithHypervelMigrations;

    /**
     * Register browser views and the application's confirmation route.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Passport::tokensCan(['read' => 'Read', 'write' => 'Write']);
        Passport::authorizationView(static fn (array $parameters): array => $parameters);
        Passport::accountSelectionView(static fn (array $parameters): array => $parameters);
        Route::get('/confirm', static fn (): string => 'Confirm')->name('password.confirm');
        Route::get('/login', static fn (): string => 'Login')->name('login');
    }

    public function testGuestLoginSatisfiesFreshAuthenticationWithoutAnotherConfirmation(): void
    {
        $client = ClientFactory::new()->create();
        $user = UserFactory::new()->create();
        Route::post('/perform-login', static function () use ($user) {
            Auth::guard('web')->login($user, true);

            return redirect()->intended();
        })->middleware('web');

        $resume = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'response_type' => 'code', 'prompt' => 'login',
        ]))->assertRedirect()->headers->get('Location');
        $this->get($resume)->assertRedirectToRoute('login');
        $this->post('/perform-login')->assertRedirect($resume);
        $token = $this->get($resume)->assertOk()->json('authToken');
        $this->post('/oauth/authorize/consent', ['auth_token' => $token])->assertRedirect();
        $this->assertDatabaseHas('oauth_auth_codes', ['user_id' => $user->id]);
    }

    public function testRecallOnAnEarlierRequestDoesNotSatisfyFreshAuthentication(): void
    {
        $client = ClientFactory::new()->create();
        $user = UserFactory::new()->create(['remember_token' => 'previous-login']);
        $guard = Auth::guard('web');
        $recaller = $user->id . '|previous-login|' . $guard->hashPasswordForCookie($user->getAuthPassword());
        Route::get('/authentication-evidence', static function (): array {
            $guard = Auth::guard('web');

            return ['user' => $guard->user()?->getAuthIdentifier(), 'recalled' => $guard->viaRemember()];
        })->middleware('web');

        // A browser may withhold an existing remember cookie on the initial cross-site request.
        $resume = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'response_type' => 'code', 'prompt' => 'login',
        ]))->assertRedirect()->headers->get('Location');
        $this->withCookie($guard->getRecallerName(), $recaller)->get('/authentication-evidence')
            ->assertExactJson(['user' => $user->id, 'recalled' => true]);
        $this->get('/authentication-evidence')->assertExactJson(['user' => $user->id, 'recalled' => false]);
        $this->get($resume)->assertRedirectToRoute('password.confirm');
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testConfirmationClearsOnlyItsGuardAndAcceptsANewSameSecondConfirmation(): void
    {
        $this->freezeTime();
        $client = ClientFactory::new()->create();
        $this->actingAs($user = UserFactory::new()->create(), 'web');
        $key = PasswordConfirmation::sessionKey('web');
        $otherKey = PasswordConfirmation::sessionKey('staff');
        $this->withSession([$key => now()->getTimestamp(), $otherKey => now()->getTimestamp()])
            ->get('/oauth/authorize?' . http_build_query([
                'client_id' => $client->id, 'response_type' => 'code', 'prompt' => 'login',
            ]))->assertRedirectToRoute('password.confirm')->assertSessionMissing($key)->assertSessionHas($otherKey);
        $resume = session()->get('url.intended');
        $this->assertAuthenticatedAs($user, 'web');
        $this->get($resume)->assertRedirectToRoute('password.confirm');

        $token = $this->withSession([$key => now()->getTimestamp()])->get($resume)->assertOk()->json('authToken');
        $this->post('/oauth/authorize/consent', ['auth_token' => $token])->assertRedirect();
        $this->get($resume)->assertForbidden();
        $this->assertDatabaseCount('oauth_auth_codes', 1);
    }

    public function testCustomConfirmationResponseReceivesTheClient(): void
    {
        $client = ClientFactory::new()->create();
        $this->actingAs(UserFactory::new()->create(), 'web');
        $response = m::mock(ReauthenticationResponse::class);
        $response->shouldReceive('withParameters')->once()->with(m::on(
            static fn (array $parameters): bool => $parameters['client']->is($client),
        ))->andReturnSelf();
        $response->shouldReceive('toResponse')->once()->andReturn(response('Confirm identity'));
        $this->app->instance(ReauthenticationResponse::class, $response);

        $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'response_type' => 'code', 'prompt' => 'login',
        ]))->assertOk()->assertSee('Confirm identity');
    }

    public function testConfirmationCannotResumeForAnotherUser(): void
    {
        $client = ClientFactory::new()->create();
        $this->actingAs(UserFactory::new()->create(), 'web');
        $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'response_type' => 'code', 'prompt' => 'login',
        ]))->assertRedirectToRoute('password.confirm');
        $resume = session()->get('url.intended');

        $this->actingAs(UserFactory::new()->create(), 'web');
        $this->withSession([PasswordConfirmation::sessionKey('web') => now()->getTimestamp()])
            ->get($resume)->assertForbidden();
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testAccountChoiceIsRequiredOnceBeforeConsent(): void
    {
        $client = ClientFactory::new()->create();
        $this->actingAs(UserFactory::new()->create(), 'web');
        $token = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'response_type' => 'code', 'prompt' => 'select_account',
        ]))->assertOk()->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertJsonPath('client.id', $client->id)->json('authToken');

        $resume = $this->post('/oauth/authorize/account/continue', ['auth_token' => $token])
            ->assertRedirect()->headers->get('Location');
        $this->post('/oauth/authorize/account/continue', ['auth_token' => $token])->assertForbidden();
        $consent = $this->get($resume . '?client_id=override&prompt=none')->assertOk()->json();
        $this->assertSame($client->id, $consent['client']['id']);
        $this->assertSame($token, $consent['authToken']);
        $this->post('/oauth/authorize/consent', ['auth_token' => $token])->assertRedirect();
        $this->assertDatabaseCount('oauth_auth_codes', 1);
    }

    public function testAccountSwitchSignsOutAndPreservesTheOriginalExpiry(): void
    {
        $this->freezeTime();
        $client = ClientFactory::new()->create();
        $this->actingAs($first = UserFactory::new()->create(), 'web');
        Event::listen(AuthorizationUserResolved::class, static function (AuthorizationUserResolved $event) use ($first): void {
            $event->permissions = ['profile.claims' => $event->user->is($first) ? ['name'] : ['email']];
        });
        $token = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'response_type' => 'code',
        ]))->assertOk()->json('authToken');
        Event::listen(AuthorizationRequestValidated::class, static function (AuthorizationRequestValidated $event): void {
            $event->prompts[] = 'select_account';
        });
        $this->get('/oauth/authorize/resume/' . $token)->assertOk();
        $this->travel(9)->minutes();

        $resume = $this->post('/oauth/authorize/account/switch', ['auth_token' => $token])
            ->assertRedirect()->assertSessionMissing('passport.authorizations.' . $token)->headers->get('Location');
        $this->assertGuest('web');
        $this->get($resume)->assertRedirectToRoute('login');
        $this->actingAs($other = UserFactory::new()->create(), 'web');
        $choice = $this->get($resume)->assertOk()->json();
        $this->assertSame($other->id, $choice['user']['id']);
        $this->post('/oauth/authorize/account/continue', ['auth_token' => $choice['authToken']])->assertRedirect();
        $presentation = $this->get($resume)->assertOk()->json();
        $this->assertSame(['profile.claims' => ['email']], $presentation['permissions']);

        $this->travel(1)->minute();
        $this->post('/oauth/authorize/consent', ['auth_token' => $presentation['authToken']])->assertForbidden();
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testReopeningConsentPreservesItsPresentationAndRefreshesAttributes(): void
    {
        $this->freezeSecond();
        $client = ClientFactory::new()->create();
        $this->actingAs(UserFactory::new()->create(), 'web');
        $reopened = false;
        Event::listen(AuthorizationUserResolved::class, static function (AuthorizationUserResolved $event) use (&$reopened): void {
            $event->permissions = ['profile.claims' => $reopened ? ['name', 'email'] : ['name']];
            $event->rememberConsentFor = new DateInterval($reopened ? 'P30D' : 'PT1H');
            $event->attributes['authentication_generation'] = $reopened ? 2 : 1;
        });
        $generation = null;
        Event::listen(AuthorizationApproved::class, static function (AuthorizationApproved $event) use (&$generation): void {
            $generation = $event->attributes['authentication_generation'];
            unset($event->attributes['authentication_generation']);
        });
        $token = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'response_type' => 'code',
        ]))->assertOk()->json('authToken');

        $reopened = true;
        $presentation = $this->get('/oauth/authorize/resume/' . $token)->assertOk()->json();
        $this->assertSame($token, $presentation['authToken']);
        $this->assertSame(['profile.claims' => ['name']], $presentation['permissions']);
        $this->post('/oauth/authorize/consent', ['auth_token' => $token])->assertRedirect();

        $consent = Consent::sole();
        $this->assertSame(['profile.claims' => ['name']], $consent->permissions);
        $this->assertTrue($consent->remembered_until->equalTo(now()->addHour()));
        $this->assertSame(2, $generation);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testResumedProtocolErrorsConsumeTheOlderFormButApplicationRedirectsDoNot(bool $resolverError): void
    {
        $client = ClientFactory::new()->create();
        $this->actingAs(UserFactory::new()->create(), 'web');
        $token = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'response_type' => 'code', 'state' => 'original',
        ]))->assertOk()->json('authToken');
        $resume = '/oauth/authorize/resume/' . $token;
        $action = 'redirect';
        Event::listen(AuthorizationUserResolved::class, static function () use (&$action): void {
            if ($action === 'redirect') {
                throw new HttpResponseException(redirect('/application-confirmation'));
            }

            if ($action === 'deny') {
                throw OAuthServerException::accessDenied();
            }
        });
        $this->get($resume)->assertRedirect('/application-confirmation')
            ->assertSessionHas('passport.authorizations.' . $token);
        $action = $resolverError ? 'continue' : 'deny';

        if ($resolverError) {
            $client->update(['revoked' => true]);
            $this->get($resume)->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
            $client->update(['revoked' => false]);
        } else {
            $response = $this->get($resume)->assertRedirect();
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
            $this->assertSame('access_denied', $parameters['error']);
            $this->assertSame('original', $parameters['state']);
        }

        $action = 'continue';
        $this->post('/oauth/authorize/consent', ['auth_token' => $token])->assertForbidden();
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testCompletingOneTabDoesNotConsumeAnotherAndRejectsDecisionReplay(): void
    {
        $client = ClientFactory::new()->create();
        $this->actingAs(UserFactory::new()->create(), 'web');
        $url = '/oauth/authorize?' . http_build_query(['client_id' => $client->id, 'response_type' => 'code']);
        $first = $this->get($url . '&state=first')->assertOk()->json('authToken');
        $second = $this->get($url . '&state=second')->assertOk()->json('authToken');

        $this->post('/oauth/authorize/consent', ['auth_token' => $first])->assertRedirect()
            ->assertSessionHas('passport.authorizations.' . $second);
        $this->post('/oauth/authorize/consent', ['auth_token' => $first])->assertForbidden();
        $response = $this->delete('/oauth/authorize/consent', ['auth_token' => $second])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame('second', $parameters['state']);
        $this->assertSame('access_denied', $parameters['error']);
        $this->assertDatabaseCount('oauth_auth_codes', 1);
    }

    #[TestWith(['query'])]
    #[TestWith(['form_post'])]
    public function testApprovalEventRejectionRollsBackConsentAndUsesTheSavedTransport(string $mode): void
    {
        $client = ClientFactory::new()->create(['redirect_uris' => ['https://client.example/callback?source=app']]);
        $this->actingAs(UserFactory::new()->create(), 'web');
        $token = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'response_type' => 'code', 'response_mode' => $mode, 'state' => ' original ',
        ]))->assertOk()->json('authToken');
        Event::listen(AuthorizationApproved::class, static fn (): never => throw OAuthServerException::accessDenied());

        $response = $this->post('/oauth/authorize/consent', [
            'auth_token' => $token, 'response_mode' => 'ignored', 'state' => 'ignored', 'redirect_uri' => 'https://other.example',
        ])->assertSessionMissing('passport.authorizations.' . $token);

        if ($mode === 'query') {
            $response->assertRedirect();
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
            $this->assertSame('access_denied', $parameters['error']);
            $this->assertSame(' original ', $parameters['state']);
            $this->assertSame('app', $parameters['source']);
        } else {
            $response->assertOk()->assertHeaderMissing('Location')
                ->assertSee('action="https://client.example/callback?source=app"', false)
                ->assertSee('name="error" value="access_denied"', false)
                ->assertSee('name="state" value=" original "', false);
        }

        $this->assertDatabaseCount('oauth_consents', 0);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testFinalApprovalUsesSelectedPermissionsAndProtectsCoreFields(): void
    {
        $client = ClientFactory::new()->create();
        $this->actingAs($user = UserFactory::new()->create(), 'web');
        Event::listen(AuthorizationUserResolved::class, static function (AuthorizationUserResolved $event): void {
            $event->attributes['provider'] = 'wrong';
            $event->permissions = ['profile.claims' => ['name', 'email'], 'resources' => ['https://injected.example' => ['write']]];
        });
        $observed = [];
        Event::listen(AuthorizationApproved::class, static function (AuthorizationApproved $event) use (&$observed): void {
            $observed = [$event->approval, $event->scopes, $event->permissions];
            $event->attributes = ['provider' => 'wrong', 'consent_id' => 'wrong'];
        });
        $presentation = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'response_type' => 'code', 'scope' => 'read write',
        ]))->assertOk()->json();
        $this->assertSame(['profile.claims' => ['name', 'email']], $presentation['permissions']);

        $response = $this->post('/oauth/authorize/consent', [
            'auth_token' => $presentation['authToken'], 'scopes' => ['read'], 'permissions' => ['profile.claims' => ['name']],
        ])->assertRedirect();
        $this->assertSame([AuthorizationApproval::Prompt, ['read'], ['profile.claims' => ['name']]], $observed);
        $consent = Consent::sole();
        $code = AuthCode::sole();
        $this->assertSame($consent->id, $code->consent_id);
        $this->assertSame('users', $code->provider);
        $this->assertSame($user->id, $code->user_id);
        $this->assertSame(['read'], $code->scopes);

        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $parameters['code'],
            'client_id' => $client->id, 'client_secret' => $client->plainSecret,
        ])->assertOk()->assertJsonPath('scope', 'read');
    }

    #[TestWith([false, 'login_required'])]
    #[TestWith([true, 'consent_required'])]
    public function testSilentErrorsHonorFormPost(bool $authenticated, string $error): void
    {
        $client = ClientFactory::new()->create();

        if ($authenticated) {
            $this->actingAs(UserFactory::new()->create(), 'web');
        }

        $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'response_type' => 'code', 'response_mode' => 'form_post', 'prompt' => 'none', 'state' => 'silent',
        ]))->assertOk()->assertHeaderMissing('Location')
            ->assertSee('name="error" value="' . $error . '"', false)
            ->assertSee('name="state" value="silent"', false);
    }

    public function testProtocolPostPreservesTheSessionAndRepeatedResourcesWhileConsentRequiresCsrf(): void
    {
        $this->app->bind(PreventRequestForgery::class, PassportInteractionForgeryMiddleware::class);
        $client = ClientFactory::new()->create();
        $this->actingAs(UserFactory::new()->create(), 'web');
        Route::get('/session-marker', static fn (Request $request): array => [
            'marker' => $request->session()->get('marker'), 'id' => $request->session()->getId(),
        ])->middleware('web');
        $before = $this->withSession(['marker' => 'original'])->get('/session-marker')->assertOk()->json();
        $body = http_build_query(['client_id' => $client->id, 'response_type' => 'code'])
            . '&resource=https%3A%2F%2Fapi.example%2Fa&resource=https%3A%2F%2Fapi.example%2Fb';
        $response = $this->call('POST', '/oauth/authorize', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ], $body)->assertStatus(303)->assertHeaderMissing('Set-Cookie');
        $this->assertSame(2, substr_count($response->headers->get('Location'), 'resource='));
        $this->assertStringNotContainsString('resource%5B', $response->headers->get('Location'));
        // A browser omits its Lax cookie on the cross-site POST, then sends it on the top-level GET.
        $this->withCookie(config('session.cookie'), $before['id'])->get('/session-marker')->assertExactJson($before);

        $token = $this->get($response->headers->get('Location'))->assertOk()->json('authToken');
        $this->post('/oauth/authorize/consent', ['auth_token' => $token])->assertStatus(419);
        $this->post('/oauth/authorize/consent', ['auth_token' => $token, '_token' => session()->token()])->assertRedirect();
        $this->assertDatabaseCount('oauth_auth_codes', 1);
    }
}

class PassportInteractionForgeryMiddleware extends PreventRequestForgery
{
    /**
     * Exercise the application's CSRF protection in browser interaction tests.
     */
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
