<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Closure;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Fortify\Contracts\CreatesNewUsers;
use Hypervel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationProvider as TwoFactorAuthenticationProviderContract;
use Hypervel\Fortify\Fortify;
use Hypervel\Fortify\FortifyServiceProvider;
use Hypervel\Fortify\RecoveryCode;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Configuration\ConfigMutationTracker;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Passkeys\Passkeys;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Session\Store;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Tests\Fortify\Fixtures\FixedClock;
use Mockery as m;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NullSessionHandler;

class FortifyServiceProviderTest extends TestCase
{
    public function testViewsCanBeCustomized(): void
    {
        Fortify::loginView(function () {
            return 'foo';
        });

        $response = $this->get('/login');

        $response->assertOk();
        $this->assertSame('foo', $response->content());
    }

    public function testCustomizedViewsCanReturnTheirOwnResponsable(): void
    {
        Fortify::loginView(function () {
            return new class implements Responsable {
                public function toResponse(Request $request): Response
                {
                    return new JsonResponse(['foo' => 'bar']);
                }
            };
        });

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertExactJson(['foo' => 'bar']);
    }

    public function testTwoFactorAuthenticationProviderUsesFrameworkClock(): void
    {
        $timestamp = 946684800;
        $clock = new FixedClock($timestamp);
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $code = TOTP::createFromSecret($secret, $clock)->at($timestamp);

        $this->app->instance(ClockInterface::class, $clock);
        $this->app->forgetInstance(TwoFactorAuthenticationProviderContract::class);

        $provider = $this->app->make(TwoFactorAuthenticationProviderContract::class);

        $this->assertTrue($provider->verify($secret, $code));
    }

    public function testPackageConfigDefaultsRemainAligned(): void
    {
        $this->unsetEnvironmentValue('PASSKEYS_TIMEOUT');

        $config = require dirname(__DIR__, 2) . '/src/fortify/config/fortify.php';
        $stub = require dirname(__DIR__, 2) . '/src/fortify/stubs/fortify.php';

        $this->assertSame('6,1', $config['limiters']['verification']);
        $this->assertSame('two-factor', $config['limiters']['two-factor']);
        $this->assertSame('two-factor', $stub['limiters']['two-factor']);
        $this->assertSame(Passkeys::DEFAULT_TIMEOUT, $config['passkeys']['timeout']);
    }

    public function testDefaultTwoFactorLimiterScopesAttemptsByGuardAndAccount(): void
    {
        $callback = $this->app->make(RateLimiter::class)->limiter('two-factor');
        $this->assertInstanceOf(Closure::class, $callback);

        $first = $callback($this->challengeRequest('session-a', 'web', 1, '192.0.2.1'));
        $sameAccountFromAnotherIp = $callback($this->challengeRequest('session-b', 'web', 1, '192.0.2.2'));
        $otherAccount = $callback($this->challengeRequest('session-c', 'web', 2, '192.0.2.1'));
        $otherGuard = $callback($this->challengeRequest('session-d', 'admin', 1, '192.0.2.1'));

        $this->assertInstanceOf(Limit::class, $first);
        $this->assertSame(5, $first->maxAttempts);
        $this->assertSame(60, $first->decaySeconds);
        $this->assertSame($first->key, $sameAccountFromAnotherIp->key);
        $this->assertNotSame($first->key, $otherAccount->key);
        $this->assertNotSame($first->key, $otherGuard->key);
    }

    public function testDefaultTwoFactorLimiterFallsBackToTheChallengeSession(): void
    {
        $callback = $this->app->make(RateLimiter::class)->limiter('two-factor');
        $this->assertInstanceOf(Closure::class, $callback);
        $firstSessionId = str_repeat('a', 40);
        $secondSessionId = str_repeat('b', 40);

        $first = $callback($this->challengeRequest($firstSessionId));
        $second = $callback($this->challengeRequest($secondSessionId, 'web'));

        $this->assertSame($firstSessionId, $first->key);
        $this->assertSame($secondSessionId, $second->key);
    }

    public function testApplicationCanReplaceTheDefaultTwoFactorLimiter(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);
        $rateLimiter->for('two-factor', static fn (): Limit => Limit::perMinute(9)->by('application'));

        $callback = $rateLimiter->limiter('two-factor');
        $this->assertInstanceOf(Closure::class, $callback);

        $limit = $callback($this->challengeRequest('session-a', 'web', 1));

