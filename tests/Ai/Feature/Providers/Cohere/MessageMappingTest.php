<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Cohere;

use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\CohereHelpers;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

use function Hypervel\Ai\agent;

class MessageMappingTest extends TestCase
{
    use CohereHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.cohere.key', 'test-key');
    }

    public function testConversationHistoryMapsToChatMessages(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Your name is Sam.')]);

        agent('Be concise.', messages: [
            new Message(role: 'user', content: 'My name is Sam.'),
            new Message(role: 'assistant', content: 'Nice to meet you, Sam.'),
        ])->prompt('What is my name?', provider: 'cohere');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['messages'] === [
            ['role' => 'system', 'content' => 'Be concise.'],
            ['role' => 'user', 'content' => 'My name is Sam.'],
            ['role' => 'assistant', 'content' => 'Nice to meet you, Sam.'],
            ['role' => 'user', 'content' => 'What is my name?'],
        ]);
    }

    public function testAssistantToolCallsAndToolResultsAreReplayedInTheFollowUpRequest(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_1'),
                $this->fakeTextResponse('The random number generated is 72019.'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'cohere');

        $messages = json_decode((string) Http::recorded()[1][0]->body(), true)['messages'];

        $this->assertSame([
            'role' => 'assistant',
            'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}'],
            ]],
        ], collect($messages)->firstWhere('role', 'assistant'));
        $this->assertSame([
            'role' => 'tool',
            'tool_call_id' => 'call_1',
            'content' => '72019',
        ], collect($messages)->firstWhere('role', 'tool'));
    }

    public function testNumericToolArgumentNamesRemainAJsonObject(): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);

        agent(messages: [
            new AssistantMessage('', collect([new ToolCall('call_1', 'Lookup', ['0' => 'value'])])),
            new ToolResultMessage(collect([new ToolResult('call_1', 'Lookup', ['0' => 'value'], 'Found')])),
        ])->prompt('Summarize the result.', provider: 'cohere');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['messages'][0]['tool_calls'][0]['function']['arguments'] === '{"0":"value"}');
    }

    public function testRemoteImageAttachmentMapsToImageUrl(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [new RemoteImage('https://example.com/image.png')],
            provider: 'cohere',
        );

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['messages'][1]['content'] === [
            ['type' => 'text', 'text' => 'What is in this image?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.png']],
        ]);
    }

    public function testBase64ImageAttachmentMapsToDataUri(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [new Base64Image(base64_encode('fake-image-data'), 'image/png')],
            provider: 'cohere',
        );

        Http::assertSent(function (Request $request): bool {
            $content = json_decode($request->body(), true)['messages'][1]['content'];

            return collect($content)->firstWhere('type', 'image_url') === [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('fake-image-data')],
            ];
        });
    }

    public function testUploadedImageMapsToDataUri(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [UploadedFile::fake()->createWithContent('photo.png', 'png-bytes')],
            provider: 'cohere',
        );

        Http::assertSent(function (Request $request): bool {
            $content = json_decode($request->body(), true)['messages'][1]['content'];

            return collect($content)->firstWhere('type', 'image_url') === [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('png-bytes')],
            ];
        });
    }

    public function testDocumentAttachmentsThrow(): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);

        try {
            agent('You are helpful.')->prompt(
                'What is in this document?',
                attachments: [Document::fromString('contents', 'text/plain')],
                provider: 'cohere',
            );
            $this->fail('Expected unsupported document attachments to throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Cohere does not support document attachments. Only image attachments are supported.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
