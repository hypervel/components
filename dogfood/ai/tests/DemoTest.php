<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Ai\Tests;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Str;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase;
use Workbench\App\Agents\Assistant;

class DemoTest extends TestCase
{
    use RefreshDatabase;
    use WithWorkbench;

    /**
     * Keep demo tests independent of the local database and paid providers.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.default', 'testing');
        $app->make('config')->set('ai.conversations.generate_title', false);
    }

    public function testBrowserChatStartsAndContinuesAConversation(): void
    {
        $this->withoutVite();
        $this->get('/chat')->assertOk()->assertSee('Hypervel AI SDK');

        Assistant::fake(['Hello.', 'Welcome back.']);

        $first = $this->postJson('/chat/message', ['message' => 'Hello'])
            ->assertOk()
            ->assertJsonPath('text', 'Hello.');

        $conversationId = $first->json('conversation_id');
        $this->assertNotEmpty($conversationId);

        $this->postJson('/chat/message', ['message' => 'Again', 'conversation_id' => $conversationId])
            ->assertOk()
            ->assertJsonPath('text', 'Welcome back.')
            ->assertJsonPath('conversation_id', $conversationId);

        $this->assertDatabaseCount('agent_conversations', 1);
        $this->assertDatabaseCount('agent_conversation_messages', 4);
    }

    public function testAgUiStreamsAndRestoresTheClientThread(): void
    {
        Assistant::fake(['Hello from AG-UI.']);
        $threadId = (string) Str::uuid7();

        $response = $this->postJson('/ag-ui', [
            'threadId' => $threadId,
            'runId' => 'demo-run',
            'messages' => [['id' => 'demo-message', 'role' => 'user', 'content' => 'Hello']],
        ])->assertOk()->assertStreamed();

        $content = $response->streamedContent();
        $this->assertStringContainsString('RUN_FINISHED', $content);
        $this->assertStringContainsString($threadId, $content);

        $this->getJson('/ag-ui/' . $threadId)
            ->assertOk()
            ->assertJsonPath('threadId', $threadId)
            ->assertJsonPath('messages.0.content', 'Hello')
            ->assertJsonPath('messages.1.content', 'Hello from AG-UI.');
    }
}
