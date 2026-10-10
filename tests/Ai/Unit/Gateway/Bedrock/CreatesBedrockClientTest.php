<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Bedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Credentials\CredentialsInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sts\StsClient;
use Closure;
use DateTimeImmutable;
use Hypervel\Ai\Gateway\Bedrock\Concerns\CreatesBedrockClient;
use Hypervel\Ai\Providers\BedrockProvider;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Tests\Ai\TestCase;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Coroutine\parallel;

function bedrockClientTrait(?MockHandler $handler = null, ?Closure $httpHandler = null): object
{
    return new class($handler, $httpHandler) {
        use CreatesBedrockClient;

        /** @var array<string, mixed> */
        public array $stsSourceConfig = [];

        /** @var list<null|int> */
        public array $stsTimeouts = [];

        /**
         * Create a gateway with an isolated STS transport.
         */
        public function __construct(protected ?MockHandler $handler = null, protected ?Closure $httpHandler = null)
        {
        }

        /**
         * Resolve the authentication configuration.
         */
        public function resolve(array $credentials, array $config): array
        {
            return $this->resolveAuthConfig($credentials, $config);
        }

        /**
         * Create the provider's SDK client.
         */
        public function create(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
        {
            return $this->createBedrockClient($provider, $timeout);
        }

        /**
         * Capture the source configuration and use the test's STS transport.
         */
        protected function createStsClient(array $credentials, array $config, ?int $timeout = null): StsClient
        {
            $this->stsSourceConfig = $this->resolveSourceAuthConfig($credentials, $config);
            $this->stsTimeouts[] = $timeout;

            return new StsClient([
                'region' => $config['region'] ?? 'us-east-1',
                'version' => 'latest',
                'credentials' => ['key' => 'source-key', 'secret' => 'source-secret'],
                'handler' => $this->handler,
                ...($this->httpHandler === null ? [] : ['http_handler' => $this->httpHandler]),
            ]);
        }
    };
}

function stsAssumeRoleResult(): Result
{
    return new Result([
        'Credentials' => [
            'AccessKeyId' => 'ASIA-ASSUMED',
            'SecretAccessKey' => 'assumed-secret',
            'SessionToken' => 'assumed-token',
            'Expiration' => new DateTimeImmutable('+1 hour'),
        ],
    ]);
}

function assumeRoleConfig(array $assumeRole = [], array $config = []): array
{
    return [
        'region' => 'us-west-2',
        'assume_role' => [...['arn' => 'arn:aws:iam::123456789012:role/test-role'], ...$assumeRole],
        ...$config,
    ];
}

class CreatesBedrockClientTest extends TestCase
{
    public function testBearerTokenCredentialsUseHttpBearerAuthScheme(): void
    {
        $config = bedrockClientTrait()->resolve(
            ['key' => 'bedrock-token'],
            [],
        );

        $this->assertSame([
            'token' => ['token' => 'bedrock-token'],
            'auth_scheme_preference' => ['smithy.api#httpBearerAuth'],
        ], $config);
    }

    public function testConfiguredHeadersAreAddedBeforeRequestSigning(): void
    {
        $provider = new BedrockProvider([
            'name' => 'bedrock',
            'access_key_id' => 'test-key',
            'secret_access_key' => 'test-secret',
            'region' => 'us-east-1',
            'headers' => ['X-Session-Affinity' => 'abc-123'],
        ], new Dispatcher);

        $client = bedrockClientTrait()->create($provider);
        $handler = new MockHandler([new Result]);
        $client->getHandlerList()->setHandler($handler);

        $client->invokeModel([
            'modelId' => 'amazon.titan-embed-text-v2:0',
            'body' => '{}',
            'contentType' => 'application/json',
        ]);

        $request = $handler->getLastRequest();

        $this->assertSame('abc-123', $request->getHeaderLine('X-Session-Affinity'));
        $this->assertStringContainsString('x-session-affinity', $request->getHeaderLine('Authorization'));
    }

    public function testAssumedCredentialsSignRequestsThroughTheConfiguredHttpConnections(): void
    {
        Http::registerConnection('ai-providers', ['headers' => ['X-Shared' => 'sts']]);
        Http::registerConnection('ai-providers.bedrock', ['headers' => ['X-Provider' => 'bedrock']]);
        $expiration = (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM);
        Http::fake([
            'https://sts.us-west-2.amazonaws.com/' => Http::response(
                '<AssumeRoleResponse xmlns="https://sts.amazonaws.com/doc/2011-06-15/">'
                . '<AssumeRoleResult><Credentials><AccessKeyId>ASIA-ASSUMED</AccessKeyId>'
                . '<SecretAccessKey>assumed-secret</SecretAccessKey><SessionToken>assumed-token</SessionToken>'
                . '<Expiration>' . $expiration . '</Expiration></Credentials></AssumeRoleResult></AssumeRoleResponse>',
                headers: ['Content-Type' => 'text/xml'],
            ),
            'https://bedrock-runtime.us-west-2.amazonaws.com/*' => Http::response('result'),
        ]);
        $gateway = new class {
            use CreatesBedrockClient;

            /**
             * Create the provider's SDK client with its real HTTP handler.
             */
            public function create(Provider $provider): BedrockRuntimeClient
            {
                return $this->createBedrockClient($provider);
            }
        };
        $provider = new BedrockProvider([
            'name' => 'bedrock',
            'access_key_id' => 'source-key',
            'secret_access_key' => 'source-secret',
            ...assumeRoleConfig(),
        ], new Dispatcher);

        $result = $gateway->create($provider)->invokeModel(['modelId' => 'test-model', 'body' => '{}']);

        $this->assertSame('result', (string) $result['body']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sts.us-west-2.amazonaws.com/'
            && $request->hasHeader('X-Shared', 'sts')
            && ! $request->hasHeader('X-Provider'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'bedrock-runtime.us-west-2.amazonaws.com')
            && $request->hasHeader('X-Provider', 'bedrock')
            && ! $request->hasHeader('X-Shared')
            && str_contains($request->toPsrRequest()->getHeaderLine('Authorization'), 'Credential=ASIA-ASSUMED/')
            && $request->hasHeader('X-Amz-Security-Token', 'assumed-token'));
    }

    public function testBearerTokenTakesPriorityOverIamCredentials(): void
    {
        $config = bedrockClientTrait()->resolve(
            [
                'key' => 'bedrock-token',
                'access_key_id' => 'ignored-key',
                'secret_access_key' => 'ignored-secret',
            ],
            [],
        );

        $this->assertArrayHasKey('token', $config);
        $this->assertArrayNotHasKey('credentials', $config);
    }

    public function testIamCredentialsProduceKeyAndSecret(): void
    {
        $config = bedrockClientTrait()->resolve(
            [
                'access_key_id' => 'AKIA123',
                'secret_access_key' => 'secret',
            ],
            [],
        );

        $this->assertSame([
            'credentials' => [
                'key' => 'AKIA123',
                'secret' => 'secret',
            ],
        ], $config);
    }

    public function testSessionTokenIsIncludedWhenProvidedWithIamCredentials(): void
    {
        $config = bedrockClientTrait()->resolve(
            [
                'access_key_id' => 'AKIA123',
                'secret_access_key' => 'secret',
                'session_token' => 'session',
            ],
            [],
        );

        $this->assertSame([
            'key' => 'AKIA123',
            'secret' => 'secret',
            'token' => 'session',
        ], $config['credentials']);
    }

    public function testEmptyCredentialsWithDefaultProviderEnabledReturnsEmptyConfig(): void
    {
        $config = bedrockClientTrait()->resolve([], []);

        $this->assertSame([], $config);
    }

    public function testEmptyCredentialsWithDefaultProviderDisabledReturnsFalseCredentials(): void
    {
        $config = bedrockClientTrait()->resolve(
            [],
            ['use_default_credential_provider' => false],
        );

        $this->assertSame(['credentials' => false], $config);
    }

    public function testPartialIamCredentialsFallBackToDefaultProvider(): void
    {
        $config = bedrockClientTrait()->resolve(
            ['access_key_id' => 'AKIA123'],
            [],
        );

        $this->assertSame([], $config);
    }

    public function testAssumeRoleProducesACallableCredentialProvider(): void
    {
        $config = bedrockClientTrait()->resolve([], assumeRoleConfig());

        $this->assertArrayHasKey('credentials', $config);
        $this->assertIsCallable($config['credentials']);
    }

    public function testAssumeRoleSendsTheConfiguredParametersToSts(): void
    {
        $handler = new MockHandler([stsAssumeRoleResult()]);

        $config = bedrockClientTrait($handler)->resolve([], assumeRoleConfig([
            'session_name' => 'my-session',
            'duration_seconds' => 900,
            'external_id' => 'ext-123',
        ]));

        $credentials = ($config['credentials'])()->wait();

        $this->assertSame('AssumeRole', $handler->getLastCommand()->getName());
        $this->assertSame([
            'RoleArn' => 'arn:aws:iam::123456789012:role/test-role',
            'RoleSessionName' => 'my-session',
            'DurationSeconds' => 900,
            'ExternalId' => 'ext-123',
        ], array_intersect_key($handler->getLastCommand()->toArray(), array_flip(['RoleArn', 'RoleSessionName', 'DurationSeconds', 'ExternalId'])));
        $this->assertSame('ASIA-ASSUMED', $credentials->getAccessKeyId());
        $this->assertSame('assumed-token', $credentials->getSecurityToken());
    }

    public function testAssumeRoleOmitsDurationSecondsWhenNotConfigured(): void
    {
        $handler = new MockHandler([stsAssumeRoleResult()]);

        $config = bedrockClientTrait($handler)->resolve([], assumeRoleConfig());

        ($config['credentials'])()->wait();

        $this->assertArrayNotHasKey('DurationSeconds', $handler->getLastCommand()->toArray());
    }

    public function testAssumeRoleOmitsExternalIdWhenNotConfigured(): void
    {
        $handler = new MockHandler([stsAssumeRoleResult()]);

        $config = bedrockClientTrait($handler)->resolve([], assumeRoleConfig());

        ($config['credentials'])()->wait();

        $this->assertArrayNotHasKey('ExternalId', $handler->getLastCommand()->toArray());
    }

    public function testAssumeRoleUsesAStableDefaultSessionName(): void
    {
        $handler = new MockHandler([stsAssumeRoleResult(), stsAssumeRoleResult()]);

        $first = bedrockClientTrait($handler)->resolve([], assumeRoleConfig());
        $second = bedrockClientTrait($handler)->resolve([], assumeRoleConfig());

        ($first['credentials'])()->wait();
        $firstSession = $handler->getLastCommand()->toArray()['RoleSessionName'];

        ($second['credentials'])()->wait();
        $secondSession = $handler->getLastCommand()->toArray()['RoleSessionName'];

        $this->assertSame('hypervel-ai-bedrock', $firstSession);
        $this->assertSame($firstSession, $secondSession);
    }

    public function testAssumeRoleCredentialsAreMemoizedAcrossClientCreations(): void
    {
        $handler = new MockHandler([static function (): Result {
            usleep(1000);

            return stsAssumeRoleResult();
        }]);
        $provider = new BedrockProvider(['name' => 'bedrock', ...assumeRoleConfig()], new Dispatcher);
        $first = bedrockClientTrait($handler)->create($provider);
        $second = bedrockClientTrait($handler)->create($provider->withHeaders(['X-Trace' => 'second']));
        [$firstCredentials, $secondCredentials] = parallel([
            fn (): CredentialsInterface => $first->getCredentials()->wait(),
            fn (): CredentialsInterface => $second->getCredentials()->wait(),
        ]);

        $this->assertCount(0, $handler);
        $this->assertSame($firstCredentials, $secondCredentials);
        $this->assertSame($firstCredentials, $first->getCredentials()->wait());
    }

    #[WithEnv('AWS_ACCESS_KEY_ID', 'environment-key')]
    #[WithEnv('AWS_SECRET_ACCESS_KEY', 'environment-secret')]
    public function testDefaultChainCredentialsAreReusedAcrossClients(): void
    {
        $provider = new BedrockProvider(['name' => 'bedrock', 'region' => 'us-west-2'], new Dispatcher);
        $first = bedrockClientTrait()->create($provider)->getCredentials()->wait();
        $second = bedrockClientTrait()->create($provider)->getCredentials()->wait();

        $this->assertSame('environment-key', $first->getAccessKeyId());
        $this->assertSame($first, $second);
    }

    public function testAssumeRoleCredentialsAreOwnedByEachProvider(): void
    {
        $handler = new MockHandler([stsAssumeRoleResult(), stsAssumeRoleResult()]);
        $trait = bedrockClientTrait($handler);
        $west = new BedrockProvider(['name' => 'bedrock', ...assumeRoleConfig()], new Dispatcher);
        $east = new BedrockProvider(['name' => 'bedrock', ...assumeRoleConfig(config: ['region' => 'us-east-1'])], new Dispatcher);

        $westCredentials = $trait->create($west)->getCredentials()->wait();
        $eastCredentials = $trait->create($east)->getCredentials()->wait();

        $this->assertCount(0, $handler);
        $this->assertNotSame($westCredentials, $eastCredentials);
    }

    public function testAssumeRoleRefreshUsesTheCurrentOperationTimeout(): void
    {
        $expiring = stsAssumeRoleResult();
        $credentials = $expiring['Credentials'];
        $credentials['Expiration'] = new DateTimeImmutable('+30 seconds');
        $expiring['Credentials'] = $credentials;
        $trait = bedrockClientTrait(new MockHandler([$expiring, stsAssumeRoleResult()]));
        $provider = new BedrockProvider(['name' => 'bedrock', ...assumeRoleConfig()], new Dispatcher);

        $first = $trait->create($provider, 3)->getCredentials()->wait();
        $second = $trait->create($provider, 17)->getCredentials()->wait();

        $this->assertNotSame($first, $second);
        $this->assertSame([3, 17], $trait->stsTimeouts);
    }

    public function testAssumeRoleIsNotUsedWhenArnIsEmpty(): void
    {
        $config = bedrockClientTrait()->resolve([], ['assume_role' => ['arn' => null]]);

        $this->assertSame([], $config);
    }

    public function testAssumeRoleIsNotUsedWhenArnIsAnEmptyString(): void
    {
        $config = bedrockClientTrait()->resolve([], ['assume_role' => ['arn' => '']]);

        $this->assertSame([], $config);
    }

    public function testAssumeRoleIsNotUsedWhenNotConfiguredAtAll(): void
    {
        $config = bedrockClientTrait()->resolve([], ['region' => 'us-west-2']);

        $this->assertSame([], $config);
    }

    public function testBearerTokenTakesPriorityOverAssumeRole(): void
    {
        $config = bedrockClientTrait()->resolve(['key' => 'bedrock-token'], assumeRoleConfig());

        $this->assertArrayHasKey('token', $config);
        $this->assertArrayNotHasKey('credentials', $config);
    }

    public function testAssumeRoleTakesPriorityOverStaticIamCredentials(): void
    {
        $config = bedrockClientTrait()->resolve(
            ['access_key_id' => 'AKIA123', 'secret_access_key' => 'secret'],
            assumeRoleConfig(),
        );

        $this->assertIsCallable($config['credentials']);
    }

    public function testStaticIamCredentialsAreUsedAsTheAssumeRoleSource(): void
    {
        $trait = bedrockClientTrait(new MockHandler([stsAssumeRoleResult()]));

        $config = $trait->resolve(
            ['access_key_id' => 'AKIA123', 'secret_access_key' => 'secret', 'session_token' => 'session'],
            assumeRoleConfig(),
        );

        ($config['credentials'])()->wait();

        $this->assertSame([
            'credentials' => ['key' => 'AKIA123', 'secret' => 'secret', 'token' => 'session'],
        ], $trait->stsSourceConfig);
    }

    public function testAssumeRoleSourceUsesTheDefaultCredentialChainWhenNoStaticCredentialsExist(): void
    {
        $trait = bedrockClientTrait(new MockHandler([stsAssumeRoleResult()]));

        $config = $trait->resolve([], assumeRoleConfig());
        ($config['credentials'])()->wait();

        $this->assertSame([], $trait->stsSourceConfig);
    }

    public function testAssumeRoleSourceHonorsADisabledDefaultCredentialProvider(): void
    {
        $trait = bedrockClientTrait(new MockHandler([stsAssumeRoleResult()]));

        $config = $trait->resolve([], assumeRoleConfig(config: ['use_default_credential_provider' => false]));
        ($config['credentials'])()->wait();

        $this->assertSame(['credentials' => false], $trait->stsSourceConfig);
    }

    public function testAssumeRoleRefreshPreservesCancellation(): void
    {
        // @TODO Unskip once the AWS SDK minimum includes the fix for https://github.com/aws/aws-sdk-php/issues/3370.
        $this->markTestSkipped('The AWS SDK replaces cancellation with a TypeError during assume-role refresh.');

        $cancellation = new CanceledException('The client disconnected during credential refresh.');
        $trait = bedrockClientTrait(httpHandler: static fn (): never => throw $cancellation);
        $config = $trait->resolve([], assumeRoleConfig());
        $caught = null;

        try {
            ($config['credentials'])()->wait();
        } catch (CanceledException $exception) {
            $caught = $exception;
        }

        $this->assertSame($cancellation, $caught);
    }
}
