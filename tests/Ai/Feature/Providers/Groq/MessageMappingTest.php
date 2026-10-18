<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Groq;

use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeGroqResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeGroqToolCallResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class MessageMappingTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.groq.key', 'test-key');
    }

    public function testUserMessageMapsToGroqFormat(): void
    {
        Http::fake([
            'api.groq.com/*' => fakeGroqResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'What is Hypervel?',
            provider: 'groq',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $messages = $body['messages'];
            $userMessage = collect($messages)->firstWhere('role', 'user');

            return $userMessage !== null
                && $userMessage['content'] === 'What is Hypervel?';
        });
    }

    public function testToolResultFollowUpMapsAssistantAndToolResultMessages(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::sequence([
                fakeGroqToolCallResponse(),
                fakeGroqResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'groq',
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

        $assistantMsg = collect($followUpMessages)->last(fn (array $m): bool => $m['role'] === 'assistant' && isset($m['tool_calls']));
        $toolMsg = collect($followUpMessages)->last(fn (array $m): bool => $m['role'] === 'tool');

        $this->assertSame('FixedNumberGenerator', $assistantMsg['tool_calls'][0]['function']['name']);
        $this->assertSame($assistantMsg['tool_calls'][0]['id'], $toolMsg['tool_call_id']);
        $this->assertNotEmpty($toolMsg['content']);
    }

    public function testImageAttachmentMapsToImageUrlContentBlock(): void
    {
        Http::fake([
            'api.groq.com/*' => fakeGroqResponse('I see an image'),
        ]);

        $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'groq',
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

    public function testLocalImageAttachmentWithoutExplicitMimeTypeDetectsMimeFromFile(): void
    {
        Http::fake([
            'api.groq.com/*' => fakeGroqResponse('I see an image'),
        ]);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
            provider: 'groq',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['messages'])->firstWhere('role', 'user');
            $imageBlock = collect($userMessage['content'])->firstWhere('type', 'image_url');

            return $imageBlock !== null
                && str_starts_with((string) $imageBlock['image_url']['url'], 'data:image/png;base64,')
                && ! str_contains((string) $imageBlock['image_url']['url'], 'data:;base64,');
        });
    }

    public function testDocumentAttachmentsThrowException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Http::fake([
            'api.groq.com/*' => fakeGroqResponse(),
        ]);

        $pdf = new Base64Document(base64_encode('fake-pdf'), 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this PDF?',
            attachments: [$pdf],
            provider: 'groq',
        );
    }
}
