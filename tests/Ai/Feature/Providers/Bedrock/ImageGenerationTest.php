<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Bedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Hypervel\Ai\Gateway\Bedrock\BedrockImageGateway;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Tests\Ai\Fixtures\BedrockHelpers;
use Hypervel\Tests\Ai\TestCase;

class ImageGenerationTest extends TestCase
{
    use BedrockHelpers;

    public function testNestedProviderOptionsAreMergedBeneathTheCoreImageBody(): void
    {
        $mock = $this->bedrockInvokeMock(['images' => [base64_encode('fake-image')]]);

        $gateway = new class($this->bedrockClient($mock)) extends BedrockImageGateway {
            /**
             * Create a gateway with the supplied client.
             */
            public function __construct(private BedrockRuntimeClient $stub)
            {
            }

            /**
             * Return the supplied client.
             */
            protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
            {
                return $this->stub;
            }
        };

        $gateway->generateImage(
            $this->bedrockProvider(),
            'amazon.titan-image-generator-v2:0',
            'A red apple',
            providerOptions: ['imageGenerationConfig' => ['seed' => 42, 'numberOfImages' => 5]],
        );

        $body = json_decode($mock->getLastCommand()['body'], true);

        $this->assertSame(42, $body['imageGenerationConfig']['seed']);
        $this->assertSame(1, $body['imageGenerationConfig']['numberOfImages']);
        $this->assertSame('A red apple', $body['textToImageParams']['text']);
    }
}
