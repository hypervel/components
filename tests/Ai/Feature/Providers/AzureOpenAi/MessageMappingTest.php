<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\AzureOpenAi;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeAzureResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenAiToolCallResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class MessageMappingTest extends TestCase
{
    /**
     * Configure the Azure OpenAI deployment.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('ai.providers.azure', [
            ...$config->get('ai.providers.azure'),
            'key' => 'test-key',
            'url' => 'https://my-resource.cognitiveservices.azure.com',
            'deployment' => 'gpt-4o',
        ]);
    }

    public function testUserMessageMapsToAzureFormat(): void
    {
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => fakeAzureResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'What is Hypervel?',
            provider: 'azure',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $input = $body['input'];
            $userMessage = collect($input)->firstWhere('role', 'user');

            return $userMessage !== null
                && $userMessage['content'][0]['type'] === 'input_text'
                && $userMessage['content'][0]['text'] === 'What is Hypervel?';
        });
    }

    public function testToolResultFollowUpUsesPreviousResponseId(): void
    {
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => Http::sequence([
                fakeOpenAiToolCallResponse('resp_azure_tool_123', 'gpt-4o'),
                fakeAzureResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'azure',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

        $this->assertArrayHasKey('previous_response_id', $followUpBody);
        $this->assertSame('resp_azure_tool_123', $followUpBody['previous_response_id']);

        $hasFunctionCallOutput = false;

        foreach ($followUpBody['input'] as $item) {
            if (($item['type'] ?? '') === 'function_call_output') {
                $hasFunctionCallOutput = true;
                $this->assertSame('call_123', $item['call_id']);
                $this->assertNotEmpty($item['output']);
            }
        }

        $this->assertTrue($hasFunctionCallOutput);
    }

    public function testAzureStoreFalseEnablesStatelessInlineConversation(): void
    {
        config(['ai.providers.azure' => [
            ...config('ai.providers.azure'),
            'store' => false,
        ]]);

        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => Http::sequence([
                fakeOpenAiToolCallResponse('resp_azure_tool_123', 'gpt-4o'),
                fakeAzureResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'azure');

        $recorded = Http::recorded();
        $initialBody = json_decode((string) $recorded[0][0]->body(), true);
        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

        $this->assertFalse($initialBody['store'] ?? null);
        $this->assertArrayNotHasKey('previous_response_id', $followUpBody);
        $this->assertFalse($followUpBody['store'] ?? null);
    }

    public function testImageAttachmentMapsToInputImageContentBlock(): void
    {
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => fakeAzureResponse('I see an image'),
        ]);

        $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'azure',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['input'])->firstWhere('role', 'user');
            $content = $userMessage['content'];

            $imageBlock = collect($content)->firstWhere('type', 'input_image');

            return $imageBlock !== null
                && str_contains((string) $imageBlock['image_url'], 'image/png')
                && str_contains((string) $imageBlock['image_url'], base64_encode('fake-image-data'));
        });
    }

    public function testAttachmentProviderOptionsClosureReceivesTheAzureProvider(): void
    {
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => fakeAzureResponse('I see an image'),
        ]);

        $image = (new Base64Image(base64_encode('fake-image-data'), 'image/png'))
            ->withProviderOptions(fn (Lab $provider): array => match ($provider) {
                Lab::Azure => ['detail' => 'low'],
                default => ['detail' => 'high'],
            });

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'azure',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['input'])->firstWhere('role', 'user');
            $imageBlock = collect($userMessage['content'])->firstWhere('type', 'input_image');

            return $imageBlock !== null
                && ($imageBlock['detail'] ?? null) === 'low';
        });
    }

    public function testDocumentAttachmentMapsToInputFileContentBlock(): void
    {
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => fakeAzureResponse('I see a PDF'),
        ]);

        $pdf = new Base64Document(base64_encode('fake-pdf'), 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this PDF?',
            attachments: [$pdf],
            provider: 'azure',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['input'])->firstWhere('role', 'user');
            $content = $userMessage['content'];

            $fileBlock = collect($content)->firstWhere('type', 'input_file');

            return $fileBlock !== null
                && str_contains((string) $fileBlock['file_data'], 'application/pdf');
        });
    }
}
