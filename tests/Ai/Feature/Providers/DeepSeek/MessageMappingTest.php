<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\DeepSeek;

use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeDeepSeekResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeDeepSeekToolCallResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class MessageMappingTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.deepseek.key', 'test-key');
    }

    public function testUserMessageMapsToDeepSeekFormat(): void
    {
        Http::fake([
            'api.deepseek.com/*' => fakeDeepSeekResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'What is Hypervel?',
            provider: 'deepseek',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['messages'])->firstWhere('role', 'user');

            return $userMessage !== null
                && $userMessage['content'] === 'What is Hypervel?';
        });
    }

    public function testToolResultFollowUpMapsAssistantAndToolResultMessages(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence([
                fakeDeepSeekToolCallResponse(),
                fakeDeepSeekResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'deepseek',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);
        $followUpMessages = $followUpBody['messages'];

        $hasAssistantWithToolCalls = false;
        $hasToolResult = false;

        foreach ($followUpMessages as $msg) {
            if ($msg['role'] === 'assistant' && isset($msg['tool_calls'])) {
                $hasAssistantWithToolCalls = true;
            }

            if ($msg['role'] === 'tool') {
                $hasToolResult = true;
            }
        }

        $this->assertTrue($hasAssistantWithToolCalls);
        $this->assertTrue($hasToolResult);

        $assistantMsg = collect($followUpMessages)->last(fn (array $message): bool => $message['role'] === 'assistant' && isset($message['tool_calls']));
        $toolMsg = collect($followUpMessages)->last(fn (array $message): bool => $message['role'] === 'tool');

        $this->assertSame('FixedNumberGenerator', $assistantMsg['tool_calls'][0]['function']['name']);
        $this->assertSame($assistantMsg['tool_calls'][0]['id'], $toolMsg['tool_call_id']);
        $this->assertNotEmpty($toolMsg['content']);
    }

    public function testNumericToolArgumentNamesRemainAJsonObject(): void
    {
        Http::fake(['*' => fakeDeepSeekResponse()]);

        agent(messages: [
            new AssistantMessage('', collect([new ToolCall('call_1', 'Lookup', ['0' => 'value'])])),
            new ToolResultMessage(collect([new ToolResult('call_1', 'Lookup', ['0' => 'value'], 'Found')])),
        ])->prompt('Summarize the result.', provider: 'deepseek');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['messages'][0]['tool_calls'][0]['function']['arguments'] === '{"0":"value"}');
    }

    public function testImageAttachmentMapsToImageUrlContentBlock(): void
    {
        Http::fake([
            'api.deepseek.com/*' => fakeDeepSeekResponse('I see an image'),
        ]);

        $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'deepseek',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['messages'])->firstWhere('role', 'user');
            $content = $userMessage['content'];

            $imageBlock = collect($content)->firstWhere('type', 'image_url');

            return $imageBlock !== null
                && str_contains((string) $imageBlock['image_url']['url'], 'image/png')
                && str_contains((string) $imageBlock['image_url']['url'], base64_encode('fake-image-data'));
        });
    }

    public function testDocumentAttachmentsThrowException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Http::fake([
            'api.deepseek.com/*' => fakeDeepSeekResponse(),
        ]);

        $pdf = new Base64Document(base64_encode('fake-pdf'), 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this PDF?',
            attachments: [$pdf],
            provider: 'deepseek',
        );
    }
}
