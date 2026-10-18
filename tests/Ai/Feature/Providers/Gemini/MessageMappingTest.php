<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\Base64Video;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Files\RemoteAudio;
use Hypervel\Ai\Files\RemoteDocument;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Storage;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\GeminiHelpers;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

/**
 * Get the recorded Gemini request body.
 */
function geminiRequestBody(): array
{
    return Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->first(fn (Request $request): bool => str_contains((string) $request->url(), 'generativelanguage.googleapis.com'))
        ->data();
}

/**
 * Find an input content block by type.
 */
function geminiInputBlock(string $type): ?array
{
    foreach (geminiRequestBody()['input'] as $step) {
        foreach ($step['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === $type) {
                return $block;
            }
        }
    }

    return null;
}

class MessageMappingTest extends TestCase
{
    use GeminiHelpers;

    public function testUserMessageMapsToAUserInputStep(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'What is Hypervel?',
            provider: 'gemini',
        );

        $this->assertSame('user_input', geminiRequestBody()['input'][0]['type']);
        $this->assertSame([['type' => 'text', 'text' => 'What is Hypervel?']], geminiRequestBody()['input'][0]['content']);
    }

    public function testToolResultFollowUpMapsAFunctionCallAndFunctionResultStep(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 72019'),
        ]);

        $agent = new class implements Agent, Conversational {
            use Promptable;

            /**
             * Get the agent's instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }

            /**
             * Get the conversation's messages.
             */
            public function messages(): iterable
            {
                $toolResults = collect([
                    'custom_key' => new ToolResult('call_123', 'FixedNumberGenerator', [], 123),
                ]);

                return [
                    new Message(role: 'user', content: 'Generate a number'),
                    new AssistantMessage('', collect([
                        new ToolCall('call_123', 'FixedNumberGenerator', [], 'call_123'),
                    ])),
                    new ToolResultMessage($toolResults),
                ];
            }
        };

        $agent->prompt('Follow up', provider: 'gemini');

        $recorded = Http::recorded();

        $this->assertCount(1, $recorded);

        $input = $recorded[0][0]->data()['input'];

        $functionCall = collect($input)->firstWhere('type', 'function_call');
        $functionResult = collect($input)->firstWhere('type', 'function_result');

        $this->assertNotNull($functionCall, 'Follow-up should include a function_call step');
        $this->assertSame('FixedNumberGenerator', $functionCall['name']);
        $this->assertSame('call_123', $functionCall['id']);
        $this->assertNotNull($functionResult, 'Follow-up should include a function_result step');
        $this->assertSame('call_123', $functionResult['call_id']);
        $this->assertSame('FixedNumberGenerator', $functionResult['name']);
        $this->assertTrue(array_is_list($functionResult['result']), 'Tool results must be a sequential array');
        $this->assertSame('text', $functionResult['result'][0]['type']);
    }

    public function testPriorAssistantToolCallSendsItsArgumentsAsAnObject(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('OK'),
        ]);

        $agent = new class implements Agent, Conversational {
            use Promptable;

            /**
             * Get the agent's instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }

            /**
             * Get the conversation's messages.
             */
            public function messages(): iterable
            {
                return [
                    new Message(role: 'user', content: 'Generate a number'),
                    new AssistantMessage('', new Collection([
                        new ToolCall('call_123', 'FixedNumberGenerator', [], 'call_123'),
                    ])),
                ];
            }
        };

        $agent->prompt('And again', provider: 'gemini');

        $body = Http::recorded()[0][0]->body();

        $this->assertStringContainsString('"arguments":{}', $body);
    }

    public function testPriorAssistantStepsAreReplayedToGeminiVerbatim(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('OK'),
        ]);

        $replayed = [
            ['type' => 'thought', 'summary' => [['type' => 'text', 'text' => 'Thinking.']], 'signature' => 'sig_123'],
            ['type' => 'function_call', 'id' => 'call_123', 'name' => 'FixedNumberGenerator', 'arguments' => []],
        ];

        $agent = new class($replayed) implements Agent, Conversational {
            use Promptable;

            /**
             * Create an agent with replayable history.
             */
            public function __construct(protected array $replayed)
            {
            }

            /**
             * Get the agent's instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }

            /**
             * Get the conversation's messages.
             */
            public function messages(): iterable
            {
                return [
                    new Message(role: 'user', content: 'Generate a number'),
                    new AssistantMessage('', new Collection([
                        new ToolCall('call_123', 'FixedNumberGenerator', [], 'call_123'),
                    ]), replayBlocks: $this->replayed),
                ];
            }
        };

        $agent->prompt('And again', provider: 'gemini');

        [$request] = Http::recorded()[0];

        $this->assertSame($replayed[0], $request->data()['input'][1]);
        $this->assertStringContainsString('{"type":"function_call","id":"call_123","name":"FixedNumberGenerator","arguments":{}}', $request->body());
    }

    public function testLocalImageAttachmentWithoutExplicitMimeTypeDetectsMimeFromFile(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see an image'),
        ]);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
            provider: 'gemini',
        );

        $this->assertSame('image/png', geminiInputBlock('image')['mime_type']);
    }

    public function testAnAttachmentOnlyMessageSendsNoTextBlock(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see an image'),
        ]);

        agent('You are helpful.')->prompt(
            '',
            attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
            provider: 'gemini',
        );

        $this->assertCount(1, geminiRequestBody()['input'][0]['content']);
        $this->assertSame('image', geminiRequestBody()['input'][0]['content'][0]['type']);
    }

    public function testBase64PdfDocumentMapsToADocumentBlock(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see a PDF'),
        ]);

        $pdf = new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this PDF?',
            attachments: [$pdf],
            provider: 'gemini',
        );

        $this->assertSame('application/pdf', geminiInputBlock('document')['mime_type']);
        $this->assertSame(base64_encode('fake-pdf-content'), geminiInputBlock('document')['data']);
    }

    public function testBase64VideoAttachmentMapsToAVideoBlock(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see a video'),
        ]);

        $video = new Base64Video(base64_encode('fake-video-content'), 'video/mp4');

        agent('You are helpful.')->prompt(
            'What is in this video?',
            attachments: [$video],
            provider: 'gemini',
        );

        $this->assertSame('video/mp4', geminiInputBlock('video')['mime_type']);
        $this->assertSame(base64_encode('fake-video-content'), geminiInputBlock('video')['data']);
    }

    public function testRemoteImageUrlIsFetchedAndMappedToAnImageBlock(): void
    {
        Http::fake([
            'example.com/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/png']),
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see an image'),
        ]);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [new RemoteImage('https://example.com/photo.png', 'image/png')],
            provider: 'gemini',
        );

        $this->assertSame('image/png', geminiInputBlock('image')['mime_type']);
        $this->assertSame(base64_encode('fake-image-bytes'), geminiInputBlock('image')['data']);
    }

    public function testRemotePdfUrlIsFetchedAndMappedToADocumentBlock(): void
    {
        Http::fake([
            'example.com/*' => Http::response('fake-pdf-bytes', 200, ['Content-Type' => 'application/pdf']),
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see a PDF'),
        ]);

        agent('You are helpful.')->prompt(
            'What is in this PDF?',
            attachments: [new RemoteDocument('https://example.com/report.pdf', 'application/pdf')],
            provider: 'gemini',
        );

        $this->assertSame('application/pdf', geminiInputBlock('document')['mime_type']);
        $this->assertSame(base64_encode('fake-pdf-bytes'), geminiInputBlock('document')['data']);
    }

    public function testRemoteAudioUrlIsFetchedAndMappedToAnAudioBlock(): void
    {
        Http::fake([
            'example.com/*' => Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/mp3']),
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I hear audio'),
        ]);

        agent('You are helpful.')->prompt(
            'What is in this audio?',
            attachments: [new RemoteAudio('https://example.com/clip.mp3', 'audio/mp3')],
            provider: 'gemini',
        );

        $this->assertSame('audio/mp3', geminiInputBlock('audio')['mime_type']);
        $this->assertSame(base64_encode('fake-audio-bytes'), geminiInputBlock('audio')['data']);
    }

    public function testStoredTextDocumentSendsRealMimeType(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        Storage::fake('docs');
        Storage::disk('docs')->put('notes.txt', 'stored text contents');

        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [Document::fromStorage('notes.txt', 'docs')],
            provider: 'gemini',
        );

        $this->assertSame('text/plain', geminiInputBlock('document')['mime_type']);
        $this->assertSame(base64_encode('stored text contents'), geminiInputBlock('document')['data']);
    }

    public function testSystemInstructionsAreNotPartOfTheInputSteps(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        $this->assertNotContains('system', array_column(geminiRequestBody()['input'], 'type'));
        $this->assertArrayHasKey('system_instruction', geminiRequestBody());
    }
}
