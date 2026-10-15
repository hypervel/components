<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Hypervel\Ai\Approvals\Decision;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Concerns\RemembersConversations;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Exceptions\ApprovalNotResumableException;
use Hypervel\Ai\Responses\Data\UrlCitation;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Streaming\Events\Citation;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\ProviderToolEvent;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\ToolApprovalRequest;
use Hypervel\Ai\Streaming\Events\ToolCall;
use Hypervel\Ai\Streaming\Events\ToolResult;
use Hypervel\Console\Command;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Support\Utils;
use Hypervel\Support\Collection;
use InvalidArgumentException;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

use function Hypervel\Prompts\clear;
use function Hypervel\Prompts\confirm;
use function Hypervel\Prompts\error;
use function Hypervel\Prompts\info;
use function Hypervel\Prompts\intro;
use function Hypervel\Prompts\note;
use function Hypervel\Prompts\outro;
use function Hypervel\Prompts\select;
use function Hypervel\Prompts\stream;
use function Hypervel\Prompts\table;
use function Hypervel\Prompts\textarea;
use function Hypervel\Prompts\title;
use function Hypervel\Prompts\warning;

class AgentCommand extends Command
{
    /**
     * The visibility modes shared by the tool and reasoning renderers.
     *
     * @var array<int, string>
     */
    private const array VISIBILITY_MODES = ['full', 'collapsed', 'auto-collapsed', 'hidden'];

    /**
     * The character length above which auto-collapsed content is summarized instead of shown in full.
     */
    private const int AUTO_COLLAPSE_THRESHOLD = 200;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'agent
        {AgentName : An agent class or its name from Workbench\App\Agents}
        {--title= : The title shown in the terminal}
        {--tools=auto-collapsed : full, collapsed, auto-collapsed, or hidden}
        {--reasoning=auto-collapsed : full, collapsed, auto-collapsed, or hidden}
        {--response-statistics=output-tokens-per-second : output-tokens-per-second or output-token-count}
        {--context-size= : The model context window, in tokens}';

    /**
     * The console command description.
     */
    protected string $description = 'Run a Hypervel AI SDK agent in an interactive terminal UI';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $agent = $this->resolveAgent((string) $this->argument('AgentName'));
            $options = $this->options();

            $this->validateOption('tools', $options['tools'], self::VISIBILITY_MODES);
            $this->validateOption('reasoning', $options['reasoning'], self::VISIBILITY_MODES);
            $this->validateOption('response-statistics', $options['response-statistics'], ['output-tokens-per-second', 'output-token-count']);

