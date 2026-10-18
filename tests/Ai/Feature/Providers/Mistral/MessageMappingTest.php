<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Mistral;

use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\LocalAudio;
use Hypervel\Ai\Files\LocalDocument;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Files\RemoteDocument;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Files\StoredDocument;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Storage;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\MistralHelpers;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Ai\agent;

class MessageMappingTest extends TestCase
{
    use MistralHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.mistral.key', 'test-key');
    }

    public function testUserMessageMapsToChatFormat(): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);

        (new AssistantAgent)->prompt('What is Hypervel?', provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['messages'])->firstWhere('role', 'user');

            return $userMsg['content'] === 'What is Hypervel?';
        });
    }

    public function testAssistantMessageWithToolCallsMapsCorrectly(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'mistral');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

        $assistantMsg = collect($followUpBody['messages'])->firstWhere('role', 'assistant');
        $toolMsg = collect($followUpBody['messages'])->firstWhere('role', 'tool');

        $this->assertNotNull($assistantMsg);
        $this->assertNotNull($toolMsg);
        $this->assertNotEmpty($assistantMsg['tool_calls']);
        $this->assertSame('FixedNumberGenerator', $assistantMsg['tool_calls'][0]['function']['name']);
        $this->assertSame($assistantMsg['tool_calls'][0]['id'], $toolMsg['tool_call_id']);
    }

    #[DataProvider('historyToolIds')]
    public function testHistoryToolIdsMatchMistralsFormatWithoutChangingStoredIds(string $id, ?string $resultId): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);
        $call = new ToolCall($id, 'lookup', [], $resultId);
        $result = new ToolResult($id, 'lookup', [], 'Found', $resultId);

        agent(messages: [new AssistantMessage('', collect([$call])), new ToolResultMessage(collect([$result]))])
            ->prompt('Continue', provider: 'mistral');

        $messages = Http::recorded()[0][0]->data()['messages'];
        $wireId = $messages[0]['tool_calls'][0]['id'];
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{9}$/D', $wireId);
        $this->assertSame($wireId, $messages[1]['tool_call_id']);
        $this->assertSame($id, $call->id);
        $this->assertSame($id, $result->id);
        $this->assertSame($resultId, $call->resultId);
        $this->assertSame($resultId, $result->resultId);
        if (strlen($resultId ?? $id) === 9) {
            $this->assertSame($resultId ?? $id, $wireId);
        }
    }

    /**
     * Provide foreign IDs, native IDs and a provider-specific result ID.
     */
    public static function historyToolIds(): array
    {
        return [['toolu_foreign', null], ['Abc123xyz', null], ['call_foreign', 'Native123']];
    }

    public function testImageAttachmentMapsToImageUrl(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

        $image = new RemoteImage('https://example.com/image.png');

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'mistral',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

            if (! is_array($content)) {
                return false;
            }

            $imageBlock = collect($content)->firstWhere('type', 'image_url');

            return $imageBlock !== null
                && $imageBlock['image_url']['url'] === 'https://example.com/image.png';
        });
    }

    public function testBase64ImageAttachmentMapsToDataUri(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

        $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'mistral',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

            if (! is_array($content)) {
                return false;
            }

            $imageBlock = collect($content)->firstWhere('type', 'image_url');

            return $imageBlock !== null
                && str_starts_with((string) $imageBlock['image_url']['url'], 'data:image/png;base64,');
        });
    }

    public function testLocalImageAttachmentWithoutExplicitMimeTypeDetectsMimeFromFile(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
            provider: 'mistral',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

            if (! is_array($content)) {
                return false;
            }

            $imageBlock = collect($content)->firstWhere('type', 'image_url');

            return $imageBlock !== null
                && str_starts_with((string) $imageBlock['image_url']['url'], 'data:image/png;base64,')
                && ! str_contains((string) $imageBlock['image_url']['url'], 'data:;base64,');
        });
    }

    public function testRemoteDocumentMapsToDocumentUrl(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

        $document = new RemoteDocument('https://example.com/report.pdf');

        agent('You are helpful.')->prompt(
            'What is in this document?',
            attachments: [$document],
            provider: 'mistral',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

            if (! is_array($content)) {
                return false;
            }

            $docBlock = collect($content)->firstWhere('type', 'document_url');

            return $docBlock !== null
                && $docBlock['document_url'] === 'https://example.com/report.pdf';
        });
    }

    public function testBase64DocumentMapsToAnInlineDocumentUrl(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

        $document = (new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf'))->as('report.pdf');

        agent('You are helpful.')->prompt(
            'What is in this document?',
            attachments: [$document],
            provider: 'mistral',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

            return collect($content)->firstWhere('type', 'document_url') === [
                'type' => 'document_url',
                'document_url' => 'data:application/pdf;base64,' . base64_encode('fake-pdf-content'),
                'document_name' => 'report.pdf',
            ];
        });
    }

    public function testLocalDocumentMapsToAnInlineDocumentUrl(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

        $path = __DIR__ . '/../../../Fixtures/document.txt';

        agent('You are helpful.')->prompt(
            'What is in this document?',
            attachments: [new LocalDocument($path)],
            provider: 'mistral',
        );

        Http::assertSent(function (Request $request) use ($path): bool {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

            return collect($content)->firstWhere('type', 'document_url') === [
                'type' => 'document_url',
                'document_url' => 'data:text/plain;base64,' . base64_encode(file_get_contents($path)),
                'document_name' => 'document.txt',
            ];
        });
    }

    public function testStoredDocumentMapsToAnInlineDocumentUrl(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see a document')]);
        Storage::fake('docs');
        Storage::disk('docs')->put('notes.txt', 'stored text contents');

        agent('You are helpful.')->prompt(
            'What is in this document?',
            attachments: [new StoredDocument('notes.txt', 'docs')],
            provider: 'mistral',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

            return collect($content)->firstWhere('type', 'document_url') === [
                'type' => 'document_url',
                'document_url' => 'data:text/plain;base64,' . base64_encode('stored text contents'),
                'document_name' => 'notes.txt',
            ];
        });
    }

    public function testUploadedDocumentMapsToAnInlineDocumentUrl(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

        $upload = UploadedFile::fake()->createWithContent('notes.txt', 'uploaded text contents');

        agent('You are helpful.')->prompt(
            'What is in this document?',
            attachments: [$upload],
            provider: 'mistral',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

            return collect($content)->firstWhere('type', 'document_url') === [
                'type' => 'document_url',
                'document_url' => 'data:text/plain;base64,' . base64_encode('uploaded text contents'),
                'document_name' => 'notes.txt',
            ];
        });
    }

    public function testUploadedAvifImageMapsToAnImageUrl(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

        $upload = UploadedFile::fake()->createWithContent('shot.avif', 'avif-bytes');

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$upload],
            provider: 'mistral',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

            return collect($content)->firstWhere('type', 'image_url') === [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:image/avif;base64,' . base64_encode('avif-bytes')],
            ];
        });
    }

    public function testDocumentWithoutANameOrMimeTypeFallsBackToAPdfDataUri(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

        agent('You are helpful.')->prompt(
            'What is in this document?',
            attachments: [Document::fromString('nameless contents')],
            provider: 'mistral',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

            return collect($content)->firstWhere('type', 'document_url') === [
                'type' => 'document_url',
                'document_url' => 'data:application/pdf;base64,' . base64_encode('nameless contents'),
                'document_name' => 'document',
            ];
        });
    }

    public function testUnsupportedAttachmentTypeThrows(): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mistral only supports image and document attachments. Unsupported attachment type [' . LocalAudio::class . '].');

        agent('You are helpful.')->prompt(
            'What is in this recording?',
            attachments: [new LocalAudio(__DIR__ . '/../../../Fixtures/audio.mp3')],
            provider: 'mistral',
        );
    }

    public function testSystemInstructionsAreInMessagesArray(): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);

        (new AssistantAgent)->prompt('Hi', provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains((string) $systemMsg['content'], 'helpful assistant');
        });
    }
}
