<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Ai\Tests;

use Closure;
use Hypervel\Console\OutputStyle;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Prompts\Output\BufferedConsoleOutput;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Stream;
use Hypervel\Prompts\Support\Utils;
use Hypervel\Support\Str;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Workbench\App\Agents\Assistant;
use Workbench\App\Console\Commands\AgentCommand;

use function Hypervel\Prompts\note;
use function Hypervel\Prompts\table;

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

    #[DataProvider('terminalOutput')]
    public function testTerminalRendererStripsModelControlsBeforeAddingStyles(string $method, mixed $input, bool $decorated, string $expected, Closure $render): void
    {
        Prompt::fake();
        Prompt::terminal()->shouldReceive('cols')->andReturn(120); // @phpstan-ignore-line
        $output = new BufferedConsoleOutput(decorated: $decorated);
        Prompt::setOutput($output);
        $command = new AgentCommand;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        $result = (new ReflectionMethod($command, $method))->invoke($command, $input);

        $render($result);

        $content = $output->content();
        $this->assertStringContainsString($expected, Utils::stripTerminalControls($content));
        $this->assertStringNotContainsString("\e[2J", $content);
        $this->assertStringNotContainsString("\e]52;", $content);
        $this->assertStringNotContainsString("\e[30;40m", $content);

        if ($decorated && str_starts_with($method, 'renderMarkdown')) {
            $this->assertStringContainsString("\e[1m", $content);
        }
    }

    /**
     * Provide Markdown and tool values for styled and plain terminal output.
     */
    public static function terminalOutput(): array
    {
        $renderStream = static function (string $result): void {
            $stream = new Stream;

            try {
                $stream->append($result);
            } finally {
                $stream->close();
            }
        };
        $renderTable = static function (string $result): void {
            table([[$result]]);
        };

        return [
            ['renderMarkdownDelta', "\e[2J\e]52;c;c2VjcmV0\x07<error>**Hello**</error>\r", true, '<error>Hello</error>', $renderStream],
            ['renderMarkdownDelta', "\e[2J<error>**Hello**</error>\r", false, '<error>**Hello**</error>', $renderStream],
            ['renderMarkdownBlock', '\<fg=black;bg=black>**Hello**', true, '\<fg=black;bg=black>Hello', note(...)],
            ['renderMarkdownBlock', '<info>**Hello**</info>', false, '<info>**Hello**</info>', note(...)],
            ['formatValue', "\e[2J<error>Tool output</error>", true, '<error>Tool output</error>', $renderTable],
            ['formatValue', ['name' => '<fg=black;bg=black>Hidden</>'], true, '"name": "<fg=black;bg=black>Hidden</>"', $renderTable],
            ['formatValue', '\<fg=black;bg=black>Hidden', true, '\<fg=black;bg=black>Hidden', $renderTable],
        ];
    }
}
