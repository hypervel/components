<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenAiResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenAiToolCallResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class MessageMappingTest extends TestCase
{
    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testUserMessageMapsToOpenaiFormat(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'What is Hypervel?',
            provider: 'openai',
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
            'api.openai.com/*' => Http::sequence([
                fakeOpenAiToolCallResponse(),
                fakeOpenAiResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'openai',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

        $this->assertArrayHasKey('previous_response_id', $followUpBody);
        $this->assertSame('resp_tool_123', $followUpBody['previous_response_id']);

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

    public function testBase64PdfDocumentMapsToInputFile(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse('I see a PDF'),
        ]);

        $pdf = new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this PDF?',
            attachments: [$pdf],
            provider: 'openai',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['input'])->firstWhere('role', 'user');
            $content = $userMessage['content'];

            $fileBlock = collect($content)->firstWhere('type', 'input_file');

            return $fileBlock !== null
                && str_contains((string) $fileBlock['file_data'], 'application/pdf')
                && str_contains((string) $fileBlock['file_data'], base64_encode('fake-pdf-content'));
        });
    }

    public function testNamelessTextDocumentFallsBackToDerivedFilename(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse(),
        ]);

        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [Document::fromString('hello world', 'text/plain')],
            provider: 'openai',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['input'])->firstWhere('role', 'user');
            $fileBlock = collect($userMessage['content'])->firstWhere('type', 'input_file');

            return $fileBlock !== null
                && ($fileBlock['filename'] ?? null) === 'document.txt';
        });
    }

    public function testUploadedPdfFileMapsToInputFile(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse('I see a PDF'),
        ]);

        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this file?',
            attachments: [$file],
            provider: 'openai',
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

    public function testLocalImageAttachmentWithoutExplicitMimeTypeDetectsMimeFromFile(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse('I see an image'),
        ]);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
            provider: 'openai',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['input'])->firstWhere('role', 'user');
            $imageBlock = collect($userMessage['content'])->firstWhere('type', 'input_image');

            return $imageBlock !== null
                && str_starts_with((string) $imageBlock['image_url'], 'data:image/png;base64,')
                && ! str_contains((string) $imageBlock['image_url'], 'data:;base64,');
        });
    }

    public function testEmptyToolArgumentsSerializeAsObjectStringOnAssistantReplay(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse('hi'),
        ]);

        agent(
            instructions: 'Hi.',
            messages: [
                new UserMessage('list'),
                new AssistantMessage('Listing.', collect([
                    new ToolCall(
                        id: 'call_empty',
                        name: 'FixedNumberGenerator',
                        arguments: [],
                        resultId: 'call_empty',
                    ),
                ])),
                new ToolResultMessage(collect([
                    new ToolResult(
                        id: 'call_empty',
                        name: 'FixedNumberGenerator',
                        arguments: [],
                        result: '42',
                        resultId: 'call_empty',
                    ),
                ])),
                new UserMessage('thanks'),
            ],
            tools: [(new ToolUsingAgent(fixed: true))->tools()[0]],
        )->prompt('', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $functionCall = collect($body['input'] ?? [])
                ->firstWhere('type', 'function_call');

            return $functionCall && $functionCall['arguments'] === '{}';
        });
    }

    #[DataProvider('toolArguments')]
    public function testNonEmptyToolArgumentsPreserveShapeOnAssistantReplay(array $arguments, string $encodedArguments): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse('hi'),
        ]);

        agent(
            instructions: 'Hi.',
            messages: [
                new UserMessage('search'),
                new AssistantMessage('Searching.', collect([
                    new ToolCall(
                        id: 'call_args',
                        name: 'FixedNumberGenerator',
                        arguments: $arguments,
                        resultId: 'call_args',
                    ),
                ])),
                new ToolResultMessage(collect([
                    new ToolResult(
                        id: 'call_args',
                        name: 'FixedNumberGenerator',
                        arguments: $arguments,
                        result: '42',
                        resultId: 'call_args',
                    ),
                ])),
                new UserMessage('thanks'),
            ],
            tools: [(new ToolUsingAgent(fixed: true))->tools()[0]],
        )->prompt('', provider: 'openai');

        Http::assertSent(function (Request $request) use ($encodedArguments): bool {
            $body = json_decode($request->body(), true);
            $functionCall = collect($body['input'] ?? [])
                ->firstWhere('type', 'function_call');

            return $functionCall && $functionCall['arguments'] === $encodedArguments;
        });
    }

    /**
     * Provide named and numeric tool argument keys.
     */
    public static function toolArguments(): array
    {
        return [
            'named key' => [['query' => 'test'], '{"query":"test"}'],
            'numeric key' => [[0 => 'test'], '{"0":"test"}'],
        ];
    }

    public function testReasoningBlocksAreInterleavedWithAssociatedToolCallsOnAssistantReplay(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse('hi'),
        ]);

        agent(
            instructions: 'Hi.',
            messages: [
                new UserMessage('search'),
                new AssistantMessage('Searching.', collect([
                    new ToolCall(
                        id: 'fc_1',
                        name: 'FixedNumberGenerator',
                        arguments: ['q' => 'foo'],
                        resultId: 'call_1',
                        reasoningId: 'rs_1',
                        reasoningSummary: [],
                    ),
                    new ToolCall(
                        id: 'fc_2',
                        name: 'FixedNumberGenerator',
                        arguments: ['q' => 'bar'],
                        resultId: 'call_2',
                        reasoningId: 'rs_2',
                        reasoningSummary: [],
                    ),
                    new ToolCall(
                        id: 'fc_3',
                        name: 'FixedNumberGenerator',
                        arguments: ['q' => 'baz'],
                        resultId: 'call_3',
                    ),
                ])),
                new ToolResultMessage(collect([
                    new ToolResult(
                        id: 'fc_1',
                        name: 'FixedNumberGenerator',
                        arguments: ['q' => 'foo'],
                        result: '42',
                        resultId: 'call_1',
                    ),
                ])),
                new UserMessage('thanks'),
            ],
            tools: [(new ToolUsingAgent(fixed: true))->tools()[0]],
        )->prompt('', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $input = $body['input'];

            $firstReasoningIndex = collect($input)->search(fn (array $item): bool => ($item['type'] ?? '') === 'reasoning' && ($item['id'] ?? '') === 'rs_1');
            $firstCallIndex = collect($input)->search(fn (array $item): bool => ($item['id'] ?? '') === 'fc_1');
            $secondReasoningIndex = collect($input)->search(fn (array $item): bool => ($item['type'] ?? '') === 'reasoning' && ($item['id'] ?? '') === 'rs_2');
            $secondCallIndex = collect($input)->search(fn (array $item): bool => ($item['id'] ?? '') === 'fc_2');
            $thirdCall = collect($input)->first(fn (array $item): bool => ($item['call_id'] ?? '') === 'call_3');

            return $firstReasoningIndex !== false
                && $firstCallIndex !== false
                && $firstReasoningIndex + 1 === $firstCallIndex
                && $secondReasoningIndex !== false
                && $secondCallIndex !== false
                && $secondReasoningIndex + 1 === $secondCallIndex
                // A call with no reasoning of its own goes out without its item id, which the API only accepts beside a reasoning item...
                && $thirdCall !== null
                && ! array_key_exists('id', $thirdCall);
        });
    }

    public function testAToolCallAnotherProviderMadeReplaysWithoutAnItemIdOpenaiWouldReject(): void
    {
        Http::fake(['api.openai.com/*' => fakeOpenAiResponse('hi')]);

        agent(
            instructions: 'Hi.',
            messages: [
                new UserMessage('number'),
                new AssistantMessage('', collect([new ToolCall('toolu_01ABC', 'FixedNumberGenerator', [], 'toolu_01ABC')])),
                new ToolResultMessage(collect([new ToolResult('toolu_01ABC', 'FixedNumberGenerator', [], '72019', 'toolu_01ABC')])),
                new UserMessage('again'),
            ],
            tools: [(new ToolUsingAgent(fixed: true))->tools()[0]],
        )->prompt('', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $call = collect(json_decode($request->body(), true)['input'])->firstWhere('type', 'function_call');

            return ! array_key_exists('id', $call) && $call['call_id'] === 'toolu_01ABC';
        });
    }

    public function testImageAttachmentProviderOptionsAreForwardedToTheContentPart(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse('I see an image'),
        ]);

        $image = (new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png'))
            ->withProviderOptions(['detail' => 'low']);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'openai',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['input'])->firstWhere('role', 'user');
            $imageBlock = collect($userMessage['content'])->firstWhere('type', 'input_image');

            return $imageBlock !== null
                && ($imageBlock['detail'] ?? null) === 'low'
                && str_starts_with((string) $imageBlock['image_url'], 'data:image/png;base64,');
        });
    }

    public function testDocumentAttachmentProviderOptionsAreForwardedToTheContentPart(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse('I see a document'),
        ]);

        $document = Document::fromString('hello world', 'text/plain')
            ->withProviderOptions(['detail' => 'high']);

        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [$document],
            provider: 'openai',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['input'])->firstWhere('role', 'user');
            $fileBlock = collect($userMessage['content'])->firstWhere('type', 'input_file');

            return $fileBlock !== null
                && ($fileBlock['detail'] ?? null) === 'high'
                && str_contains((string) $fileBlock['file_data'], base64_encode('hello world'));
        });
    }

    public function testAttachmentProviderOptionsResolveFromAClosureScopedToTheProvider(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse('I see an image'),
        ]);

        $image = (new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png'))
            ->withProviderOptions(fn (Lab $provider): array => match ($provider) {
                Lab::OpenAI => ['detail' => 'low'],
                default => [],
            });

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'openai',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['input'])->firstWhere('role', 'user');
            $imageBlock = collect($userMessage['content'])->firstWhere('type', 'input_image');

            return $imageBlock !== null
                && ($imageBlock['detail'] ?? null) === 'low';
        });
    }

    public function testAttachmentsWithoutProviderOptionsMapUnchanged(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse('I see an image'),
        ]);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
            provider: 'openai',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['input'])->firstWhere('role', 'user');
            $imageBlock = collect($userMessage['content'])->firstWhere('type', 'input_image');

            return $imageBlock !== null
                && ! array_key_exists('detail', $imageBlock);
        });
    }

    public function testProviderOptionsCannotOverwriteTheMappedStructuralKeys(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse('I see an image'),
        ]);

        $image = (new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png'))
            ->withProviderOptions(['type' => 'input_text', 'detail' => 'low']);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'openai',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['input'])->firstWhere('role', 'user');
            $imageBlock = collect($userMessage['content'])->firstWhere('type', 'input_image');

            return $imageBlock !== null
                && $imageBlock['type'] === 'input_image'
                && ($imageBlock['detail'] ?? null) === 'low'
                && str_starts_with((string) $imageBlock['image_url'], 'data:image/png;base64,');
        });
    }

    public function testSystemInstructionsAreInInputArrayAsSystemRole(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'openai',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $systemMessage = collect($body['input'])->firstWhere('role', 'system');

            return $systemMessage !== null
                && str_contains((string) $systemMessage['content'], 'helpful assistant');
        });
    }
}