            $contextSize = $this->contextSize($options['context-size']);
        } catch (InvalidArgumentException $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->input->isInteractive() || ! stream_isatty(STDIN)) {
            error('The agent terminal UI requires an interactive TTY.');

            return self::FAILURE;
        }

        $title = filled($options['title'])
            ? $options['title']
            : class_basename($agent);

        $this->startConversation($agent);

        Prompt::cancelUsing(fn () => throw new AgentTuiInterrupted);

        try {
            $this->renderWelcome($title, $agent);

            while (true) {
                $prompt = textarea(
                    label: 'Prompt',
                    placeholder: 'Ask ' . $title . ' anything...',
                    hint: 'Ctrl+D submits · /help for controls · /exit closes the session',
                    rows: 3,
                );

                $command = $this->handleCommand($prompt, $title, $agent);

                if ($command === 'exit') {
                    break;
                }

                if ($command === 'handled' || blank($prompt)) {
                    continue;
                }

                $this->streamUntilComplete(
                    $agent,
                    $prompt,
                    tools: $options['tools'],
                    reasoning: $options['reasoning'],
                    responseStatistics: $options['response-statistics'],
                    contextSize: $contextSize,
                );
            }
        } catch (AgentTuiInterrupted) {
            // Ctrl+C is an expected way to leave an interactive terminal session.
        } finally {
            Prompt::cancelUsing(null);
        }

        outro('Session closed.');

        return self::SUCCESS;
    }

    /**
     * Resolve an agent from either its fully-qualified class name or workbench alias.
     */
    private function resolveAgent(string $name): Agent
    {
        $class = ltrim(str_replace('/', '\\', $name), '\\');

        if (! str_contains($class, '\\')) {
            $class = 'Workbench\App\Agents\\' . $class;
        }

        if (! class_exists($class)) {
            throw new InvalidArgumentException("Agent [{$name}] was not found.");
        }

        $agent = app($class);

        if (! $agent instanceof Agent) {
            throw new InvalidArgumentException("[{$class}] must implement " . Agent::class . '.');
        }

        return $agent;
    }

    /**
     * Start one in-memory terminal session for agents that remember conversations.
     */
    private function startConversation(Agent $agent): void
    {
        if ($this->remembersConversations($agent)) {
            $this->beginConversation($agent);

            return;
        }

        if ($agent instanceof HasTools) {
            warning('This agent does not remember conversations. Tool approvals cannot be resumed until it uses RemembersConversations.');
        }
    }

    /**
     * Continue an agent turn until it has no more pending tool approvals.
     */
    private function streamUntilComplete(
        Agent $agent,
        string $prompt,
        string $tools,
        string $reasoning,
        string $responseStatistics,
        ?int $contextSize,
    ): void {
        $nextPrompt = $prompt;

        while (true) {
            try {
                $response = $agent->stream($nextPrompt);

                $pendingApprovals = $this->renderResponse(
                    $response,
                    tools: $tools,
                    reasoning: $reasoning,
                    responseStatistics: $responseStatistics,
                    contextSize: $contextSize,
                );
            } catch (ApprovalNotResumableException $exception) {
                error($exception->getMessage());

                return;
            }

            if ($pendingApprovals->isEmpty()) {
                return;
            }

            $nextPrompt = $this->requestApprovals($pendingApprovals);
        }
    }

    /**
     * Collect a decision for every pending approval, offering a bulk shortcut when several are queued.
     *
     * @param Collection<int, PendingApproval> $pendingApprovals
     */
    private function requestApprovals(Collection $pendingApprovals): Decisions
    {
        if ($pendingApprovals->count() > 1 && ($bulk = $this->requestBulkDecision($pendingApprovals)) !== null) {
            return $bulk;
        }

        return Decisions::from($pendingApprovals
            ->mapWithKeys(fn (PendingApproval $approval): array => [
                $approval->id => $this->requestApproval($approval),
            ])
            ->all());
    }

    /**
     * Offer a single approve-all/reject-all decision for a batch of pending approvals.
     *
     * Returns null when the user chooses to decide each approval individually.
     *
     * @param Collection<int, PendingApproval> $pendingApprovals
     */
    private function requestBulkDecision(Collection $pendingApprovals): ?Decisions
    {
        $tools = $pendingApprovals
            ->map(fn (PendingApproval $approval): string => $this->escapeText($approval->tool))
            ->implode(', ');

        note("{$pendingApprovals->count()} tool approvals required · {$tools}");

        return match (select(
            label: 'How would you like to respond?',
            options: [
                'each' => 'Decide each individually',
                'approve-all' => 'Approve all',
                'reject-all' => 'Reject all',
            ],
            default: 'each',
        )) {
            'approve-all' => Decision::approveAll(),
            'reject-all' => Decision::rejectAll('Rejected in terminal UI.'),
            default => null,
        };
    }

    /**
     * Render every event from a streamed response and return any approvals it requests.
     *
     * @return Collection<int, PendingApproval>
     */
    private function renderResponse(
        StreamableAgentResponse $response,
        string $tools,
        string $reasoning,
        string $responseStatistics,
        ?int $contextSize,
    ): Collection {
        $startedAt = hrtime(true);
        $firstTokenAt = null;
        $pendingApprovals = collect();
        $reasoningParts = [];
        $textStream = null;

        foreach ($response as $event) {
            if ($event instanceof TextDelta || $event instanceof ReasoningDelta) {
                $firstTokenAt ??= hrtime(true);
            }

            if ($event instanceof TextDelta) {
                $textStream ??= stream();
                $textStream->append($this->renderMarkdownDelta($event->delta));

                continue;
            }

            $textStream?->close();
            $textStream = null;

            match (true) {
                $event instanceof ToolCall => $this->renderToolCall($event, $tools),
                $event instanceof ToolResult => $this->renderToolResult($event, $tools),
                $event instanceof ToolApprovalRequest => $pendingApprovals = $pendingApprovals
                    ->merge($event->pendingApprovals)
                    ->values(),
                $event instanceof ReasoningStart => $reasoningParts[$event->reasoningId] = '',
                $event instanceof ReasoningDelta => $reasoningParts[$event->reasoningId] = ($reasoningParts[$event->reasoningId] ?? '') . $event->delta,
                $event instanceof ReasoningEnd => $this->renderReasoning(
                    $reasoningParts[$event->reasoningId] ?? '',
                    $reasoning,
                ),
                $event instanceof Citation => $this->renderCitation($event),
                $event instanceof ProviderToolEvent => $this->renderProviderToolEvent($event, $tools),
                $event instanceof Error => error($this->escapeText($event->message)),
                $event instanceof StreamEnd => null,
                default => null,
            };
        }

        $textStream?->close();

        $this->renderResponseStatistics(
            $response,
            // Measure throughput from the first token so time-to-first-token is excluded.
            seconds: (hrtime(true) - ($firstTokenAt ?? $startedAt)) / 1_000_000_000,
            mode: $responseStatistics,
            contextSize: $contextSize,
        );

        return $pendingApprovals;
    }

    /**
     * Render a tool call in the selected visibility mode.
     */
    private function renderToolCall(ToolCall $event, string $mode): void
    {
        if ($mode === 'hidden') {
            return;
        }

        $tool = $event->toolCall;
        $name = $this->escapeText($tool->name);
        $input = $this->formatValue($tool->arguments);

        if ($this->effectiveMode($mode, $input) === 'collapsed') {
            info("Tool · {$name}");

            return;
        }

        table(['Tool', 'Input'], [[
            $name,
            $input,
        ]]);
    }

    /**
     * Render a completed or failed tool call in the selected visibility mode.
     */
    private function renderToolResult(ToolResult $event, string $mode): void
    {
        if ($mode === 'hidden') {
            return;
        }

        $result = $event->toolResult;
        $name = $this->escapeText($result->name);
        $status = $event->successful ? 'completed' : 'failed';
        $output = $this->formatValue($event->successful ? $result->result : ($event->error ?? 'The tool call failed.'));

        if ($this->effectiveMode($mode, $output) === 'collapsed') {
            info("Tool {$status} · {$name}");

            return;
        }

        table(['Tool', 'Status', 'Output'], [[
            $name,
            $status,
            $output,
        ]]);
    }

    /**
     * Render a provider-native tool event that does not map to an SDK tool call.
     */
    private function renderProviderToolEvent(ProviderToolEvent $event, string $mode): void
    {
        if ($mode === 'hidden') {
            return;
        }

        $data = $this->formatValue($event->data);
        $type = $this->escapeText($event->type);
        $status = $this->escapeText($event->status);

        if ($this->effectiveMode($mode, $data) === 'collapsed') {
            info("Provider tool · {$type} ({$status})");

            return;
        }

        table(['Provider tool', 'Status', 'Data'], [[
            $type,
            $status,
            $data,
        ]]);
    }

    /**
     * Render model reasoning without ever exposing it when the user selected hidden mode.
     */
    private function renderReasoning(string $text, string $mode): void
    {
        if ($mode === 'hidden' || blank($text)) {
            return;
        }

        if ($this->effectiveMode($mode, $text) === 'collapsed') {
            info('Reasoning · ' . mb_strlen($text) . ' characters');

            return;
        }

        note($this->renderMarkdownBlock($text), 'info');
    }

    /**
     * Resolve the auto-collapsed mode to full or collapsed based on the content length.
     */
    private function effectiveMode(string $mode, string $content): string
    {
        if ($mode !== 'auto-collapsed') {
            return $mode;
        }

        return mb_strlen($content) > self::AUTO_COLLAPSE_THRESHOLD ? 'collapsed' : 'full';
    }

    /**
     * Render URL citations emitted by providers that support them.
     */
    private function renderCitation(Citation $event): void
    {
        if (! $event->citation instanceof UrlCitation) {
            return;
        }

        $label = $event->citation->title ?? $event->citation->url;

        info($this->escapeText("Source · {$label}\n{$event->citation->url}"));
    }

    /**
     * Ask for a yes/no decision using the same y/n controls as the upstream TUI.
     */
    private function requestApproval(PendingApproval $approval): Decision
    {
        $tool = $this->escapeText($approval->tool);
        note("Tool approval required · {$tool}\n" . $this->formatValue($approval->arguments));

        $approved = confirm(
            label: "Allow {$tool}?",
            default: false,
            yes: 'Approve',
            no: 'Reject',
            hint: $this->escapeText($approval->reason ?? 'This tool requires your approval.'),
        );

        return $approved ? Decision::approve() : Decision::reject('Rejected in terminal UI.');
    }

    /**
     * Render output token count or throughput and optional context window use.
     */
    private function renderResponseStatistics(
        StreamableAgentResponse $response,
        float $seconds,
        string $mode,
        ?int $contextSize,
    ): void {
        $usage = $response->usage;

        // A zeroed usage is reported for turns that end without real usage, such as errors.
        if ($usage === null || ($usage->inputTokens === 0 && $usage->outputTokens === 0)) {
            return;
        }

        $output = $usage->outputTokens;
        $message = $mode === 'output-token-count'
            ? "Output · {$output} tokens"
            : 'Output · ' . number_format($output / max($seconds, 0.001), 1) . ' tokens/s';

        if ($contextSize !== null) {
            $total = $usage->totalTokens();
            $message .= ' · Context ' . number_format(($total / $contextSize) * 100, 1) . "% ({$total}/{$contextSize})";
        }

        info($message);
    }

    /**
     * Handle slash commands without sending them to the model.
     */
    private function handleCommand(string $prompt, string $title, Agent $agent): ?string
    {
        return match (trim($prompt)) {
            '/exit', '/quit' => 'exit',
            '/clear' => $this->clearAndRenderWelcome($title, $agent),
            '/help' => $this->renderHelp(),
            '/new', '/reset' => $this->resetConversation($agent),
            default => null,
        };
    }

    /**
     * Clear the terminal and redraw the welcome message.
     */
    private function clearAndRenderWelcome(string $title, Agent $agent): string
    {
        clear();
        $this->renderWelcome($title, $agent);

        return 'handled';
    }

    /**
     * Render the terminal controls.
     */
    private function renderHelp(): string
    {
        note("Controls\nCtrl+D · submit a multi-line prompt\ny / n · approve or reject a tool\nApprove all / Reject all · decide a batch of approvals at once\nCtrl+C · exit\n/clear · repaint the terminal\n/new · start a fresh conversation\n/exit · close the session");

        return 'handled';
    }

    /**
     * Start a fresh conversation for the current agent.
     */
    private function resetConversation(Agent $agent): string
    {
        if (! $this->remembersConversations($agent)) {
            warning('This agent does not persist conversations.');

            return 'handled';
        }

        $this->beginConversation($agent);
        info('Started a fresh conversation.');

        return 'handled';
    }

    /**
     * Render the session title and available features.
     */
    private function renderWelcome(string $title, Agent $agent): void
    {
        title($title);
        intro("Hypervel AI SDK · {$title}");
        info('Streaming responses, Markdown, tool activity, approvals, reasoning, citations, and response statistics are enabled.');

        if ($agent instanceof HasTools) {
            info('Tool calls are shown as cards. Approvals default to reject.');
        }
    }

    /**
     * Determine whether the agent uses stored conversations.
     */
    private function remembersConversations(Agent $agent): bool
    {
        return $agent instanceof Conversational
            && in_array(RemembersConversations::class, class_uses_recursive($agent), true);
    }

    /**
     * Begin a conversation for the terminal participant.
     */
    private function beginConversation(Agent $agent): void
    {
        (new ReflectionMethod($agent, 'forUser'))->invoke($agent, (object) ['id' => 0]);
    }

    /**
     * Render supported inline Markdown with terminal styles.
     */
    private function renderMarkdownDelta(string $text): string
    {
        $text = Utils::stripTerminalControls(str_replace("\r", '', $text));

        if (! $this->output->isDecorated()) {
            return $text;
        }

        $text = preg_replace_callback('/`([^`]+)`/', fn (array $matches): string => "\e[36m{$matches[1]}\e[39m", $text) ?? $text;
        $text = preg_replace_callback('/\*\*([^*]+)\*\*/', fn (array $matches): string => "\e[1m{$matches[1]}\e[22m", $text) ?? $text;

        return preg_replace('/(^|\n)(#{1,6}\s+)([^\n]+)/', "$1\e[1;36m$3\e[22;39m", $text) ?? $text;
    }

    /**
     * Format completed Markdown blocks such as reasoning with the same styles.
     */
    private function renderMarkdownBlock(string $text): string
    {
        return OutputFormatter::escape($this->renderMarkdownDelta($text));
    }

    /**
     * Format a tool value for terminal output.
     */
    private function formatValue(mixed $value): string
    {
        $text = is_string($value) ? $value : (json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: get_debug_type($value));

        return $this->escapeText($text);
    }

    /**
     * Keep provider text literal when writing through the console formatter.
     */
    private function escapeText(string $text): string
    {
        return OutputFormatter::escape(Utils::stripTerminalControls($text));
    }

    /**
     * Validate an option against its supported values.
     *
     * @param array<int, string> $allowed
     */
    private function validateOption(string $name, mixed $value, array $allowed): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("The --{$name} option must be one of: " . implode(', ', $allowed) . '.');
        }
    }

    /**
     * Parse the optional model context size.
     */
    private function contextSize(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException('The --context-size option must be a positive integer.');
        }

        return (int) $value;
    }
}

class AgentTuiInterrupted extends RuntimeException
{
}
