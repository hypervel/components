<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Bedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Gateway\Bedrock\BedrockImageGateway;
use Hypervel\Ai\Image;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\BedrockHelpers;
use Hypervel\Tests\Ai\TestCase;
use LogicException;

class ImageGenerationTest extends TestCase
{
    use BedrockHelpers;

    public function testImageAttachmentsAreRejectedBeforeSendingARequest(): void
    {
        Http::fake(['*' => Http::response(['images' => [base64_encode('unrelated-image')]])]);
        $exception = null;

        try {
            Image::of('Make it brighter')
                ->attachments([new Base64Image(base64_encode('image'), 'image/png')])
                ->generate(provider: $this->bedrockProvider(), model: 'amazon.titan-image-generator-v2:0');
        } catch (LogicException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(LogicException::class, $exception);
        $this->assertSame('The Bedrock image gateway does not support attachments.', $exception->getMessage());
        Http::assertNothingSent();
    }

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
