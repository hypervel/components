<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Ai\Events\AgentPrompted;
use Hypervel\Ai\Events\AgentStreamed;
use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\TestCase;

class RawResponseTest extends TestCase
{
    use AnthropicHelpers;

    public function testTextResponsesExposeTheRawHttpResponse(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('Hello there'),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        $this->assertInstanceOf(Response::class, $response->raw);
        $this->assertSame('Hello there', $response->raw->json('content.0.text'));
    }

    public function testStructuredResponsesExposeTheRawHttpResponse(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
        ]);

        $response = (new StructuredAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );

        $this->assertInstanceOf(Response::class, $response->raw);
        $this->assertSame('msg_123', $response->raw->json('id'));
    }

    public function testToolCallLoopsExposeTheRawHttpResponseOfTheFinalStep(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'anthropic',
        );

        $this->assertInstanceOf(Response::class, $response->raw);
        $this->assertSame('The number is 72019', $response->raw->json('content.0.text'));
    }

    public function testEachStepExposesTheRawHttpResponseOfItsOwnRequest(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'anthropic',
        );

        $this->assertCount(2, $response->steps);
        $this->assertInstanceOf(Response::class, $response->steps[0]->raw);
        $this->assertSame('tool_use', $response->steps[0]->raw->json('stop_reason'));
        $this->assertInstanceOf(Response::class, $response->steps[1]->raw);
        $this->assertSame('The number is 72019', $response->steps[1]->raw->json('content.0.text'));
    }

    public function testAgentPromptedEventExposesTheRawHttpResponse(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('Hello there'),
        ]);

        $raw = null;

        Event::listen(AgentPrompted::class, function (AgentPrompted $event) use (&$raw): void {
            $raw = $event->response->raw;
        });

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        $this->assertInstanceOf(Response::class, $raw);
        $this->assertSame('Hello there', $raw->json('content.0.text'));
    }

    public function testStreamedResponsesHaveANullRawHttpResponse(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                    $this->contentBlockStop(0),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $invoked = false;

        Event::listen(AgentStreamed::class, function (AgentStreamed $event) use (&$invoked): void {
            $invoked = true;

            $this->assertNull($event->response->raw);
        });

        $this->collectStreamEvents();

        $this->assertTrue($invoked);
    }

    public function testResponsesDiscardTheRawHttpResponseWhenSerialized(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'anthropic',
        );

        $restored = unserialize(serialize($response));

        $this->assertNull($restored->raw);
        $this->assertSame('The number is 72019', $restored->text);
        $this->assertCount(2, $restored->steps);
        $this->assertNull($restored->steps[1]->raw);
        $this->assertSame('The number is 72019', $restored->steps[1]->text);
    }
}
