<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai\Database;

use Hypervel\Ai\AnonymousAgent;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Enums\MessageStatus;
use Hypervel\Ai\Models\Conversation;
use Hypervel\Ai\Models\ConversationMessage;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Ai\Storage\DatabaseConversationStore;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Str;
use Hypervel\Tests\Ai\TestCase;
use Mockery as m;

abstract class ConversationStorageTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Use the configured integration database.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.default', getenv('DB_CONNECTION') ?: 'testing');
    }

    public function testMessagesCanStoreMoreThanSixtyFourKilobytes(): void
    {
        $content = str_repeat('A paragraph from an attached document. ', 2048);
        $conversation = Conversation::create(['id' => (string) Str::uuid7(), 'title' => 'Document review']);
        $message = $conversation->messages()->create([
            'id' => (string) Str::uuid7(),
            'agent' => 'Agent',
            'role' => 'user',
            'content' => $content,
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
        ]);

        $this->assertSame($content, $message->fresh()->content);
    }

    public function testToolPayloadsPreserveNullBytesAndObjectKeyOrder(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation(null, null, 'Read a file');
        $arguments = ['encoding' => 'UTF-8', 'path' => 'example.txt'];
        $contents = "Hello\0world";
        $prompt = new AgentPrompt(new AnonymousAgent('', [], []), 'Read the file', [], m::mock(TextProvider::class), 'model');
        $response = (new AgentResponse('invocation', 'File read', new TextUsage, new Meta('test', 'model')))
            ->withSteps(collect([
                new Step('', [new ToolCall('call-1', 'read_file', $arguments)], [new ToolResult('call-1', 'read_file', $arguments, $contents)], FinishReason::ToolCalls, new TextUsage, new Meta, '', []),
            ]));

        $messageId = $store->storeAssistantMessage($conversationId, null, null, $prompt, $response);
        $message = ConversationMessage::findOrFail($messageId);

        $this->assertSame($arguments, $message->tool_calls[0]['arguments']);
        $this->assertSame($contents, $message->tool_results[0]['result']);
    }
}
