<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Generator;
use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Exceptions\StreamErrorException;
use Hypervel\Ai\Responses\Data\Citation as CitationData;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall as ToolCallData;
use Hypervel\Ai\Responses\Data\ToolResult as ToolResultData;
use Hypervel\Ai\Responses\Data\UrlCitation;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Streaming\Events\Citation;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\ProviderToolEvent;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\Streaming\Events\ToolApprovalRequest;
use Hypervel\Ai\Streaming\Events\ToolCall;
use Hypervel\Ai\Streaming\Events\ToolResult;
use Hypervel\Ai\Streaming\Protocols\VercelDataProtocol;
use Hypervel\Ai\Vercel\Vercel;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Tests\Ai\TestCase;
use RuntimeException;

/**
 * Collect the Vercel protocol's streamed parts.
 */
function vercelProtocolParts(array|Closure $events, ?string $messageId = null, ?VercelDataProtocol $protocol = null): array
{
    $stream = $events instanceof Closure ? $events : fn (): Generator => yield from $events;

    $response = (new StreamableAgentResponse('invocation-1', $stream, new Meta('anthropic', 'claude-sonnet-4-6')))
        ->usingProtocol($protocol ?? new VercelDataProtocol($messageId))
        ->toResponse(request());

    $output = '';

    ob_start(function (string $buffer) use (&$output): string {
        $output .= $buffer;

        return '';
    });

    try {
        $response->sendContent();
    } finally {
        ob_end_clean();
    }

    return collect(explode("\n\n", trim($output)))
        ->map(fn (string $frame): string => str_replace('data: ', '', $frame))
        ->map(fn (string $payload): array => $payload === '[DONE]' ? ['type' => 'done'] : json_decode($payload, true))
        ->all();
}

/**
 * Build the expected finish part.
 */
function vercelFinishPart(string $reason = 'stop', ?TextUsage $usage = null): array
{
    $usage ??= new TextUsage;

    return [
        'type' => 'finish',
        'finishReason' => $reason,
        'messageMetadata' => [
            'usage' => [
                'inputTokens' => $usage->inputTokens,
                'outputTokens' => $usage->outputTokens,
                'totalTokens' => $usage->inputTokens + $usage->outputTokens,
                'reasoningTokens' => $usage->reasoningTokens,
                'cachedInputTokens' => $usage->cacheReadInputTokens,
            ],
        ],
    ];
}

