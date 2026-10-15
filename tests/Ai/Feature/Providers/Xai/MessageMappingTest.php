<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Xai;

use Hypervel\Ai\AgentUserInteraction\AgentUserInteraction;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Files\RemoteDocument;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\Xai\XaiGateway;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\XaiHelpers;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class MessageMappingTest extends TestCase
{
    use XaiHelpers;

    /**
     * Configure the xAI provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.xai.key', 'test-key');
    }

    public function testUserMessageMapsToResponsesApiFormat(): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);

        (new AssistantAgent)->prompt('What is Hypervel?', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['input'])->firstWhere('role', 'user');

            return $userMsg !== null
                && collect($userMsg['content'])->contains(
                    fn ($c): bool => ($c['type'] ?? '') === 'input_text' && $c['text'] === 'What is Hypervel?'
                );
        });
    }

    public function testToolCallFollowUpUsesPreviousResponseId(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'xai');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

        $this->assertArrayHasKey('previous_response_id', $followUpBody);
        $this->assertNotEmpty($followUpBody['previous_response_id']);

        $hasToolOutput = collect($followUpBody['input'])->contains(
            fn ($item): bool => ($item['type'] ?? '') === 'function_call_output'
        );

        $this->assertTrue($hasToolOutput, 'Follow-up should include function_call_output');
    }

    public function testFrontendToolHistoryKeepsPairedCallIds(): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);

        $history = AgentUserInteraction::fromMessages([
            ['id' => 'assistant-1', 'role' => 'assistant', 'toolCalls' => [
                ['id' => 'weather-1', 'type' => 'function', 'function' => ['name' => 'getWeather', 'arguments' => '{"city":"Lisbon"}']],
            ]],
            ['id' => 'result-1', 'role' => 'tool', 'toolCallId' => 'weather-1', 'content' => 'Sunny'],
        ]);

        agent(messages: $history)->prompt('Summarize.', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $input = collect(json_decode($request->body(), true)['input']);

            return $input->firstWhere('type', 'function_call')['call_id'] === 'weather-1'
                && $input->firstWhere('type', 'function_call_output')['call_id'] === 'weather-1';
        });

        $this->app->make(XaiGateway::class)->generateTextStep(
            Ai::textProvider('xai'),
            'grok-4',
            null,
            $history,
            [],
            null,
            null,
            null,
            new StepContext(continuationToken: 'previous-response'),
        );

        $body = json_decode(Http::recorded()->last()[0]->body(), true);
        $this->assertSame('previous-response', $body['previous_response_id']);
        $this->assertSame('weather-1', $body['input'][0]['call_id']);
    }

    public function testRemoteImageAttachmentMapsToInputImage(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

        $image = new RemoteImage('https://example.com/image.png');

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'xai',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['input'])->firstWhere('role', 'user');

            $imageBlock = collect($userMsg['content'])->firstWhere('type', 'input_image');

            return $imageBlock !== null
                && $imageBlock['image_url'] === 'https://example.com/image.png';
        });
    }

    public function testBase64ImageAttachmentMapsToDataUri(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

        $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [$image],
            provider: 'xai',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['input'])->firstWhere('role', 'user');

            $imageBlock = collect($userMsg['content'])->firstWhere('type', 'input_image');

            return $imageBlock !== null
                && str_starts_with((string) $imageBlock['image_url'], 'data:image/png;base64,');
        });
    }

    public function testLocalImageAttachmentWithoutExplicitMimeTypeDetectsMimeFromFile(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

        agent('You are helpful.')->prompt(
            'What is in this image?',
            attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
            provider: 'xai',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['input'])->firstWhere('role', 'user');
            $imageBlock = collect($userMsg['content'])->firstWhere('type', 'input_image');

            return $imageBlock !== null
                && str_starts_with((string) $imageBlock['image_url'], 'data:image/png;base64,')
                && ! str_contains((string) $imageBlock['image_url'], 'data:;base64,');
        });
    }

    public function testRemoteDocumentMapsToInputFile(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

        $document = new RemoteDocument('https://example.com/report.pdf');

        agent('You are helpful.')->prompt(
            'What is in this document?',
            attachments: [$document],
            provider: 'xai',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['input'])->firstWhere('role', 'user');

            $fileBlock = collect($userMsg['content'])->firstWhere('type', 'input_file');

            return $fileBlock !== null
                && $fileBlock['file_url'] === 'https://example.com/report.pdf';
        });
    }

    public function testBase64DocumentWithoutAnExplicitNameFallsBackToAMimeBasedFilename(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

        $document = new Base64Document(base64_encode('fake-pdf-data'), 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this document?',
            attachments: [$document],
            provider: 'xai',
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['input'])->firstWhere('role', 'user');

            $fileBlock = collect($userMsg['content'])->firstWhere('type', 'input_file');

            return $fileBlock !== null
                && $fileBlock['filename'] === 'document.pdf';
        });
    }

    public function testReasoningBlocksAreInterleavedWithAssociatedToolCallsOnAssistantReplay(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('hi')]);

        agent(
            instructions: 'Hi.',
            messages: [
                new UserMessage('search'),
                new AssistantMessage('Searching.', collect([
                    new ToolCall(
                        id: 'call_1',
                        name: 'FixedNumberGenerator',
                        arguments: ['q' => 'foo'],
                        resultId: 'call_1',
                        reasoningId: 'rs_1',
                        reasoningSummary: [],
                    ),
                    new ToolCall(
                        id: 'call_2',
                        name: 'FixedNumberGenerator',
                        arguments: ['q' => 'bar'],
                        resultId: 'call_2',
                        reasoningId: 'rs_2',
                        reasoningSummary: [],
                    ),
                    new ToolCall(
                        id: 'call_3',
                        name: 'FixedNumberGenerator',
                        arguments: ['q' => 'baz'],
                        resultId: 'call_3',
                    ),
                ])),
                new ToolResultMessage(collect([
                    new ToolResult(
                        id: 'call_1',
                        name: 'FixedNumberGenerator',
                        arguments: ['q' => 'foo'],
                        result: '42',
                        resultId: 'call_1',
                    ),
                ])),
                new UserMessage('thanks'),
            ],
            tools: [(new ToolUsingAgent(fixed: true))->tools()[0]],
        )->prompt('', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $input = $body['input'];

            $rs1Index = collect($input)->search(fn ($i): bool => ($i['type'] ?? '') === 'reasoning' && ($i['id'] ?? '') === 'rs_1');
            $call1Index = collect($input)->search(fn ($i): bool => ($i['id'] ?? '') === 'call_1');
            $rs2Index = collect($input)->search(fn ($i): bool => ($i['type'] ?? '') === 'reasoning' && ($i['id'] ?? '') === 'rs_2');
            $call2Index = collect($input)->search(fn ($i): bool => ($i['id'] ?? '') === 'call_2');
            $call3Index = collect($input)->search(fn ($i): bool => ($i['id'] ?? '') === 'call_3');

            return $rs1Index !== false
                && $call1Index !== false
                && $rs1Index + 1 === $call1Index
                && $rs2Index !== false
                && $call2Index !== false
                && $rs2Index + 1 === $call2Index
                && $call3Index !== false;
        });
    }

    public function testNumericArgumentNamesRemainObjectsForLinkedAndUnlinkedToolCalls(): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);

        agent(messages: [new AssistantMessage('', collect([
            new ToolCall('linked', 'Lookup', ['0' => 'first'], reasoningId: 'reasoning'),
            new ToolCall('unlinked', 'Lookup', ['0' => 'second']),
        ]))])->prompt('Continue', provider: 'xai');

        $calls = collect(Http::recorded()[0][0]['input'])->where('type', 'function_call')->values();
        $this->assertSame('{"0":"first"}', $calls[0]['arguments']);
        $this->assertSame('{"0":"second"}', $calls[1]['arguments']);
    }

    public function testSystemInstructionsAreInInputArray(): void
    {
        Http::fake(['*' => $this->fakeTextResponse()]);

        (new AssistantAgent)->prompt('Hi', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            $systemMsg = collect($body['input'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains((string) $systemMsg['content'], 'helpful assistant');
        });
    }
}
