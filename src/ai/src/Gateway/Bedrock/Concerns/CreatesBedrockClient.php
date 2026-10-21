<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Bedrock\Concerns;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Credentials\AssumeRoleCredentialProvider;
use Aws\Credentials\CredentialProvider;
use Aws\Credentials\CredentialsInterface;
use Aws\Middleware;
use Aws\Sts\StsClient;
use Closure;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Gateway\Bedrock\BedrockCredentials;
use Hypervel\Ai\Gateway\Bedrock\BedrockHttpHandler;
use Hypervel\Ai\Gateway\Concerns\CreatesClient;
use Hypervel\Ai\Providers\BedrockProvider;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

trait CreatesBedrockClient
{
    use CreatesClient;

    // REMOVED: $assumeRoleProviders; the provider's BedrockCredentials holder reuses completed credentials.

    /**
     * Create a new Bedrock client instance.
     */
    protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
    {
        if (! class_exists(BedrockRuntimeClient::class)) {
            throw new RuntimeException('The Bedrock provider requires the AWS SDK. Please install it via: composer require aws/aws-sdk-php');
        }

        $credentials = $provider->providerCredentials();

        $config = $provider->additionalConfiguration();
        $auth = $this->resolveAuthConfig($credentials, $config, $timeout);
        $region = $this->bedrockRegion($config);

        // Share completed credentials rather than SDK promises that concurrent coroutines cannot safely drive.
        if (! isset($auth['token']) && (! array_key_exists('credentials', $auth) || is_callable($auth['credentials']))) {
            $holder = $provider instanceof BedrockProvider ? $provider->bedrockCredentials() : new BedrockCredentials;
            $resolve = $auth['credentials'] ?? static fn (): PromiseInterface => CredentialProvider::defaultProvider(['region' => $region])();
            $auth['credentials'] = static fn (): PromiseInterface => new FulfilledPromise($holder->get(
                static fn (): CredentialsInterface => $resolve()->wait(),
                $timeout,
            ));
        }

        $clientConfig = [
            'region' => $region,
            'version' => '2023-09-30',
            'http_handler' => new BedrockHttpHandler(Http::getFacadeRoot(), $this->httpConnection($provider)),
            ...$auth,
        ];

        if ($timeout) {
            $clientConfig['http'] = ['timeout' => $timeout];
        }

        $client = new BedrockRuntimeClient($clientConfig);

        if ($headers = $config['headers'] ?? []) {
            $client->getHandlerList()->appendBuild(Middleware::mapRequest(
                function (RequestInterface $request) use ($headers): RequestInterface {
                    foreach ($headers as $name => $value) {
                        $request = $request->withHeader($name, $value);
                    }

                    return $request;
                }
            ), 'hypervel-ai.headers');
        }

        return $client;
    }

    /**
     * Resolve the configured region, falling back to the default.
     *
     * @param array<string, mixed> $config
     */
    protected function bedrockRegion(array $config): string
    {
        return $config['region'] ?? 'us-east-1';
    }

    /**
     * Resolve the provider authentication configuration.
     *
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function resolveAuthConfig(array $credentials, array $config, ?int $timeout = null): array
    {
        if (! empty($credentials['key'])) {
            return [
                'token' => ['token' => $credentials['key']],
                'auth_scheme_preference' => ['smithy.api#httpBearerAuth'],
            ];
        }

        if (! empty($config['assume_role']['arn'])) {
            return ['credentials' => $this->assumeRoleCredentialProvider($credentials, $config, $timeout)];
        }

        return $this->resolveSourceAuthConfig($credentials, $config);
    }

    /**
     * Resolve the auth configuration for static, or automatically discovered, credentials.
     *
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function resolveSourceAuthConfig(array $credentials, array $config): array
    {
        if (! empty($credentials['access_key_id']) && ! empty($credentials['secret_access_key'])) {
            $awsCredentials = [
                'key' => $credentials['access_key_id'],
                'secret' => $credentials['secret_access_key'],
            ];

            if (! empty($credentials['session_token'])) {
                $awsCredentials['token'] = $credentials['session_token'];
            }

            return ['credentials' => $awsCredentials];
        }

        if (! ($config['use_default_credential_provider'] ?? true)) {
            // Disabling the default chain without static credentials leaves an assume-role source empty; STS will reject it.
            return ['credentials' => false];
        }

        return [];
    }

    /**
     * Create a loader that assumes the configured role.
     *
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $config
     */
    protected function assumeRoleCredentialProvider(array $credentials, array $config, ?int $timeout = null): Closure
    {
        return fn (): PromiseInterface => (new AssumeRoleCredentialProvider([
            'client' => $this->createStsClient($credentials, $config, $timeout),
            'assume_role_params' => $this->assumeRoleParameters($config['assume_role']),
        ]))();
    }

    /**
     * Create the STS client used to assume the configured role.
     *
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $config
     */
    protected function createStsClient(array $credentials, array $config, ?int $timeout = null): StsClient
    {
        $clientConfig = [
            'region' => $this->bedrockRegion($config),
            'version' => 'latest',
            'http_handler' => new BedrockHttpHandler(Http::getFacadeRoot()),
            ...$this->resolveSourceAuthConfig($credentials, $config),
        ];

        if ($timeout) {
            $clientConfig['http'] = ['timeout' => $timeout];
        }

        return new StsClient($clientConfig);
    }

    /**
     * Build the parameters for the STS assume role request.
     *
     * @param array<string, mixed> $assumeRole
     * @return array<string, mixed>
     */
    protected function assumeRoleParameters(array $assumeRole): array
    {
        return array_filter([
            'RoleArn' => $assumeRole['arn'],
            'RoleSessionName' => ($assumeRole['session_name'] ?? null) ?: 'hypervel-ai-bedrock',
            'DurationSeconds' => (int) ($assumeRole['duration_seconds'] ?? 0),
            'ExternalId' => $assumeRole['external_id'] ?? null,
        ]);
    }
}
