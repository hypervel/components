<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use DateInterval;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Request;
use Hypervel\Passport\Client;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\Passport;
use Hypervel\Passport\PassportServiceProvider;
use Hypervel\Passport\RefreshToken;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\Token;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Passport\Fixtures\User;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use UnitEnum;

class PassportServiceProviderTest extends PassportTestCase
{
    public function testCanUseCryptoKeysFromConfig(): void
    {
        $keys = (new SigningKeyFactory)->definition();
        config([
            'passport.private_key' => $keys['private_key'],
            'passport.public_key' => $keys['public_key'],
        ]);
        (new Filesystem)->delete([
            $this->keysDirectory . '/oauth-private.key',
            $this->keysDirectory . '/oauth-public.key',
        ]);

        $cryptKey = $this->app->make(ServerFactory::class)->signingKey(new DateInterval('PT1H'));

        $this->assertSame(
            $keys['private_key'],
            $cryptKey->getKeyContents()
        );
    }

    public function testCanUseCryptoKeysFromLocalDisk(): void
    {
        config(['passport.private_key' => null]);

        $cryptKey = $this->app->make(ServerFactory::class)->signingKey(new DateInterval('PT1H'));

        $this->assertSame(
            file_get_contents($this->keysDirectory . '/oauth-private.key'),
            $cryptKey->getKeyContents()
        );
    }

    public function testFilesDoNotScheduleRotation(): void
    {
        $this->assertSame([], array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn ($event): bool => str_contains($event->command ?? '', 'passport:keys:rotate'),
        ));
    }

    #[WithConfig('passport.key_store', 'database')]
    #[WithConfig('passport.key_rotation', ['enabled' => false, 'days' => 90])]
    public function testDisabledDatabaseRotationDoesNotRegisterAScheduledCommand(): void
    {
        $this->assertSame([], array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn ($event): bool => str_contains($event->command ?? '', 'passport:keys:rotate'),
        ));
    }

    public function testDefaultLimiterUsesTheUserOrRequestIp(): void
    {
        $limiter = $this->app->make(RateLimiter::class)->limiter('passport');
        $request = Request::create('/oauth/token', server: ['REMOTE_ADDR' => '192.0.2.1']);
        $anonymous = $limiter($request);
        $this->assertSame(60, $anonymous->maxAttempts);
        $this->assertSame(60, $anonymous->decaySeconds);
        $this->assertSame('192.0.2.1', $anonymous->key);

        $request->setUserResolver(static fn (): User => (new User)->forceFill(['id' => 1]));
        $this->assertSame('1', $limiter($request)->key);
    }

    #[DefineEnvironment('configureApplicationLimiter')]
    public function testAnApplicationLimiterIsNotReplacedByTheDefault(): void
    {
        $limiter = $this->app->make(RateLimiter::class)->limiter('passport');
        $limit = $limiter(Request::create('/oauth/token'));
        $this->assertSame(12, $limit->maxAttempts);
        $this->assertSame('application', $limit->key);
    }

    /**
     * Register the application policy before Passport's booted callback.
     */
    protected function configureApplicationLimiter(Application $app): void
    {
        $app->booting(static function () use ($app): void {
            $app->make(RateLimiter::class)->for('passport', static fn (): Limit => Limit::perMinute(12)->by('application'));
        });
    }

    #[DataProvider('invalidConfiguration')]
    public function testInvalidSettingsAreRejectedAtStartup(array $settings, string $message): void
    {
        config($settings);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new PassportConfigurationProvider($this->app))->validateConfiguration();
    }

    /**
     * Provide invalid issuer, cache and key-publication settings.
     */
    public static function invalidConfiguration(): iterable
    {
        yield 'insecure issuer' => [
            ['passport.issuer' => 'http://passport-app.test'], 'Passport issuers must be absolute HTTPS URLs',
        ];
        yield 'unbounded settings cache' => [
            ['passport.server_cache_size' => 0], 'Passport server cache size must be a positive integer.',
        ];
        yield 'disabled client key retention' => [
            ['passport.client_keys.ttl' => 0], 'Passport client key cache TTL and refresh interval must be positive integers.',
        ];
        yield 'negative client key refresh interval' => [
            ['passport.client_keys.refresh_interval' => -1], 'Passport client key cache TTL and refresh interval must be positive integers.',
        ];
        yield 'anonymous wildcard scope' => [
            ['passport.registration.scopes' => ['*']], 'Passport anonymous registration scopes cannot include wildcard or provisioning scopes.',
        ];
        yield 'anonymous provisioning scope' => [
            ['passport.registration.scopes' => ['passport:register']], 'Passport anonymous registration scopes cannot include wildcard or provisioning scopes.',
        ];
        yield 'activation before refresh' => [
            ['passport.key_store' => 'database', 'passport.key_activation_delay' => 60],
            'Passport key activation delay must exceed a positive key refresh interval.',
        ];
        yield 'continuous key refresh' => [
            ['passport.key_store' => 'database', 'passport.key_refresh_interval' => 0],
            'Passport key activation delay must exceed a positive key refresh interval.',
        ];
        yield 'invalid rotation period' => [
            ['passport.key_store' => 'database', 'passport.key_rotation.days' => 0],
            'Passport key rotation period must be a positive number of days.',
        ];
        yield 'unbounded credential cache' => [
            ['passport.cache.enabled' => true, 'passport.cache.ttl' => 0],
            'Passport cache TTL must be a positive integer.',
        ];
        yield 'process-local credentials' => [
            ['passport.cache.enabled' => true, 'passport.cache.store' => 'array'],
            'Passport token and client cache',
        ];
    }

    public function testCredentialConnectionsNormalizeTheDefaultAndExcludeTheClientConnection(): void
    {
        Passport::useTokenModel(PassportExplicitDefaultToken::class);
        Passport::useClientModel(PassportSeparateClient::class);
        $provider = new PassportConfigurationProvider($this->app);
        $provider->validateConfiguration();

        Passport::useRefreshTokenModel(PassportSeparateRefreshToken::class);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Passport credential and database signing-key models must share one database connection.');
        $provider->validateConfiguration();
    }
}

class PassportConfigurationProvider extends PassportServiceProvider
{
    /**
     * Expose startup validation without rebooting the test application.
     */
    public function validateConfiguration(): void
    {
        parent::validateConfiguration();
    }
}

class PassportExplicitDefaultToken extends Token
{
    protected UnitEnum|string|null $connection = 'testing';
}

class PassportSeparateClient extends Client
{
    protected UnitEnum|string|null $connection = 'other';
}

class PassportSeparateRefreshToken extends RefreshToken
{
    protected UnitEnum|string|null $connection = 'other';
}
