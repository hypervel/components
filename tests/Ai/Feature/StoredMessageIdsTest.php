<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\TestCase;

class StoredMessageIdsTest extends TestCase
{
    use RefreshDatabase;

    public function testARememberedTurnReportsTheRowsItWrote(): void
    {
        RememberingAssistantAgent::fake(['Hello world']);

        $participant = new class {
            public int $id = 1;
        };

        $response = (new RememberingAssistantAgent)->forUser($participant)->prompt('Hi');

        $this->assertNotNull($response->conversationId);
        $this->assertNotNull($response->userMessageId);
        $this->assertNotNull($response->assistantMessageId);

        // The IDs name real rows, not just the stream's own identifiers...
        $this->assertSame('user', DB::table('agent_conversation_messages')->where('id', $response->userMessageId)->value('role'));
        $this->assertSame('assistant', DB::table('agent_conversation_messages')->where('id', $response->assistantMessageId)->value('role'));
    }

    public function testAStreamedTurnReportsTheRowsItWroteOnceItHasBeenConsumed(): void
    {
        RememberingAssistantAgent::fake(['Hello world']);

        $participant = new class {
            public int $id = 1;
        };

        $response = (new RememberingAssistantAgent)->forUser($participant)->stream('Hi');

        foreach ($response as $event) {
            $this->assertNotNull($event);
        }

        $this->assertNotNull($response->assistantMessageId);
        $this->assertSame('assistant', DB::table('agent_conversation_messages')->where('id', $response->assistantMessageId)->value('role'));
    }

    public function testATurnThatStoresNothingReportsNoIds(): void
    {
        RememberingAssistantAgent::fake(['Hello world']);

        // No participant and no conversation, so nothing is remembered...
        $response = (new RememberingAssistantAgent)->prompt('Hi');

        $this->assertNull($response->conversationId);
        $this->assertNull($response->userMessageId);
        $this->assertNull($response->assistantMessageId);
    }
}