        $this->assertSame(9, $limit->maxAttempts);
        $this->assertSame('application', $limit->key);
    }

    public function testOmittedPasskeySettingsUseTheApplicationAndPackageDefaults(): void
    {
        $appKey = config()->string('app.key');

        config(['fortify.passkeys' => []]);

        $provider = $this->app->getProvider(FortifyServiceProvider::class);
        $method = new ReflectionMethod($provider, 'configurePasskeys');

        $method->invoke($provider);

        $this->assertSame('example.test', config('passkeys.relying_party_id'));
        $this->assertSame(['https://example.test'], config('passkeys.allowed_origins'));
        $this->assertSame($appKey, config('passkeys.user_handle_secret'));
        $this->assertSame(Passkeys::DEFAULT_TIMEOUT, config('passkeys.timeout'));
    }

    public function testPasskeySettingsAreComputedAgainAgainstTheRebuiltConfiguration(): void
    {
        $masterConfig = new ConfigRepository([
            'app' => ['url' => 'https://old.example.test', 'key' => 'old-key'],
            'fortify' => ['passkeys' => ['timeout' => 30000]],
        ]);
        $tracker = new ConfigMutationTracker;
        $tracker->observe($masterConfig);
        $app = m::mock(Application::class)->makePartial();
        $app->shouldReceive('configurationIsCached')->andReturnFalse();
        $app->shouldReceive('make')->with('config')->andReturn($masterConfig);
        $app->shouldReceive('make')->with(ConfigMutationTracker::class)->andReturn($tracker);

        (new ReflectionMethod(FortifyServiceProvider::class, 'configurePasskeys'))->invoke(new FortifyServiceProvider($app));

        $this->assertSame('old.example.test', $masterConfig->get('passkeys.relying_party_id'));

        // A worker whose environment changed the application URL and key derives the defaults from them, keeping explicit settings.
        $workerConfig = new ConfigRepository([
            'app' => ['url' => 'https://new.example.test', 'key' => 'new-key'],
            'fortify' => ['passkeys' => ['relying_party_id' => 'passkeys.example.test', 'timeout' => 30000]],
        ]);
        $tracker->replay($workerConfig);

        $this->assertSame('passkeys.example.test', $workerConfig->get('passkeys.relying_party_id'));
        $this->assertSame(['https://new.example.test'], $workerConfig->get('passkeys.allowed_origins'));
        $this->assertSame('new-key', $workerConfig->get('passkeys.user_handle_secret'));
        $this->assertSame(30000, $workerConfig->get('passkeys.timeout'));
    }

    #[DefineEnvironment('withTwoFactorAuthentication')]
    public function testRedirectIfTwoFactorAuthenticatableIsResolvedFreshAfterFlushingScopedInstances(): void
    {
        $instanceA = $this->app->make(RedirectsIfTwoFactorAuthenticatable::class);
        $instanceB = $this->app->make(RedirectsIfTwoFactorAuthenticatable::class);

        $this->assertSame($instanceA, $instanceB);

        $this->app->forgetScopedInstances();

        $instanceC = $this->app->make(RedirectsIfTwoFactorAuthenticatable::class);

        $this->assertNotSame($instanceA, $instanceC);
    }

    #[DefineEnvironment('withTwoFactorAuthentication')]
    public function testRedirectIfTwoFactorAuthenticatableDoesNotCacheGuard(): void
    {
        $instance = $this->app->make(RedirectsIfTwoFactorAuthenticatable::class);
        $reflection = new ReflectionClass($instance);

        $this->assertFalse($reflection->hasProperty('guard'));
    }

    #[DefineEnvironment('withTwoFactorAuthentication')]
    public function testCustomRedirectIfTwoFactorAuthenticatableIsResolvedFreshAfterFlushingScopedInstances(): void
    {
        Fortify::redirectUserForTwoFactorAuthenticationUsing(TestRedirectIfTwoFactorAuthenticatable::class);

        $instanceA = $this->app->make(RedirectsIfTwoFactorAuthenticatable::class);

        $this->app->forgetScopedInstances();

        $instanceB = $this->app->make(RedirectsIfTwoFactorAuthenticatable::class);

        $this->assertNotSame($instanceA, $instanceB);
    }

    public function testRecoveryCodeGenerationCanBeCustomized(): void
    {
        Fortify::generateRecoveryCodesUsing(fn (): string => 'recovery-code');

        $this->assertSame('recovery-code', RecoveryCode::generate());
    }

    public function testActionsCanBeRegisteredWithNonClosureCallables(): void
    {
        $creator = m::mock(CreatesNewUsers::class);

        Fortify::createUsersUsing(new class($creator) {
            public function __construct(
                private readonly CreatesNewUsers $creator,
            ) {
            }

            public function __invoke(): CreatesNewUsers
            {
                return $this->creator;
            }
        });

        $this->assertSame($creator, $this->app->make(CreatesNewUsers::class));
    }

    /**
     * Create a two-factor challenge request with session identity state.
     */
    private function challengeRequest(
        string $sessionId,
        ?string $guard = null,
        int|string|null $id = null,
        string $ip = '192.0.2.1',
    ): Request {
        $request = Request::create('/two-factor-challenge', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $request->setHypervelSession($session = new Store(
            'fortify',
            new NullSessionHandler,
            $sessionId,
        ));

        if ($guard !== null) {
            $session->put('login.guard', $guard);
        }

        if ($id !== null) {
            $session->put('login.id', $id);
        }

        return $request;
    }
}

class TestRedirectIfTwoFactorAuthenticatable implements RedirectsIfTwoFactorAuthenticatable
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
