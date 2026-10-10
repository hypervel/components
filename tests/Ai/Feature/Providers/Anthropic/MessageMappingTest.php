<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Gateway\Anthropic\AnthropicGateway;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use ReflectionClass;
use stdClass;

use function Hypervel\Ai\agent;

class MessageMappingTest extends TestCase
{
    use AnthropicHelpers;

    public function testUserMessageMapsToAnthropicFormat(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'What is Hypervel?',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $messages = $request->data()['messages'];
            $userMessage = $messages[0];

            return $userMessage['role'] === 'user'
                && $userMessage['content'][0]['type'] === 'text'
                && $userMessage['content'][0]['text'] === 'What is Hypervel?';
        });
    }

    public function testToolResultFollowUpMapsAssistantAndToolResultMessages(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'anthropic',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpMessages = $recorded[1][0]->data()['messages'];

        $assistantMsg = null;
        $toolResultMsg = null;

        foreach ($followUpMessages as $msg) {
            if ($msg['role'] === 'assistant') {
                foreach ($msg['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'tool_use') {
                        $assistantMsg = $msg;
                    }
                }
            }

            if ($msg['role'] === 'user') {
                foreach ($msg['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        $toolResultMsg = $msg;
                    }
                }
            }
        }

        $this->assertNotNull($assistantMsg, 'Follow-up should include assistant message');
        $this->assertNotNull($toolResultMsg, 'Follow-up should include tool result message');

        $toolUseBlock = collect($assistantMsg['content'])->firstWhere('type', 'tool_use');
        $this->assertSame('FixedNumberGenerator', $toolUseBlock['name']);
        $this->assertArrayHasKey('input', $toolUseBlock);

        $toolResultBlock = collect($toolResultMsg['content'])->firstWhere('type', 'tool_result');
        $this->assertSame($toolUseBlock['id'], $toolResultBlock['tool_use_id']);
        $this->assertNotEmpty($toolResultBlock['content']);
    }

    public function testLocalImageAttachmentWithoutExplicitMimeTypeDetectsMimeFromFile(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('I see an image'),
        ]);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $content = $request->data()['messages'][0]['content'];
            $imageBlock = collect($content)->firstWhere('type', 'image');

            return $imageBlock !== null
                && $imageBlock['source']['type'] === 'base64'
                && $imageBlock['source']['media_type'] === 'image/png';
        });
    }

    public function testBase64PdfDocumentMapsToDocumentContentBlock(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('I see a PDF'),
        ]);

        $pdf = new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this PDF?',
            attachments: [$pdf],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $content = $request->data()['messages'][0]['content'];
            $docBlock = $content[0];

            return $docBlock['type'] === 'document'
                && $docBlock['source']['type'] === 'base64'
                && $docBlock['source']['media_type'] === 'application/pdf'
                && $docBlock['source']['data'] === base64_encode('fake-pdf-content');
        });
    }

    public function testBase64TextDocumentMapsToTextSourceBlock(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        $document = Document::fromString('hello world', 'text/plain');

        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [$document],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $docBlock = $request->data()['messages'][0]['content'][0];

            return $docBlock['type'] === 'document'
                && $docBlock['source']['type'] === 'text'
                && $docBlock['source']['media_type'] === 'text/plain'
                && $docBlock['source']['data'] === 'hello world';
        });
    }

    public function testStoredTextDocumentMapsToTextSourceBlock(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        Storage::fake('docs');
        Storage::disk('docs')->put('notes.txt', 'stored text contents');

        agent('You are helpful.')->prompt(
            'Analyze the attached record.',
            attachments: [Document::fromStorage('notes.txt', 'docs')],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $docBlock = $request->data()['messages'][0]['content'][0];

            return $docBlock['type'] === 'document'
                && $docBlock['source']['type'] === 'text'
                && $docBlock['source']['media_type'] === 'text/plain'
                && $docBlock['source']['data'] === 'stored text contents';
        });
    }

    public function testBase64TextDocumentThatIsNotPlainTextIsSentAsPlainText(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        $document = Document::fromString("email,state\na@b.it,ongoing\n", 'text/csv');

        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [$document],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $docBlock = $request->data()['messages'][0]['content'][0];

            return $docBlock['source']['type'] === 'text'
                && $docBlock['source']['media_type'] === 'text/plain'
                && $docBlock['source']['data'] === "email,state\na@b.it,ongoing\n";
        });
    }

    public function testLocalTextDocumentMapsToTextSourceBlock(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        $directory = ParallelTesting::tempDir('AnthropicMessageMappingTest');
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($directory);
        $filesystem->makeDirectory($directory, recursive: true);
        $path = $directory . '/notes.txt';
        file_put_contents($path, 'local text contents');

        try {
            agent('You are helpful.')->prompt(
                'Read this.',
                attachments: [Document::fromPath($path)],
                provider: 'anthropic',
            );

            Http::assertSent(function ($request): bool {
                $docBlock = $request->data()['messages'][0]['content'][0];

                return $docBlock['type'] === 'document'
                    && $docBlock['source']['type'] === 'text'
                    && str_starts_with((string) $docBlock['source']['media_type'], 'text/')
                    && $docBlock['source']['data'] === 'local text contents';
            });
        } finally {
            $filesystem->deleteDirectory($directory);
        }
    }

    public function testUploadedTextFileMapsToTextSourceBlock(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        $upload = UploadedFile::fake()->createWithContent('notes.txt', 'uploaded text contents');

        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [$upload],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $docBlock = $request->data()['messages'][0]['content'][0];

            return $docBlock['type'] === 'document'
                && $docBlock['source']['type'] === 'text'
                && $docBlock['source']['data'] === 'uploaded text contents';
        });
    }

    public function testUploadedTextFileThatIsNotPlainTextIsSentAsPlainText(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        $upload = UploadedFile::fake()->createWithContent('leads.csv', "email,state\na@b.it,ongoing\n");

        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [$upload],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $docBlock = $request->data()['messages'][0]['content'][0];

            return $docBlock['source']['type'] === 'text'
                && $docBlock['source']['media_type'] === 'text/plain'
                && $docBlock['source']['data'] === "email,state\na@b.it,ongoing\n";
        });
    }

    public function testJsonDocumentIsSentAsPlainText(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [Document::fromString('{"state":"ongoing"}', 'application/json')],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $docBlock = $request->data()['messages'][0]['content'][0];

            return $docBlock['source']['type'] === 'text'
                && $docBlock['source']['media_type'] === 'text/plain'
                && $docBlock['source']['data'] === '{"state":"ongoing"}';
        });
    }

    public function testDocumentThatIsNeitherPdfNorPlainTextIsRejectedBeforeSending(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        $document = Document::fromString("PK\x03\x04\x14\x00\x00\x00\x08\x00", 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->assertThrows(
            fn () => agent('You are helpful.')->prompt('Read this.', attachments: [$document], provider: 'anthropic'),
            InvalidArgumentException::class,
            'must be converted first',
        );

        Http::assertNothingSent();
    }

    public function testYamlDocumentIsSentAsPlainText(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [Document::fromString("state: ongoing\n", 'application/x-yaml')],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $docBlock = $request->data()['messages'][0]['content'][0];

            return $docBlock['source']['type'] === 'text'
                && $docBlock['source']['media_type'] === 'text/plain'
                && $docBlock['source']['data'] === "state: ongoing\n";
        });
    }

    public function testPdfDocumentWithoutAMimeTypeIsDetectedFromItsBytes(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        $pdf = "%PDF-1.4\n\x00binary";

        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [Document::fromString($pdf)],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) use ($pdf): bool {
            $docBlock = $request->data()['messages'][0]['content'][0];

            return $docBlock['source']['type'] === 'base64'
                && $docBlock['source']['media_type'] === 'application/pdf'
                && $docBlock['source']['data'] === base64_encode($pdf);
        });
    }

    public function testRemotePdfDocumentIsSentAsAUrlSource(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [Document::fromUrl('https://example.com/report.pdf')],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $docBlock = $request->data()['messages'][0]['content'][0];

            return $docBlock['source'] === ['type' => 'url', 'url' => 'https://example.com/report.pdf'];
        });
    }

    public function testRemoteTextDocumentIsFetchedAndInlinedBecauseAUrlSourceIsPdfOnly(): void
    {
        Http::fake([
            'example.com/*' => Http::response("email,state\na@b.it,ongoing\n", headers: ['Content-Type' => 'text/csv']),
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [Document::fromUrl('https://example.com/leads.csv')],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.anthropic.com/v1/messages') {
                return false;
            }

            $docBlock = $request->data()['messages'][0]['content'][0];

            return $docBlock['source']['type'] === 'text'
                && $docBlock['source']['media_type'] === 'text/plain'
                && $docBlock['source']['data'] === "email,state\na@b.it,ongoing\n";
        });
    }

    public function testStoredDocumentCarriesItsFilenameAsTheDocumentTitle(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        Storage::fake('docs');
        Storage::disk('docs')->put('leads.csv', "email,state\na@b.it,ongoing\n");

        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [Document::fromStorage('leads.csv', 'docs')],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            return ($request->data()['messages'][0]['content'][0]['title'] ?? null) === 'leads.csv';
        });
    }

    public function testUploadedPdfFileMapsToDocumentContentBlock(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('I see a PDF'),
        ]);

        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this file?',
            attachments: [$file],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $content = $request->data()['messages'][0]['content'];
            $docBlock = $content[0];

            return $docBlock['type'] === 'document'
                && $docBlock['source']['type'] === 'base64'
                && $docBlock['source']['media_type'] === 'application/pdf';
        });
    }

    public function testEmptyToolArgumentsSerializeAsObjectOnAssistantReplay(): void
    {
        $assistant = new AssistantMessage('Listing.', collect([
            new ToolCall(
                id: 'toolu_empty',
                name: 'ListTool',
                arguments: [],
            ),
        ]));

        $gateway = app(AnthropicGateway::class);
        $method = (new ReflectionClass($gateway))->getMethod('mapMessages');

        $mapped = $method->invoke($gateway, [$assistant]);
        $toolUse = collect($mapped[0]['content'])->firstWhere('type', 'tool_use');

        $this->assertInstanceOf(stdClass::class, $toolUse['input']);
        $this->assertEmpty(get_object_vars($toolUse['input']));
    }

    public function testNonEmptyToolArgumentsPreserveShapeOnAssistantReplay(): void
    {
        $assistant = new AssistantMessage('Searching.', collect([
            new ToolCall(
                id: 'toolu_args',
                name: 'SearchTool',
                arguments: ['query' => 'test'],
            ),
        ]));

        $gateway = app(AnthropicGateway::class);
        $method = (new ReflectionClass($gateway))->getMethod('mapMessages');

        $mapped = $method->invoke($gateway, [$assistant]);
        $toolUse = collect($mapped[0]['content'])->firstWhere('type', 'tool_use');

        $this->assertSame(['query' => 'test'], $toolUse['input']);
    }

    public function testNumericArgumentNamesEncodeAsObjectProperties(): void
    {
        $assistant = new AssistantMessage('Searching.', collect([
            new ToolCall('toolu_numeric', 'SearchTool', ['0' => 'test']),
        ]));
        $gateway = app(AnthropicGateway::class);
        $method = (new ReflectionClass($gateway))->getMethod('mapMessages');
        $mapped = $method->invoke($gateway, [$assistant]);
        $toolUse = collect($mapped[0]['content'])->firstWhere('type', 'tool_use');

        $this->assertSame('{"0":"test"}', json_encode($toolUse['input']));
    }

    public function testAssistantMessageWithReplayBlocksIsReplayedVerbatimPreservingOrder(): void
    {
        $contentBlocks = [
            ['type' => 'text', 'text' => 'Let me consult the advisor.'],
            [
                'type' => 'server_tool_use',
                'id' => 'srvtoolu_abc',
                'name' => 'advisor',
                'input' => [],
            ],
            [
                'type' => 'advisor_tool_result',
                'tool_use_id' => 'srvtoolu_abc',
                'content' => [
                    'type' => 'advisor_result',
                    'text' => 'Use a channel-based coordination pattern.',
                ],
            ],
            [
                'type' => 'tool_use',
                'id' => 'toolu_xyz',
                'name' => 'write_file',
                'input' => ['path' => 'worker.go'],
            ],
            ['type' => 'text', 'text' => "Here's the implementation."],
        ];

        $assistant = new AssistantMessage("Here's the implementation.", null, $contentBlocks);

        $gateway = app(AnthropicGateway::class);
        $method = (new ReflectionClass($gateway))->getMethod('mapMessages');

        $mapped = $method->invoke($gateway, [$assistant]);

        $this->assertCount(1, $mapped);
        $this->assertSame('assistant', $mapped[0]['role']);
        $this->assertSame([
            'text',
            'server_tool_use',
            'advisor_tool_result',
            'tool_use',
            'text',
        ], array_column($mapped[0]['content'], 'type'));

        $serverToolUse = collect($mapped[0]['content'])->firstWhere('type', 'server_tool_use');
        $this->assertInstanceOf(stdClass::class, $serverToolUse['input']);
    }

    public function testParsedResponsePopulatesReplayBlocksOnTheAssistantMessage(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'text', 'text' => 'Consulted the advisor.'],
                    [
                        'type' => 'server_tool_use',
                        'id' => 'srvtoolu_1',
                        'name' => 'advisor',
                        'input' => (object) [],
                    ],
                    [
                        'type' => 'advisor_tool_result',
                        'tool_use_id' => 'srvtoolu_1',
                        'content' => ['type' => 'advisor_result', 'text' => 'Proceed.'],
                    ],
                    ['type' => 'text', 'text' => 'Done.'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('hi', provider: 'anthropic');

        $assistant = $response->messages->whereInstanceOf(AssistantMessage::class)->first();
        $this->assertNotNull($assistant);
        $blocks = $assistant->replayBlocks;
        $this->assertCount(4, $blocks);
        $this->assertSame(['type' => 'text', 'text' => 'Consulted the advisor.'], $blocks[0]);
        $this->assertSame([
            'type' => 'server_tool_use',
            'id' => 'srvtoolu_1',
            'name' => 'advisor',
        ], array_intersect_key($blocks[1], array_flip(['type', 'id', 'name'])));
        $this->assertSame([
            'type' => 'advisor_tool_result',
            'tool_use_id' => 'srvtoolu_1',
            'content' => ['type' => 'advisor_result', 'text' => 'Proceed.'],
        ], $blocks[2]);
        $this->assertSame(['type' => 'text', 'text' => 'Done.'], $blocks[3]);
    }

    public function testAssistantMessageProducedByParserRoundTripsThroughMappingWithServerBlocksIntact(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'text', 'text' => 'Searching.'],
                    [
                        'type' => 'server_tool_use',
                        'id' => 'srvtoolu_1',
                        'name' => 'web_search',
                        'input' => (object) ['query' => 'hypervel ai'],
                    ],
                    [
                        'type' => 'web_search_tool_result',
                        'tool_use_id' => 'srvtoolu_1',
                        'content' => [['title' => 'Hypervel', 'url' => 'https://hypervel.org']],
                    ],
                    ['type' => 'text', 'text' => 'Found it.'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('search hypervel', provider: 'anthropic');
        $assistant = $response->messages->whereInstanceOf(AssistantMessage::class)->first();

        $gateway = app(AnthropicGateway::class);
        $method = (new ReflectionClass($gateway))->getMethod('mapMessages');

        $mapped = $method->invoke($gateway, [$assistant]);
        $content = $mapped[0]['content'];

        $this->assertCount(4, $content);
        $this->assertSame(['type' => 'text', 'text' => 'Searching.'], $content[0]);
        $this->assertSame([
            'type' => 'server_tool_use',
            'id' => 'srvtoolu_1',
            'name' => 'web_search',
        ], array_intersect_key($content[1], array_flip(['type', 'id', 'name'])));
        $this->assertInstanceOf(stdClass::class, $content[1]['input']);
        $this->assertSame(['query' => 'hypervel ai'], (array) $content[1]['input']);
        $this->assertSame([
            'type' => 'web_search_tool_result',
            'tool_use_id' => 'srvtoolu_1',
            'content' => [['title' => 'Hypervel', 'url' => 'https://hypervel.org']],
        ], $content[2]);
        $this->assertSame(['type' => 'text', 'text' => 'Found it.'], $content[3]);
    }

    public function testAssistantMessageWithoutReplayBlocksFallsBackToTextPlusToolCallsRebuild(): void
    {
        $assistant = new AssistantMessage('Hello');

        $gateway = app(AnthropicGateway::class);
        $method = (new ReflectionClass($gateway))->getMethod('mapMessages');

        $mapped = $method->invoke($gateway, [$assistant]);

        $this->assertSame('assistant', $mapped[0]['role']);
        $this->assertSame([
            ['type' => 'text', 'text' => 'Hello'],
        ], $mapped[0]['content']);
    }

    public function testThinkingAndRedactedThinkingBlocksArePreservedOnReplay(): void
    {
        $contentBlocks = [
            ['type' => 'thinking', 'thinking' => 'Considering options.', 'signature' => 'sig_1'],
            ['type' => 'redacted_thinking', 'data' => 'opaque'],
            ['type' => 'text', 'text' => 'Answer.'],
        ];

        $assistant = new AssistantMessage('Answer.', null, $contentBlocks);

        $gateway = app(AnthropicGateway::class);
        $method = (new ReflectionClass($gateway))->getMethod('mapMessages');

        $mapped = $method->invoke($gateway, [$assistant]);

        $this->assertSame($contentBlocks, $mapped[0]['content']);
    }

    public function testSystemInstructionsAreNotInMessagesArray(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            foreach ($body['messages'] as $message) {
                if ($message['role'] === 'system') {
                    return false;
                }
            }

            return isset($body['system']) && is_string($body['system']);
        });
    }

    public function testAnotherProviderReasoningOnAReplayedToolCallIsDroppedRatherThanRebuiltAsAThinkingBlock(): void
    {
        $assistant = new AssistantMessage('Checking.', collect([
            new ToolCall(
                id: 'toolu_1',
                name: 'getWeather',
                arguments: ['city' => 'Lisbon'],
                reasoningId: 'rs_1',
                reasoningSummary: [['type' => 'summary_text', 'text' => 'They want the weather.']],
            ),
        ]));

        $gateway = app(AnthropicGateway::class);
        $method = (new ReflectionClass($gateway))->getMethod('mapMessages');

        $mapped = $method->invoke($gateway, [$assistant]);

        $this->assertSame(['text', 'tool_use'], array_column($mapped[0]['content'], 'type'));
    }
}
