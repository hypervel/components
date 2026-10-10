<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Bedrock;

use Aws\Exception\EventStreamDataException;
use Aws\MockHandler;
use Aws\Result;
use Generator;
use Hypervel\Ai\Exceptions\StreamErrorException;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\TextGenerationLoop;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\JsonSchema\JsonSchema;
use Hypervel\Tests\Ai\Fixtures\BedrockHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StreamingTest extends TestCase
{
    use BedrockHelpers;

    public function testStreamingHandlesReasoningAndTextBlocks(): void
    {
        $client = $this->fakeBedrockStream([
            $this->contentBlockStart(0),
            $this->contentBlockDelta(0, ['reasoningContent' => ['text' => 'Let me think']]),
            $this->contentBlockDelta(0, ['reasoningContent' => ['text' => ' carefully']]),
            $this->contentBlockDelta(0, ['reasoningContent' => ['signature' => 'sig-abc']]),
            $this->contentBlockStop(0),
            $this->contentBlockStart(1),
            $this->contentBlockDelta(1, ['text' => 'Hello ']),
            $this->contentBlockDelta(1, ['text' => 'world']),
            $this->contentBlockStop(1),
            $this->messageStop('end_turn'),
            ['metadata' => ['usage' => ['inputTokens' => 7, 'outputTokens' => 5]]],
        ]);

        $gateway = $this->gatewayWithClient($client);

        $events = iterator_to_array(
            (new TextGenerationLoop($gateway))->stream('inv-1', $this->bedrockProvider(), 'anthropic.claude-opus-4-7-v1:0', null),
            preserve_keys: false,
        );

        $this->assertInstanceOf(StreamStart::class, $events[0]);
        $this->assertInstanceOf(ReasoningStart::class, $events[1]);
        $this->assertInstanceOf(ReasoningDelta::class, $events[2]);
        $this->assertSame('Let me think', $events[2]->delta);
        $this->assertInstanceOf(ReasoningDelta::class, $events[3]);
        $this->assertSame(' carefully', $events[3]->delta);
        $this->assertInstanceOf(ReasoningEnd::class, $events[4]);
        $this->assertInstanceOf(TextStart::class, $events[5]);
        $this->assertInstanceOf(TextDelta::class, $events[6]);
        $this->assertSame('Hello ', $events[6]->delta);
        $this->assertInstanceOf(TextDelta::class, $events[7]);
        $this->assertSame('world', $events[7]->delta);
        $this->assertInstanceOf(TextEnd::class, $events[8]);
        $this->assertInstanceOf(StreamEnd::class, $events[9]);
        $this->assertSame(7, $events[9]->usage->inputTokens);
        $this->assertSame(5, $events[9]->usage->outputTokens);
    }

    public function testEachTextBlockGetsADistinctMessageId(): void
    {
        $client = $this->fakeBedrockStream([
            $this->contentBlockStart(0),
            $this->contentBlockDelta(0, ['text' => 'first']),
            $this->contentBlockStop(0),
            $this->contentBlockStart(1),
            $this->contentBlockDelta(1, ['text' => 'second']),
            $this->contentBlockStop(1),
            $this->messageStop('end_turn'),
        ]);

        $gateway = $this->gatewayWithClient($client);

        $events = iterator_to_array(
            (new TextGenerationLoop($gateway))->stream('inv-1', $this->bedrockProvider(), 'anthropic.claude-opus-4-7-v1:0', null),
            preserve_keys: false,
        );

        $textStarts = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof TextStart));

        $this->assertCount(2, $textStarts);
        $this->assertNotSame($textStarts[0]->messageId, $textStarts[1]->messageId);
    }

    public function testStreamingRoundTripsReasoningBlockOnFollowUpToolStep(): void
    {
        $mock = new MockHandler([
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['reasoningContent' => ['text' => 'I should call the tool']]),
                $this->contentBlockDelta(0, ['reasoningContent' => ['signature' => 'sig-xyz']]),
                $this->contentBlockStop(0),
                $this->contentBlockStart(1, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(1, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(1),
                $this->messageStop('tool_use'),
            ]]),
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
            ]]),
        ]);

        $gateway = $this->gatewayWithClient($this->bedrockClient($mock));

        iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-opus-4-7-v1:0',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $secondCall = $mock->getLastCommand()->toArray();
        $assistantTurn = $secondCall['messages'][0];

        $this->assertSame('assistant', $assistantTurn['role']);
        $this->assertSame([
            'reasoningContent' => [
                'reasoningText' => [
                    'text' => 'I should call the tool',
                    'signature' => 'sig-xyz',
                ],
            ],
        ], $assistantTurn['content'][0]);
        $this->assertSame('t1', $assistantTurn['content'][1]['toolUse']['toolUseId']);
        $this->assertSame('FixedNumberGenerator', $assistantTurn['content'][1]['toolUse']['name']);
    }

    public function testStreamingDoesNotRoundTripEmptyTextBlockOnFollowUpToolStep(): void
    {
        $mock = new MockHandler([
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => '']),
                $this->contentBlockStop(0),
                $this->contentBlockStart(1, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(1, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(1),
                $this->messageStop('tool_use'),
            ]]),
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
            ]]),
        ]);

        $gateway = $this->gatewayWithClient($this->bedrockClient($mock));

        iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-sonnet-5',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $assistantTurn = $mock->getLastCommand()->toArray()['messages'][0];

        $this->assertCount(1, $assistantTurn['content']);
        $this->assertArrayHasKey('toolUse', $assistantTurn['content'][0]);
    }

    public function testStreamingPreservesEmptyTextBlocksBetweenReasoningBlocksOnFollowUpToolStep(): void
    {
        $mock = new MockHandler([
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['reasoningContent' => ['text' => 'I should call the tool']]),
                $this->contentBlockDelta(0, ['reasoningContent' => ['signature' => 'sig-1']]),
                $this->contentBlockStop(0),
                $this->contentBlockStart(1),
                $this->contentBlockDelta(1, ['text' => '']),
                $this->contentBlockStop(1),
                $this->contentBlockStart(2),
                $this->contentBlockDelta(2, ['reasoningContent' => ['text' => 'I need the result']]),
                $this->contentBlockDelta(2, ['reasoningContent' => ['signature' => 'sig-2']]),
                $this->contentBlockStop(2),
                $this->contentBlockStart(3, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(3, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(3),
                $this->messageStop('tool_use'),
            ]]),
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
            ]]),
        ]);

        $gateway = $this->gatewayWithClient($this->bedrockClient($mock));

        iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-sonnet-5',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $assistantTurn = $mock->getLastCommand()->toArray()['messages'][0];

        $this->assertCount(4, $assistantTurn['content']);
        $this->assertSame([
            'reasoningContent' => ['reasoningText' => ['text' => 'I should call the tool', 'signature' => 'sig-1']],
        ], $assistantTurn['content'][0]);
        $this->assertSame(['text' => ''], $assistantTurn['content'][1]);
        $this->assertSame([
            'reasoningContent' => ['reasoningText' => ['text' => 'I need the result', 'signature' => 'sig-2']],
        ], $assistantTurn['content'][2]);
        $this->assertSame('t1', $assistantTurn['content'][3]['toolUse']['toolUseId']);
        $this->assertSame('FixedNumberGenerator', $assistantTurn['content'][3]['toolUse']['name']);
    }

    #[DataProvider('emptyReasoning')]
    public function testStreamingDoesNotRoundTripEmptyReasoningBlockOnFollowUpToolStep(array $delta): void
    {
        $mock = new MockHandler([
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, $delta),
                $this->contentBlockStop(0),
                $this->contentBlockStart(1, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(1, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(1),
                $this->messageStop('tool_use'),
            ]]),
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
            ]]),
        ]);

        $gateway = $this->gatewayWithClient($this->bedrockClient($mock));

        iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-sonnet-5',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $assistantTurn = $mock->getLastCommand()->toArray()['messages'][0];

        $this->assertCount(1, $assistantTurn['content']);
        $this->assertArrayHasKey('toolUse', $assistantTurn['content'][0]);
    }

    /**
     * Provide empty reasoning blocks.
     */
    public static function emptyReasoning(): array
    {
        return [
            'text' => [['reasoningContent' => ['text' => '']]],
            'signature' => [['reasoningContent' => ['signature' => '']]],
            'redacted content' => [['reasoningContent' => ['redactedContent' => '']]],
        ];
    }

    public function testStreamingOmitsEmptyReasoningSignatureOnFollowUpToolStep(): void
    {
        $mock = new MockHandler([
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['reasoningContent' => ['text' => 'I should call the tool']]),
                $this->contentBlockStop(0),
                $this->contentBlockStart(1, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(1, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(1),
                $this->messageStop('tool_use'),
            ]]),
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
            ]]),
        ]);

        $gateway = $this->gatewayWithClient($this->bedrockClient($mock));

        iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'openai.gpt-oss-120b-1:0',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $assistantTurn = $mock->getLastCommand()->toArray()['messages'][0];

        $this->assertSame([
            'reasoningContent' => [
                'reasoningText' => ['text' => 'I should call the tool'],
            ],
        ], $assistantTurn['content'][0]);
    }

    public function testStreamingRoundTripsRedactedReasoningBlock(): void
    {
        $mock = new MockHandler([
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['reasoningContent' => ['redactedContent' => 'redacted-bytes']]),
                $this->contentBlockStop(0),
                $this->contentBlockStart(1, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(1, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(1),
                $this->messageStop('tool_use'),
            ]]),
            new Result(['stream' => [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
            ]]),
        ]);

        $gateway = $this->gatewayWithClient($this->bedrockClient($mock));

        iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-opus-4-7-v1:0',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $secondCall = $mock->getLastCommand()->toArray();
        $assistantTurn = $secondCall['messages'][0];

        $this->assertSame([
            'reasoningContent' => ['redactedContent' => 'redacted-bytes'],
        ], $assistantTurn['content'][0]);
    }

    public function testStreamServiceErrorsKeepTheirDetails(): void
    {
        $stream = (function (): Generator {
            yield ['messageStart' => ['role' => 'assistant']];

            throw new EventStreamDataException('throttlingException', 'Too many requests');
        })();
        $client = $this->bedrockClient(new MockHandler([new Result(['stream' => $stream])]));
        $events = [];
        $caught = null;

        try {
            foreach ((new TextGenerationLoop($this->gatewayWithClient($client)))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-opus-4-7-v1:0',
                null,
            ) as $event) {
                $events[] = $event;
            }
        } catch (StreamErrorException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(StreamErrorException::class, $caught);
        $error = end($events);
        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('throttlingException', $error->type);
        $this->assertSame('Too many requests', $error->message);
        $this->assertSame($error, $caught->error);
    }

    public function testCleanEofWithoutMessageStopDoesNotCompleteTheTurn(): void
    {
        $client = $this->fakeBedrockStream([
            $this->contentBlockDelta(0, ['text' => 'Partial answer']),
            $this->contentBlockStop(0),
        ]);
        $caught = null;

        try {
            iterator_to_array((new TextGenerationLoop($this->gatewayWithClient($client)))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-opus-4-7-v1:0',
                null,
            ));
        } catch (StreamErrorException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(StreamErrorException::class, $caught);
        $this->assertSame('incomplete_stream', $caught->error->type);
    }

    #[DataProvider('structuredResponses')]
    public function testStructuredOutputProducesCompleteTextEventsAndStepData(string $model, array $events): void
    {
        $client = $this->fakeBedrockStream($events);
        $stream = $this->gatewayWithClient($client)->generateStreamStep(
            'inv-1',
            $this->bedrockProvider(),
            $model,
            null,
            [],
            [],
            ['answer' => JsonSchema::integer()],
            null,
            null,
            new StepContext,
        );
        $events = iterator_to_array($stream, false);

        $this->assertSame(['answer' => 42], $stream->getReturn()->structured);
        $this->assertSame('{"answer":42}', $stream->getReturn()->text);
        $this->assertSame('{"answer":42}', TextDelta::combine($events));
        $this->assertSame([StreamStart::class, TextStart::class, TextDelta::class, TextEnd::class], array_map(
            static fn (StreamEvent $event): string => $event::class,
            $events,
        ));
        $this->assertSame($events[1]->messageId, $events[2]->messageId);
        $this->assertSame($events[1]->messageId, $events[3]->messageId);
    }

    /**
     * Provide structured responses from text and synthetic tools.
     */
    public static function structuredResponses(): array
    {
        return [
            'JSON text' => ['anthropic.claude-sonnet-5-5', [
                ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['text' => '{"answer":42}']]],
                ['contentBlockStop' => ['contentBlockIndex' => 0]],
                ['messageStop' => ['stopReason' => 'end_turn']],
            ]],
            'synthetic tool' => ['anthropic.claude-opus-4-7-v1:0', [
                ['contentBlockStart' => ['contentBlockIndex' => 0, 'start' => ['toolUse' => [
                    'toolUseId' => 'structured-1', 'name' => 'structured_output',
                ]]]],
                ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['toolUse' => ['input' => '{"answer":42}']]]],
                ['contentBlockStop' => ['contentBlockIndex' => 0]],
                ['messageStop' => ['stopReason' => 'tool_use']],
            ]],
        ];
    }
}
