<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Firebase\JWT\JWT;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Http\Request;
use Hypervel\Passport\DpopProofValidator;
use Hypervel\Passport\JwkValidator;
use Hypervel\Passport\ReplayCache;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\ServerSettings;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Passport\Fixtures\JsonWebKeyPair;
use League\OAuth2\Server\Exception\OAuthServerException;
use Mockery as m;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\TestWith;

use function Hypervel\Coroutine\parallel;

class DpopProofValidatorTest extends TestCase
{
    /**
     * Enable proofs with a dedicated database replay store.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'passport' => require __DIR__ . '/../../src/passport/config/passport.php',
            'passport.dpop.enabled' => true,
            'passport.replay.store' => 'replay',
            'cache.stores.replay' => ['driver' => 'database', 'connection' => 'replay', 'table' => 'replays', 'prefix' => ''],
            'database.connections.replay' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ],
        ]);
    }

    #[TestWith(['RS256'])]
    #[TestWith(['ES256'])]
    public function testVerifiesTheEmbeddedPublicKeyAndNormalizesTheHttpTarget(string $algorithm): void
    {
        $this->freezeSecond();
        [$request, $thumbprint] = $this->proof(['htu' => 'HTTPS://AUTH.EXAMPLE:443/a/../oauth/%74oken?ignored=1#ignored'], algorithm: $algorithm);
        $replays = m::mock(ReplayCache::class);
        $replays->shouldReceive('consume')->once()->with(m::on(static fn (string $identifier): bool => json_decode($identifier, true) === [
            'dpop', $thumbprint, 'https://auth.example/oauth/token', hash('sha256', 'proof'),
        ]), Date::now()->getTimestamp() + 120)->andReturnTrue();

        $this->assertSame($thumbprint, $this->validator($replays)->validate($request));
    }

    #[TestWith([-120, false])]
    #[TestWith([-119.5, true])]
    #[TestWith([60, true])]
    #[TestWith([60.5, false])]
    public function testIssuedAtBoundariesRetainReplayEvidenceUntilTheFullDeadline(int|float $offset, bool $accepted): void
    {
        $now = $this->freezeSecond()->getTimestamp();
        [$request, $thumbprint] = $this->proof(['iat' => $now + $offset]);
        $replays = m::mock(ReplayCache::class);

        if ($accepted) {
            $replays->shouldReceive('consume')->once()->with(m::type('string'), (int) ceil($now + $offset + 120))->andReturnTrue();
        } else {
            $replays->shouldNotReceive('consume');
            $this->expectException(OAuthServerException::class);
        }

        $this->assertSame($thumbprint, $this->validator($replays)->validate($request));
    }

    #[TestWith(['htm', 'GET'])]
    #[TestWith(['htu', 'https://auth.example/oauth/token/'])]
    #[TestWith(['htu', 'https://other.example/oauth/token'])]
    #[TestWith(['htu', '/oauth/token'])]
    #[TestWith(['htu', ['https://auth.example/oauth/token']])]
    #[TestWith(['iat', '123'])]
    #[TestWith(['iat', null])]
    #[TestWith(['jti', ''])]
    #[TestWith(['jti', []])]
    public function testInvalidClaimsDoNotConsumeReplayState(string $claim, mixed $value): void
    {
        [$request] = $this->proof([$claim => $value]);
        $replays = m::mock(ReplayCache::class);
        $replays->shouldNotReceive('consume');

        $this->expectException(OAuthServerException::class);
        $this->validator($replays)->validate($request);
    }

    #[TestWith(['missing'])]
    #[TestWith(['duplicate'])]
    #[TestWith(['oversized'])]
    #[TestWith(['signature'])]
    #[TestWith(['empty-signature'])]
    #[TestWith(['short-signature'])]
    #[TestWith(['extra-byte'])]
    #[TestWith(['padded-signature'])]
    #[TestWith(['zero-r'])]
    #[TestWith(['zero-s'])]
    #[TestWith(['type'])]
    #[TestWith(['private'])]
    #[TestWith(['algorithm'])]
    public function testMalformedProofsAreRejected(string $case): void
    {
        [$request] = $this->proof(headers: match ($case) {
            'type' => ['typ' => 'JWT'],
            'private' => ['jwk' => ['kty' => 'EC', 'crv' => 'P-256', 'd' => 'private']],
            default => [],
        });
        $proof = $request->getHeaderLine('DPoP');
        $parts = explode('.', $proof);
        $signature = JWT::urlsafeB64Decode($parts[2]);
        $invalidSignature = match ($case) {
            'signature' => str_repeat("\x01", 64),
            'empty-signature' => '',
            'short-signature' => 'x',
            'extra-byte' => $signature . 'x',
            'padded-signature' => "\0" . substr($signature, 0, 32) . "\0" . substr($signature, 32),
            'zero-r' => str_repeat("\0", 32) . substr($signature, 32),
            'zero-s' => substr($signature, 0, 32) . str_repeat("\0", 32),
            default => null,
        };
        $request = match ($case) {
            'missing' => $request->withoutHeader('DPoP'),
            'duplicate' => $request->withHeader('DPoP', [$proof, $proof]),
            'oversized' => $request->withHeader('DPoP', str_repeat('x', 16385)),
            'algorithm' => $request->withHeader('DPoP', JWT::encode(
                json_decode(JWT::urlsafeB64Decode($parts[1]), true),
                str_repeat('s', 32),
                'HS256',
                head: ['typ' => 'dpop+jwt', 'jwk' => json_decode(JWT::urlsafeB64Decode($parts[0]), true)['jwk']],
            )),
            default => $invalidSignature === null ? $request
                : $request->withHeader('DPoP', $parts[0] . '.' . $parts[1] . '.' . JWT::urlsafeB64Encode($invalidSignature)),
        };
        $replays = m::mock(ReplayCache::class);
        $replays->shouldNotReceive('consume');

        $this->expectException(OAuthServerException::class);
        $this->validator($replays)->validate($request);
    }

    #[TestWith([513, 3, 'RSA keys must be between 2048 and 4096 bits.'])]
    #[TestWith([256, 5, 'RSA public exponents must not exceed 32 bits.'])]
    public function testUnsupportedKeysHavePublicErrorDescriptions(int $modulusBytes, int $exponentBytes, string $description): void
    {
        [$request] = $this->proof(headers: ['jwk' => [
            'kty' => 'RSA',
            'n' => JWT::urlsafeB64Encode("\x80" . random_bytes($modulusBytes - 2) . "\x01"),
            'e' => JWT::urlsafeB64Encode("\x01" . str_repeat("\0", $exponentBytes - 2) . "\x01"),
        ]], algorithm: 'RS256');
        $replays = m::mock(ReplayCache::class);
        $replays->shouldNotReceive('consume');

        try {
            $this->validator($replays)->validate($request);
            $this->fail('Unsupported keys must be rejected before consuming the proof.');
        } catch (OAuthServerException $exception) {
            $this->assertSame('invalid_dpop_proof', $exception->getErrorType());
            $this->assertSame($description, $exception->getPayload()['hint']);
        }
    }

    #[TestWith([true, true])]
    #[TestWith([false, true])]
    #[TestWith([true, false])]
    public function testResourceProofsMustBindBothTheAccessTokenAndItsPublicKey(bool $matchingToken, bool $matchingKey): void
    {
        [$request, $thumbprint] = $this->proof(['ath' => JWT::urlsafeB64Encode(hash('sha256', 'access-token', true))]);
        $replays = m::mock(ReplayCache::class);

        if ($matchingToken && $matchingKey) {
            $replays->shouldReceive('consume')->once()->andReturnTrue();
        } else {
            $replays->shouldNotReceive('consume');
            $this->expectException(OAuthServerException::class);
        }

        $this->assertSame($thumbprint, $this->validator($replays)->validate(
            $request,
            $matchingToken ? 'access-token' : 'another-token',
            $matchingKey ? $thumbprint : 'another-key',
        ));
    }

    #[TestWith([true, null, true])]
    #[TestWith([false, null, false])]
    #[TestWith([false, true, true])]
    #[TestWith([true, false, false])]
    #[TestWith([true, null, true, 'GET'])]
    public function testReplayPolicyUsesTheServerOverrideAndSkipsTheStoreWhenDisabled(bool $configured, ?bool $override, bool $protected, string $method = 'POST'): void
    {
        $now = $this->freezeSecond()->getTimestamp();
        config(['passport.dpop.replay_protection' => $configured]);

        if ($protected) {
            DB::connection('replay')->getSchemaBuilder()->create('replays', static function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->text('value');
                $table->integer('expiration');
            });
        } else {
            // This store is deliberately unsupported by ReplayCache; disabled tracking must never resolve it.
            config(['passport.replay.store' => 'array']);
        }

        $validator = $this->validator($this->app->make(ReplayCache::class), new ServerSettings([], dpopReplayProtection: $override));
        [$request, $thumbprint] = $this->proof(algorithm: 'RS256');
        $this->assertSame($thumbprint, $validator->validate($request));

        if ($method !== 'POST') {
            [$request] = $this->proof(['htm' => $method], algorithm: 'RS256');
            $request = $request->withMethod($method);
        }

        try {
            $this->assertSame($thumbprint, $validator->validate($request));
            $this->assertFalse($protected);
        } catch (OAuthServerException $exception) {
            $this->assertTrue($protected);
            $this->assertSame('invalid_dpop_proof', $exception->getErrorType());
            $this->assertSame($now + 120, DB::connection('replay')->table('replays')->sole()->expiration);
        }

        [$expired] = $this->proof(['iat' => $now - 120]);
        $this->expectException(OAuthServerException::class);
        $validator->validate($expired);
    }

    public function testSuccessfulProofReuseIsLimitedToTheSameRequestAndInputs(): void
    {
        [$request, $thumbprint] = $this->proof();
        RequestContext::set(Request::create('https://auth.example/oauth/token', 'POST'));
        $replays = m::mock(ReplayCache::class);
        $replays->shouldReceive('consume')->twice()->andReturn(true, false);
        $validator = $this->validator($replays);
        $this->assertSame($thumbprint, $validator->validate($request));
        $this->assertSame($thumbprint, $validator->validate($request));

        try {
            $validator->validate($request->withMethod('GET'));
            $this->fail('Changing the HTTP method must invalidate the proof.');
        } catch (OAuthServerException $exception) {
            $this->assertSame('invalid_dpop_proof', $exception->getErrorType());
        }

        RequestContext::set(Request::create('https://auth.example/oauth/token', 'POST'));
        $this->expectException(OAuthServerException::class);
        $validator->validate($request);
    }

    public function testWeakerValidationCannotSatisfyAServerRequiringReplayProtection(): void
    {
        [$request, $thumbprint] = $this->proof();
        RequestContext::set(Request::create('https://auth.example/oauth/token', 'POST'));
        $servers = m::mock(ServerFactory::class);
        $servers->shouldReceive('settings')->andReturn(
            new ServerSettings([], dpopReplayProtection: false),
            new ServerSettings([], dpopReplayProtection: true),
        );
        $replays = m::mock(ReplayCache::class);
        $replays->shouldReceive('consume')->once()->andReturnFalse();
        $validator = new DpopProofValidator($this->app->make(Repository::class), new JwkValidator, $replays, $servers);
        $this->assertSame($thumbprint, $validator->validate($request));

        $this->expectException(OAuthServerException::class);
        $validator->validate($request);
    }

    public function testProofMemosRemainIndependentAcrossConcurrentRequests(): void
    {
        [$first, $firstThumbprint] = $this->proof();
        [$second, $secondThumbprint] = $this->proof();
        $replays = m::mock(ReplayCache::class);
        $replays->shouldReceive('consume')->twice()->andReturnTrue();
        $validator = $this->validator($replays);
        $validate = static function (ServerRequest $request) use ($validator): string {
            RequestContext::set(Request::create('https://auth.example/oauth/token', 'POST'));
            $validator->validate($request);
            usleep(5000);

            return $validator->validate($request);
        };

        $this->assertSame([$firstThumbprint, $secondThumbprint], parallel([
            static fn (): string => $validate($first),
            static fn (): string => $validate($second),
        ]));
    }

    public function testDisabledSupportRejectsProofsWithoutAccessingReplayStorage(): void
    {
        config(['passport.dpop.enabled' => false]);
        [$request] = $this->proof();
        $replays = m::mock(ReplayCache::class);
        $replays->shouldNotReceive('consume');

        $this->expectException(OAuthServerException::class);
        $this->expectExceptionMessage('DPoP proof is invalid');
        $this->validator($replays)->validate($request);
    }

    /**
     * Build a validator with real cryptography and selected server policy.
     */
    protected function validator(ReplayCache $replays, ?ServerSettings $settings = null): DpopProofValidator
    {
        $servers = m::mock(ServerFactory::class);
        $servers->shouldReceive('settings')->andReturn($settings ?? new ServerSettings([]));

        return new DpopProofValidator($this->app->make(Repository::class), new JwkValidator, $replays, $servers);
    }

    /**
     * Sign a proof and return its request and expected RFC 7638 thumbprint.
     */
    protected function proof(array $claims = [], array $headers = [], string $algorithm = 'ES256'): array
    {
        $pair = $algorithm === 'RS256'
            ? JsonWebKeyPair::sharedRsa()
            : JsonWebKeyPair::generate();
        $proof = JWT::encode([
            'htm' => 'POST', 'htu' => 'https://auth.example/oauth/token', 'iat' => Date::now()->getTimestamp(), 'jti' => 'proof', ...$claims,
        ], $pair->privateKey, $algorithm, head: ['typ' => 'dpop+jwt', 'jwk' => $pair->jwk + ['kid' => 'ignored', 'use' => 'sig'], ...$headers]);

        return [(new ServerRequest('POST', 'https://auth.example/oauth/token?ignored=2'))->withHeader('DPoP', $proof), $pair->thumbprint()];
    }
}
