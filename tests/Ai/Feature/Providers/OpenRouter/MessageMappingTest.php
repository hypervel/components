<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use Hypervel\Ai\Files\Audio;
use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Files\ProviderDocument;
use Hypervel\Ai\Files\ProviderImage;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenRouterResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenRouterToolCallResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class MessageMappingTest extends TestCase
{
    /**
     * Configure the OpenRouter credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    public function testUserMessageMapsToChatCompletionsFormat(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hello there', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['messages'])->firstWhere('role', 'user');

            return $userMsg !== null
                && $userMsg['content'] === 'Hello there';
        });
    }

    public function testSystemInstructionsAreSentAsSystemRoleMessage(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        (new AssistantAgent)->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['messages'][0]['role'] === 'system'
                && str_contains((string) $body['messages'][0]['content'], 'helpful assistant');
        });
    }

    public function testToolResultFollowUpMapsAssistantAndToolResultMessages(): void
    {
        Http::fake([
            '*' => Http::sequence([
                fakeOpenRouterToolCallResponse(),
                fakeOpenRouterResponse('The number is 72019'),
            ]),
        ]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

        $requests = Http::recorded(fn (Request $r): true => true);
        $followUpBody = json_decode((string) $requests[1][0]->body(), true);
        $messages = $followUpBody['messages'];

        $assistantMsg = collect($messages)->firstWhere('role', 'assistant');
        $this->assertNotNull($assistantMsg);
        $this->assertArrayHasKey('tool_calls', $assistantMsg);
        $this->assertSame('FixedNumberGenerator', $assistantMsg['tool_calls'][0]['function']['name']);

        $toolMsg = collect($messages)->firstWhere('role', 'tool');
        $this->assertNotNull($toolMsg);
        $this->assertSame('call_123', $toolMsg['tool_call_id']);
    }

    public function testLocalImageAttachmentWithoutExplicitMimeTypeDetectsMimeFromFile(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('I see an image')]);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
            provider: 'openrouter',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['messages'])->firstWhere('role', 'user');
            $imageBlock = collect($userMsg['content'])->firstWhere('type', 'image_url');

            return $imageBlock !== null
                && str_starts_with((string) $imageBlock['image_url']['url'], 'data:image/png;base64,')
                && ! str_contains((string) $imageBlock['image_url']['url'], 'data:;base64,');
        });
    }

    public function testBase64DocumentWithoutAnExplicitNameFallsBackToAMimeBasedFilename(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('I see a document')]);

        $document = new Base64Document(base64_encode('fake-pdf-data'), 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this document?',
            attachments: [$document],
            provider: 'openrouter',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['messages'])->firstWhere('role', 'user');
            $fileBlock = collect($userMsg['content'])->firstWhere('type', 'file');

            return $fileBlock !== null
                && $fileBlock['file']['filename'] === 'document.pdf';
        });
    }

    /**
     * Get the audio content part from the recorded request.
     */
    protected function openRouterAudioPart(): ?array
    {
        $request = Http::recorded(fn (Request $r): bool => str_starts_with($r->url(), 'https://openrouter.ai'))->first()[0];
        $body = json_decode((string) $request->body(), true);
        $userMsg = collect($body['messages'])->firstWhere('role', 'user');

        return collect($userMsg['content'])->firstWhere('type', 'input_audio');
    }

    public function testBase64AudioAttachmentMapsToInputAudioWithFormatFromMimeType(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Heard it')]);

        agent()->prompt(
            'Transcribe this.',
            attachments: [Audio::fromBase64(base64_encode('fake-audio'), 'audio/mpeg')],
            provider: 'openrouter',
        );

        $this->assertSame([
            'type' => 'input_audio',
            'input_audio' => ['format' => 'mp3', 'data' => base64_encode('fake-audio')],
        ], $this->openRouterAudioPart());
    }

    public function testRemoteAudioAttachmentIsDownloadedAndSentAsBase64(): void
    {
        Http::fake([
            'https://example.com/note.wav' => Http::response('wav-bytes', 200, ['Content-Type' => 'audio/wav']),
            'https://openrouter.ai/*' => fakeOpenRouterResponse('Heard it'),
        ]);

        agent()->prompt(
            'Transcribe this.',
            attachments: [Audio::fromUrl('https://example.com/note.wav')],
            provider: 'openrouter',
        );

        $this->assertSame(['format' => 'wav', 'data' => base64_encode('wav-bytes')], $this->openRouterAudioPart()['input_audio']);
    }

    public function testUploadedAudioFileMapsToInputAudio(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Heard it')]);

        agent()->prompt(
            'Transcribe this.',
            attachments: [UploadedFile::fake()->createWithContent('note.mp3', 'mp3-bytes')],
            provider: 'openrouter',
        );

        $this->assertSame(['format' => 'mp3', 'data' => base64_encode('mp3-bytes')], $this->openRouterAudioPart()['input_audio']);
    }

    #[DataProvider('providerAttachments')]
    public function testProviderStoredAttachmentsAreRejected(string $attachmentClass): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Provider-stored attachments are not supported by OpenRouter; uploaded files may only be loaded into a sandbox container by the shell tool.');
        agent()->prompt('What is in this file?', attachments: [new $attachmentClass('or_file_abc123')], provider: 'openrouter');
    }

    /**
     * Provide unsupported provider-stored attachment types.
     */
    public static function providerAttachments(): array
    {
        return ['document' => [ProviderDocument::class], 'image' => [ProviderImage::class]];
    }
}
