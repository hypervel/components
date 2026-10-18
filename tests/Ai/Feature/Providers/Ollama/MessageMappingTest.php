<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Ollama;

use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\OllamaHelpers;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

use function Hypervel\Ai\agent;

class MessageMappingTest extends TestCase
{
    use OllamaHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.ollama.key', '');
    }

    public function testUserMessageMapsToOllamaFormat(): void
    {
        Http::fake([
            '*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'What is Hypervel?',
            provider: 'ollama',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $messages = $body['messages'];
            $userMessage = collect($messages)->firstWhere('role', 'user');

            return $userMessage !== null
                && $userMessage['content'] === 'What is Hypervel?';
        });
    }

    public function testToolResultFollowUpUsesToolNameNotToolCallId(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'ollama',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);
        $followUpMessages = $followUpBody['messages'];

        $toolMsg = collect($followUpMessages)->first(fn (array $m): bool => $m['role'] === 'tool');

        $this->assertNotNull($toolMsg);
        $this->assertArrayHasKey('tool_name', $toolMsg);
        $this->assertArrayNotHasKey('tool_call_id', $toolMsg);
        $this->assertSame('FixedNumberGenerator', $toolMsg['tool_name']);
    }

    #[DataProvider('toolArguments')]
    public function testAssistantToolCallMessageUsesFunctionFormatWithoutType(array $arguments): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response([
                    'model' => 'llama3.1:8b',
                    'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                        $this->toolCallChunk('call_123', 'FixedNumberGenerator', $arguments),
                    ]],
                    'done' => true,
                    'done_reason' => 'tool_calls',
                ]),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'ollama',
        );

        $recorded = Http::recorded();
        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);
        $followUpMessages = $followUpBody['messages'];

        $assistantMsg = collect($followUpMessages)->first(
            fn (array $m): bool => $m['role'] === 'assistant' && isset($m['tool_calls'])
        );

        $this->assertNotNull($assistantMsg);

        $toolCall = $assistantMsg['tool_calls'][0];

        $this->assertArrayHasKey('function', $toolCall);
        $this->assertArrayNotHasKey('type', $toolCall);
        $this->assertSame('FixedNumberGenerator', $toolCall['function']['name']);
        $this->assertSame($arguments, $toolCall['function']['arguments']);
        $messages = json_decode($recorded[1][0]->body())->messages;
        $assistant = collect($messages)->first(fn (stdClass $message): bool => isset($message->tool_calls));
        $this->assertInstanceOf(stdClass::class, $assistant->tool_calls[0]->function->arguments);
    }

    /**
     * Provide tool arguments with ordinary and numeric property names.
     */
    public static function toolArguments(): array
    {
        return [[[]], [['city' => 'Paris']], [[0 => 'Paris']]];
    }

    public function testImageAttachmentMapsToImagesArrayWithBase64(): void
    {
        Http::fake([
            '*' => $this->fakeTextResponse('I see an image'),
        ]);

        $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'ollama',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['messages'])->firstWhere('role', 'user');

            return isset($userMessage['images'])
                && is_array($userMessage['images'])
                && $userMessage['images'][0] === base64_encode('fake-image-data');
        });
    }

    public function testDocumentAttachmentsThrowException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Http::fake([
            '*' => $this->fakeTextResponse(),
        ]);

        $pdf = new Base64Document(base64_encode('fake-pdf'), 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this PDF?',
            attachments: [$pdf],
            provider: 'ollama',
        );
    }
}
