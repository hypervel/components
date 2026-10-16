<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Ai\Tests;

use Closure;
use Generator;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Streaming\Events\TextDelta;
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
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Workbench\App\Agents\Assistant;
use Workbench\App\Console\Commands\AgentCommand;
use Workbench\App\Support\StreamingMarkdown;

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

    public function testMarkdownFormattingDoesNotDependOnChunkBoundaries(): void
    {
        $text = "# Heading\n**Hello `world`** and `code`\nplain # comment\n```python\n# compute\na**2 + b**2\n`x`\n```not a closing fence\n# still code\n```\nDone ``x`` and ```npm install``` now\n1. Install:\n   ```python\n   y = a**2 + b**2\n   print(`x`)\n   ```\n2. Done\n**unfinished\n`end";
        $expected = "\e[1;36mHeading\e[22;39m\n\e[1mHello \e[36mworld\e[39m\e[22m and \e[36mcode\e[39m\nplain # comment\n```python\n# compute\na**2 + b**2\n`x`\n```not a closing fence\n# still code\n```\nDone ``x`` and ```npm install``` now\n1. Install:\n   ```python\n   y = a**2 + b**2\n   print(`x`)\n   ```\n2. Done\n**unfinished\n`end";

        $text .= "\n````markdown\n```php\n# literal\n```\n````\n# After\n**bold**\n";
        $expected .= "\n````markdown\n```php\n# literal\n```\n````\n\e[1;36mAfter\e[22;39m\n\e[1mbold\e[22m\n";

        for ($boundary = 0; $boundary <= strlen($text); ++$boundary) {
            $markdown = new StreamingMarkdown;
            $actual = $markdown->push(substr($text, 0, $boundary));
            $actual .= $markdown->push(substr($text, $boundary)) . $markdown->flush();

            $this->assertSame($expected, $actual);
        }

        $markdown = new StreamingMarkdown;
        $actual = '';
        foreach (str_split($text) as $character) {
            $actual .= $markdown->push($character);
        }
        $this->assertSame($expected, $actual . $markdown->flush());

        $markdown = new StreamingMarkdown;
        $this->assertSame('Plain text ', $markdown->push('Plain text **unfinished'));
        $this->assertSame("**unfinished\nnext", $markdown->push("\nnext"));
        $this->assertSame('', $markdown->flush());
    }

    public function testAgUiRejectsInvalidThreadIdsBeforeCreatingRecords(): void
    {
        Assistant::fake(['Unexpected response']);

        $this->postJson('/ag-ui', [
            'threadId' => 'not-a-uuid',
            'runId' => 'demo-run',
            'messages' => [['id' => 'message', 'role' => 'user', 'content' => 'Hello']],
        ])->assertUnprocessable()->assertJsonValidationErrors('threadId');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('agent_conversations', 0);
    }

    public function testFailedStreamFlushesUnfinishedMarkdownAndRestoresTheCursor(): void
    {
        Prompt::fake();
        Prompt::terminal()->shouldReceive('cols')->andReturn(120); // @phpstan-ignore-line
        $output = new BufferedConsoleOutput(decorated: true);
        Prompt::setOutput($output);
        $command = new AgentCommand;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        $exception = new RuntimeException('Stream failed');
        $response = new StreamableAgentResponse('invocation', static function () use ($exception): Generator {
            yield new TextDelta('event', 'message', '**unfinished', 0);

            throw $exception;
        });

        try {
            (new ReflectionMethod($command, 'renderResponse'))->invoke($command, $response, 'hidden', 'hidden', 'output-token-count', null);
            $this->fail('The stream failure must propagate.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertStringContainsString('**unfinished', Utils::stripTerminalControls($output->content()));
        $this->assertStringContainsString("\e[?25h", $output->content());
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

        $renderer = new ReflectionMethod($command, $method);

        if ($method === 'renderMarkdownDelta') {
            $markdown = new StreamingMarkdown;
            $result = $renderer->invoke($command, $input, $markdown) . $markdown->flush();
        } else {
            $result = $renderer->invoke($command, $input);
        }

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
