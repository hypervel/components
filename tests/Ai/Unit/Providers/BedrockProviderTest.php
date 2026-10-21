<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Providers;

use Hypervel\Ai\Gateway\Bedrock\BedrockImageGateway;
use Hypervel\Ai\Gateway\Bedrock\BedrockRerankingGateway;
use Hypervel\Ai\Gateway\Bedrock\BedrockTextGateway;
use Hypervel\Ai\Providers\BedrockProvider;
use Hypervel\Events\Dispatcher;
use Hypervel\Tests\TestCase;

class BedrockProviderTest extends TestCase
{
    protected Dispatcher $dispatcher;

    /**
     * Create the provider's event dispatcher.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new Dispatcher;
    }

    public function testCanBeInstantiatedWithConfig(): void
    {
        $config = [
            'driver' => 'bedrock',
            'name' => 'bedrock',
            'access_key_id' => 'test-key',
            'secret_access_key' => 'test-secret',
            'region' => 'us-east-1',
        ];

        $provider = new BedrockProvider($config, $this->dispatcher);

        $this->assertInstanceOf(BedrockProvider::class, $provider);
    }

    public function testReturnsIamCredentials(): void
    {
        $config = [
            'access_key_id' => 'test-key',
            'secret_access_key' => 'test-secret',
            'session_token' => 'test-session',
        ];

        $provider = new BedrockProvider($config, $this->dispatcher);
        $credentials = $provider->providerCredentials();

        $this->assertSame('test-key', $credentials['access_key_id']);
        $this->assertSame('test-secret', $credentials['secret_access_key']);
        $this->assertSame('test-session', $credentials['session_token']);
    }

    public function testFiltersOutEmptyCredentialValues(): void
    {
        $config = [
            'access_key_id' => 'test-key',
            'secret_access_key' => 'test-secret',
            'session_token' => null,
        ];

        $provider = new BedrockProvider($config, $this->dispatcher);
        $credentials = $provider->providerCredentials();

        $this->assertArrayHasKey('access_key_id', $credentials);
        $this->assertArrayHasKey('secret_access_key', $credentials);
        $this->assertArrayNotHasKey('session_token', $credentials);
    }

    public function testReturnsAdditionalConfigurationWithRegion(): void
    {
        $config = [
            'region' => 'us-west-2',
            'use_default_credential_provider' => true,
        ];

        $provider = new BedrockProvider($config, $this->dispatcher);
        $additionalConfig = $provider->additionalConfiguration();

        $this->assertSame('us-west-2', $additionalConfig['region']);
        $this->assertTrue($additionalConfig['use_default_credential_provider']);
    }

    public function testReturnsConfiguredHeaders(): void
    {
        $provider = new BedrockProvider([
            'headers' => ['X-Session-Affinity' => 'abc-123'],
        ], $this->dispatcher);

        $this->assertSame([
            'X-Session-Affinity' => 'abc-123',
        ], $provider->additionalConfiguration()['headers']);
    }

    public function testPreservesFalseValueForUseDefaultCredentialProvider(): void
    {
        $config = [
            'region' => 'us-east-1',
            'use_default_credential_provider' => false,
        ];

        $provider = new BedrockProvider($config, $this->dispatcher);
        $additionalConfig = $provider->additionalConfiguration();

        $this->assertFalse($additionalConfig['use_default_credential_provider']);
    }

    public function testReturnsBearerTokenCredentialWhenProvided(): void
    {
        $config = [
            'key' => 'bedrock-bearer-token',
        ];

        $provider = new BedrockProvider($config, $this->dispatcher);
        $credentials = $provider->providerCredentials();

        $this->assertSame('bedrock-bearer-token', $credentials['key']);
    }

    public function testDefaultsToUsEast1RegionWhenNotSpecified(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);
        $additionalConfig = $provider->additionalConfiguration();

        $this->assertSame('us-east-1', $additionalConfig['region']);
    }

    public function testAllowsCustomTextModelsInConfig(): void
    {
        $config = [
            'models' => [
                'text' => [
                    'default' => 'custom-model',
                    'cheapest' => 'custom-cheapest',
                    'smartest' => 'custom-smartest',
                ],
            ],
        ];

        $provider = new BedrockProvider($config, $this->dispatcher);

        $this->assertSame('custom-model', $provider->defaultTextModel());
        $this->assertSame('custom-cheapest', $provider->cheapestTextModel());
        $this->assertSame('custom-smartest', $provider->smartestTextModel());
    }

    public function testReturnsDefaultEmbeddingsModel(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertSame('amazon.titan-embed-text-v2:0', $provider->defaultEmbeddingsModel());
    }

    public function testReturnsDefaultEmbeddingsDimensions(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertSame(1024, $provider->defaultEmbeddingsDimensions());
    }

    public function testAllowsCustomEmbeddingsConfig(): void
    {
        $config = [
            'models' => [
                'embeddings' => [
                    'default' => 'custom-embed-model',
                    'dimensions' => 1536,
                ],
            ],
        ];

        $provider = new BedrockProvider($config, $this->dispatcher);

        $this->assertSame('custom-embed-model', $provider->defaultEmbeddingsModel());
        $this->assertSame(1536, $provider->defaultEmbeddingsDimensions());
    }

    public function testReturnsDefaultImageModel(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertSame('amazon.nova-canvas-v1:0', $provider->defaultImageModel());
    }

    public function testReturnsDefaultImageOptions(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);
        $options = $provider->defaultImageOptions();

        $this->assertSame('standard', $options['quality']);
        $this->assertSame('1024x1024', $options['size']);
    }

    public function testConvertsSizeRatiosToDimensionsForImages(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertSame('1024x1024', $provider->defaultImageOptions('1:1')['size']);
        $this->assertSame('768x1152', $provider->defaultImageOptions('2:3')['size']);
        $this->assertSame('1152x768', $provider->defaultImageOptions('3:2')['size']);
    }

    public function testNormalizesCanonicalQualityValuesToBedrockValues(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertSame('standard', $provider->defaultImageOptions(quality: 'low')['quality']);
        $this->assertSame('standard', $provider->defaultImageOptions(quality: 'medium')['quality']);
        $this->assertSame('premium', $provider->defaultImageOptions(quality: 'high')['quality']);
        $this->assertSame('standard', $provider->defaultImageOptions(quality: 'standard')['quality']);
        $this->assertSame('premium', $provider->defaultImageOptions(quality: 'premium')['quality']);
    }

    public function testCreatesTextGateway(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertInstanceOf(BedrockTextGateway::class, $provider->textGateway());
    }

    public function testCreatesEmbeddingGateway(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertInstanceOf(BedrockTextGateway::class, $provider->embeddingGateway());
    }

    public function testCreatesImageGateway(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertInstanceOf(BedrockImageGateway::class, $provider->imageGateway());
    }

    public function testCreatesRerankingGateway(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertInstanceOf(BedrockRerankingGateway::class, $provider->rerankingGateway());
    }

    public function testReturnsDefaultRerankingModel(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertSame('cohere.rerank-v3-5:0', $provider->defaultRerankingModel());
    }

    public function testReturnsConfiguredRerankingModel(): void
    {
        $provider = new BedrockProvider([
            'models' => [
                'reranking' => ['default' => 'amazon.rerank-v1:0'],
            ],
        ], $this->dispatcher);

        $this->assertSame('amazon.rerank-v1:0', $provider->defaultRerankingModel());
    }

    public function testReusesGatewayInstances(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $gateway1 = $provider->textGateway();
        $gateway2 = $provider->textGateway();

        $this->assertSame($gateway1, $gateway2);
    }

    public function testReusesRerankingGatewayInstance(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $gateway1 = $provider->rerankingGateway();
        $gateway2 = $provider->rerankingGateway();

        $this->assertSame($gateway1, $gateway2);
    }

    public function testReturnsAssumeRoleConfigurationWhenProvided(): void
    {
        $config = [
            'region' => 'us-west-2',
            'assume_role' => [
                'arn' => 'arn:aws:iam::123456789012:role/test-role',
                'session_name' => 'my-session',
                'duration_seconds' => 900,
                'external_id' => 'ext-123',
            ],
        ];

        $provider = new BedrockProvider($config, $this->dispatcher);
        $additionalConfig = $provider->additionalConfiguration();

        $this->assertSame([
            'arn' => 'arn:aws:iam::123456789012:role/test-role',
            'session_name' => 'my-session',
            'duration_seconds' => 900,
            'external_id' => 'ext-123',
        ], $additionalConfig['assume_role']);
    }

    public function testAssumeRoleConfigurationDefaultsToNullWhenNotSet(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);
        $additionalConfig = $provider->additionalConfiguration();

        $this->assertSame([
            'arn' => null,
            'session_name' => null,
            'duration_seconds' => null,
            'external_id' => null,
        ], $additionalConfig['assume_role']);
    }
}