class VercelDataProtocolStreamTest extends TestCase
{
    public function testATextStreamEmitsStartDeltaAndEndPartsForTheMessage(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new TextStart('event-1', 'msg-1', time()),
            new TextDelta('event-2', 'msg-1', 'Hello.', time()),
            new TextEnd('event-3', 'msg-1', time()),
            new StreamEnd('event-4', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            ['type' => 'start', 'messageId' => 'msg-1'],
            ['type' => 'start-step'],
            ['type' => 'text-start', 'id' => 'msg-1'],
            ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'Hello.'],
            ['type' => 'text-end', 'id' => 'msg-1'],
            ['type' => 'finish-step'],
            vercelFinishPart(),
            ['type' => 'done'],
        ], $parts);
    }

    public function testAReasoningStreamEmitsStartDeltaAndEndPartsForTheReasoningBlock(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ReasoningStart('event-1', 'reasoning-1', time()),
            new ReasoningDelta('event-2', 'reasoning-1', 'Considering the options.', time()),
            new ReasoningEnd('event-3', 'reasoning-1', time()),
            new StreamEnd('event-4', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            ['type' => 'start', 'messageId' => 'msg-1'],
            ['type' => 'start-step'],
            ['type' => 'reasoning-start', 'id' => 'reasoning-1'],
            ['type' => 'reasoning-delta', 'id' => 'reasoning-1', 'delta' => 'Considering the options.'],
            ['type' => 'reasoning-end', 'id' => 'reasoning-1'],
            ['type' => 'finish-step'],
            vercelFinishPart(),
            ['type' => 'done'],
        ], $parts);
    }

    public function testACitedTextStreamEmitsASourceUrlPartForTheCitedPage(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new TextStart('event-1', 'msg-1', time()),
            new TextDelta('event-2', 'msg-1', 'Hypervel is a PHP framework.', time()),
            new Citation('event-3', 'msg-1', new UrlCitation('https://hypervel.org/docs', 'Hypervel Documentation'), time()),
            new TextEnd('event-4', 'msg-1', time()),
            new StreamEnd('event-5', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            ['type' => 'start', 'messageId' => 'msg-1'],
            ['type' => 'start-step'],
            ['type' => 'text-start', 'id' => 'msg-1'],
            ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'Hypervel is a PHP framework.'],
            ['type' => 'source-url', 'sourceId' => 'https://hypervel.org/docs', 'url' => 'https://hypervel.org/docs', 'title' => 'Hypervel Documentation'],
            ['type' => 'text-end', 'id' => 'msg-1'],
            ['type' => 'finish-step'],
            vercelFinishPart(),
            ['type' => 'done'],
        ], $parts);
    }

    public function testAUrlCitationWithoutATitleOmitsOnlyTheTitleFromTheSourceUrlPart(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new Citation('event-1', 'msg-1', new UrlCitation('https://hypervel.org/docs'), time()),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            'type' => 'source-url',
            'sourceId' => 'https://hypervel.org/docs',
            'url' => 'https://hypervel.org/docs',
        ], $parts[2]);
    }

    public function testAnUnknownCitationTypeIsSkippedInsteadOfEndingTheStream(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new Citation('event-1', 'msg-1', new class extends CitationData {}, time()),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            ['type' => 'start', 'messageId' => 'msg-1'],
            ['type' => 'start-step'],
            ['type' => 'finish-step'],
            vercelFinishPart(),
            ['type' => 'done'],
        ], $parts);
    }

    public function testAFailedStreamEmitsAnErrorPartInsteadOfAFinishPart(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new TextStart('event-1', 'msg-1', time()),
            new Error('event-2', 'overloaded_error', 'Overloaded', false, time()),
        ]);

        $this->assertSame([
            ['type' => 'start', 'messageId' => 'msg-1'],
            ['type' => 'start-step'],
            ['type' => 'text-start', 'id' => 'msg-1'],
            ['type' => 'error', 'errorText' => 'Overloaded'],
            ['type' => 'done'],
        ], $parts);
    }

    public function testAPausedStreamEmitsAnApprovalRequestPartForEachPendingApproval(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ToolCall('event-1', new ToolCallData('call-1', 'DeleteFile', ['path' => 'a.txt']), time()),
            new ToolApprovalRequest('event-2', collect([
                new PendingApproval('call-1', 'DeleteFile', ['path' => 'a.txt'], 'Destructive operation.'),
            ]), time()),
            new StreamEnd('event-3', 'tool_calls', new TextUsage, time()),
        ]);

        $this->assertSame([
            ['type' => 'start', 'messageId' => 'msg-1'],
            ['type' => 'start-step'],
            ['type' => 'tool-input-available', 'toolCallId' => 'call-1', 'toolName' => 'DeleteFile', 'input' => ['path' => 'a.txt']],
            ['type' => 'tool-approval-request', 'toolCallId' => 'call-1', 'approvalId' => 'call-1', 'reason' => 'Destructive operation.'],
            ['type' => 'finish-step'],
            vercelFinishPart('tool-calls'),
            ['type' => 'done'],
        ], $parts);
    }

    public function testAResumedStreamEmitsTheApprovedToolOutputForThePriorTurnToolCall(): void
    {
        $parts = vercelProtocolParts([
            new ToolResult('event-1', new ToolResultData('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
            new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
            new TextDelta('event-2', 'msg-2', 'Done.', time()),
            new StreamEnd('event-3', 'stop', new TextUsage, time()),
        ], messageId: 'client-message-1');

        $this->assertSame([
            ['type' => 'start', 'messageId' => 'client-message-1'],
            ['type' => 'start-step'],
            ['type' => 'tool-output-available', 'toolCallId' => 'call-1', 'output' => 'deleted'],
            ['type' => 'finish-step'],
            ['type' => 'start-step'],
            ['type' => 'text-delta', 'id' => 'msg-2', 'delta' => 'Done.'],
            ['type' => 'finish-step'],
            vercelFinishPart(),
            ['type' => 'done'],
        ], $parts);
    }

    public function testAResumedStreamMayContinueAnExistingClientSideMessage(): void
    {
        $parts = vercelProtocolParts([
            new ToolResult('event-1', new ToolResultData('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
            new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ], messageId: 'client-message-1');

        $this->assertSame(['type' => 'start', 'messageId' => 'client-message-1'], $parts[0]);
        $this->assertCount(1, collect($parts)->where('type', 'start'));
    }

    public function testAResumedChatStreamsTheApprovedToolOutputIntoItsAssistantMessage(): void
    {
        $chat = Vercel::chat([
            ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Delete a.txt']]],
            ['id' => 'm2', 'role' => 'assistant', 'parts' => [
                ['type' => 'tool-DeleteFile', 'toolCallId' => 'call-1', 'state' => 'approval-responded', 'input' => ['path' => 'a.txt'], 'approval' => ['id' => 'call-1', 'approved' => true]],
            ]],
        ]);

        $parts = vercelProtocolParts([
            new ToolResult('event-1', new ToolResultData('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ], protocol: $chat->protocol());

        $this->assertSame(['type' => 'start', 'messageId' => 'm2'], $parts[0]);
        $this->assertSame(['type' => 'tool-output-available', 'toolCallId' => 'call-1', 'output' => 'deleted'], $parts[2]);
    }

    public function testARejectedApprovalStreamsAsADeniedToolOutput(): void
    {
        $parts = vercelProtocolParts([
            new ToolResult('event-1', new ToolResultData('call-1', 'DeleteFile', ['path' => 'a.txt'], 'The user rejected this tool call.'), false, 'The user rejected this tool call.', time(), denied: true),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ], messageId: 'client-message-1');

        $this->assertSame([
            ['type' => 'start', 'messageId' => 'client-message-1'],
            ['type' => 'start-step'],
            ['type' => 'tool-output-denied', 'toolCallId' => 'call-1'],
            ['type' => 'finish-step'],
            vercelFinishPart(),
            ['type' => 'done'],
        ], $parts);
    }

    public function testAToolResultWithoutAPriorCallOrExistingMessageIsSkipped(): void
    {
        $parts = vercelProtocolParts([
            new ToolResult('event-1', new ToolResultData('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            ['type' => 'done'],
        ], $parts);
    }

    public function testAnUnexecutedToolCallStreamsAsAToolOutputError(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ToolCall('event-1', new ToolCallData('call-1', 'DeleteFile', ['path' => 'a.txt']), time()),
            new ToolResult('event-2', new ToolResultData('call-1', 'DeleteFile', ['path' => 'a.txt'], 'The agent reached its maximum number of steps without running this tool call.'), false, 'The agent reached its maximum number of steps without running this tool call.', time()),
            new StreamEnd('event-3', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            'type' => 'tool-output-error',
            'toolCallId' => 'call-1',
            'errorText' => 'The agent reached its maximum number of steps without running this tool call.',
        ], $parts[3]);
    }

    public function testAFailedToolCallWithoutAnErrorMessageStreamsADefaultErrorText(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ToolCall('event-1', new ToolCallData('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
            new ToolResult('event-2', new ToolResultData('call-1', 'GetWeather', ['city' => 'Lisbon'], null), false, null, time()),
            new StreamEnd('event-3', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            'type' => 'tool-output-error',
            'toolCallId' => 'call-1',
            'errorText' => 'The tool call failed.',
        ], $parts[3]);
    }

    public function testTheFinishPartCarriesTheStreamUsageAndFinishReasonAsMessageMetadata(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new TextDelta('event-1', 'msg-1', 'Hello.', time()),
            new StreamEnd('event-2', 'length', new TextUsage(inputTokens: 10, outputTokens: 20), time()),
        ]);

        $this->assertSame(vercelFinishPart('length', new TextUsage(inputTokens: 10, outputTokens: 20)), $parts[count($parts) - 2]);
    }

    public function testFinishReasonsOutsideTheVercelEnumEmitAsOther(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new StreamEnd('event-1', 'continue', new TextUsage, time()),
        ]);

        $this->assertSame(vercelFinishPart('other'), $parts[count($parts) - 2]);
    }

    public function testAnUnknownFinishReasonEmitsAsOther(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new StreamEnd('event-1', 'unknown', new TextUsage, time()),
        ]);

        $this->assertSame(vercelFinishPart('other'), $parts[count($parts) - 2]);
    }

    public function testAProviderToolEventEmitsAsACustomProviderPart(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'openai', 'gpt-5', time()),
            new ProviderToolEvent('event-1', 'search-1', 'web_search_call', ['query' => 'Hypervel'], 'in_progress', time(), 'openai'),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            'type' => 'custom',
            'kind' => 'openai.web_search_call',
            'providerMetadata' => [
                'openai' => [
                    'itemId' => 'search-1',
                    'status' => 'in_progress',
                    'data' => ['query' => 'Hypervel'],
                ],
            ],
        ], $parts[2]);
    }

    public function testAMultiStepStreamEmitsOneFinishPartWithCombinedUsageAndTheFinalReason(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ToolCall('event-1', new ToolCallData('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
            new StreamEnd('event-2', 'tool_calls', new TextUsage(inputTokens: 10, outputTokens: 5), time()),
            new ToolResult('event-3', new ToolResultData('call-1', 'GetWeather', ['city' => 'Lisbon'], 'sunny'), true, null, time()),
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new TextDelta('event-4', 'msg-1', 'Sunny.', time()),
            new StreamEnd('event-5', 'stop', new TextUsage(inputTokens: 20, outputTokens: 15), time()),
        ]);

        $this->assertSame([
            vercelFinishPart('stop', new TextUsage(inputTokens: 30, outputTokens: 20)),
        ], collect($parts)->where('type', 'finish')->values()->all());
        $this->assertSame([
            'start', 'start-step',
            'tool-input-available', 'tool-output-available', 'finish-step',
            'start-step', 'text-delta', 'finish-step',
            'finish', 'done',
        ], collect($parts)->pluck('type')->all());
    }

    public function testAnExceptionMidStreamIsReportedAndEmittedAsAMaskedTerminalErrorPart(): void
    {
        Exceptions::fake();

        $parts = vercelProtocolParts(function (): Generator {
            yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
            yield new TextDelta('event-1', 'msg-1', 'Hel', time());

            throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
        });

        $this->assertSame([
            ['type' => 'start', 'messageId' => 'msg-1'],
            ['type' => 'start-step'],
            ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'Hel'],
            ['type' => 'error', 'errorText' => 'An error occurred.'],
            ['type' => 'done'],
        ], $parts);

        Exceptions::assertReported(RuntimeException::class);
    }

    public function testAProviderStreamErrorFollowedByTheLoopExceptionEmitsASingleErrorPart(): void
    {
        Exceptions::fake();

        $parts = vercelProtocolParts(function (): Generator {
            yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
            yield new TextDelta('event-1', 'msg-1', 'Hel', time());

            $error = new Error('event-2', 'overloaded_error', 'Overloaded', false, time());

            yield $error;

            throw new StreamErrorException($error);
        });

        $this->assertSame([
            ['type' => 'start', 'messageId' => 'msg-1'],
            ['type' => 'start-step'],
            ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'Hel'],
            ['type' => 'error', 'errorText' => 'Overloaded'],
            ['type' => 'done'],
        ], $parts);

        Exceptions::assertNothingReported();
    }

    public function testAnUnexpectedExceptionAfterAnErrorPartIsStillReported(): void
    {
        Exceptions::fake();

        $parts = vercelProtocolParts(function (): Generator {
            yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
            yield new Error('event-1', 'overloaded_error', 'Overloaded', false, time());

            throw new RuntimeException('Broken pipe');
        });

        $this->assertCount(1, collect($parts)->where('type', 'error'));

        Exceptions::assertReported(RuntimeException::class);
    }

    public function testAStreamEndAfterAnErrorDoesNotEmitFinishParts(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new Error('event-1', 'overloaded_error', 'Overloaded', false, time()),
            new StreamEnd('event-2', 'error', new TextUsage, time()),
        ]);

        $this->assertSame([
            ['type' => 'start', 'messageId' => 'msg-1'],
            ['type' => 'start-step'],
            ['type' => 'error', 'errorText' => 'Overloaded'],
            ['type' => 'done'],
        ], $parts);
    }

    public function testAToolExecutedWithinTheStreamEmitsItsInputAndOutputParts(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ToolCall('event-1', new ToolCallData('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
            new ToolResult('event-2', new ToolResultData('call-1', 'GetWeather', ['city' => 'Lisbon'], 'sunny'), true, null, time()),
            new StreamEnd('event-3', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            ['type' => 'start', 'messageId' => 'msg-1'],
            ['type' => 'start-step'],
            ['type' => 'tool-input-available', 'toolCallId' => 'call-1', 'toolName' => 'GetWeather', 'input' => ['city' => 'Lisbon']],
            ['type' => 'tool-output-available', 'toolCallId' => 'call-1', 'output' => 'sunny'],
            ['type' => 'finish-step'],
            vercelFinishPart(),
            ['type' => 'done'],
        ], $parts);
    }

    public function testAToolStillProducingItsOutputEmitsPreliminaryPartsWithoutChangingTheRunLifecycle(): void
    {
        $parts = vercelProtocolParts([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new TextDelta('event-1', 'msg-1', 'Working on it.', time()),
            new ToolCall('event-2', new ToolCallData('call-1', 'document_specialist', ['task' => 'Report']), time()),
            new ToolResult('event-3', new ToolResultData('call-1', 'document_specialist', ['task' => 'Report'], 'internal monologue'), true, null, 200, preliminary: true),
            new ToolResult('event-4', new ToolResultData('call-1', 'document_specialist', ['task' => 'Report'], 'done'), true, null, time()),
            new TextDelta('event-5', 'msg-1', ' Done.', time()),
            new StreamEnd('event-6', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            ['type' => 'start', 'messageId' => 'msg-1'],
            ['type' => 'start-step'],
            ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'Working on it.'],
            ['type' => 'tool-input-available', 'toolCallId' => 'call-1', 'toolName' => 'document_specialist', 'input' => ['task' => 'Report']],
            [
                'type' => 'tool-output-available',
                'toolCallId' => 'call-1',
                'output' => 'internal monologue',
                'preliminary' => true,
            ],
            ['type' => 'tool-output-available', 'toolCallId' => 'call-1', 'output' => 'done'],
            ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => ' Done.'],
            ['type' => 'finish-step'],
            vercelFinishPart(),
            ['type' => 'done'],
        ], $parts);
    }
}
