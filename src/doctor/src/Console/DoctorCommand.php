<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Console;

use Closure;
use Hypervel\Console\Command;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Doctor;
use Hypervel\Doctor\PendingRun;
use Hypervel\Doctor\Renderers\AgentRenderer;
use Hypervel\Doctor\Renderers\CliRenderer;
use Hypervel\Doctor\Renderers\GithubRenderer;
use Hypervel\Doctor\Results\DiagnosticOutcome;
use Hypervel\Doctor\Results\DiagnosticReport;
use Hypervel\Doctor\Results\Status;
use Hypervel\Prompts\Support\Logger;
use Laravel\AgentDetector\AgentDetector;

use function Hypervel\Prompts\confirm;
use function Hypervel\Prompts\info;
use function Hypervel\Prompts\select;
use function Hypervel\Prompts\task;
use function Hypervel\Prompts\warning;

/**
 * @phpstan-import-type GroupContinuation from PendingRun
 */
class DoctorCommand extends Command
{
    /**
     * The select entry that declines a fix requiring a choice.
     */
    protected const string SKIP_FIX = '__skip__';

    protected ?string $signature = 'doctor
        {--only=* : Run only the specified diagnostic classes, groups, or packages}
        {--except=* : Skip the specified diagnostic classes, groups, or packages}
        {--bail : Stop after the first failing diagnostic}
        {--fix : Run available deterministic fixes without prompting}
        {--format=cli : Output format: cli, json, github, or agent}
        {--fail-on=fail : Exit code threshold: fail, warn, or never}';

    protected string $description = 'Run Hypervel Doctor diagnostics';

    /**
     * The interactive CLI renderer.
     */
    protected ?CliRenderer $renderer = null;

    /**
     * Execute the console command.
     */
    public function handle(Doctor $doctor, Repository $config): int
    {
        // Keep $config for signature compatibility; Hypervel rejects invalid timezones
        // during boot, so upstream's timezone recovery is unnecessary.
        $format = $this->format();
        $failOn = $this->failOn();

        if ($format === null || $failOn === null || ! $this->fixOptionIsValidFor($format)) {
            return self::FAILURE;
        }

        return match ($format) {
            'cli' => $this->runCli($doctor, $failOn),
            'json' => $this->runJson($doctor, $failOn),
            'github' => $this->runGithub($doctor, $failOn),
            'agent' => $this->runAgent($doctor, $failOn),
        };
    }

    /**
     * Get the requested output format.
     *
     * @return null|'agent'|'cli'|'github'|'json'
     */
    protected function format(): ?string
    {
        $format = $this->input()->string('format')->toString();

        if (! in_array($format, ['cli', 'json', 'github', 'agent'], true)) {
            $this->error('The --format option must be one of: cli, json, github, agent.');

            return null;
        }

        if (! $this->input->hasParameterOption('--format') && AgentDetector::detect()->isAgent) {
            return 'agent';
        }

        return $format;
    }

    /**
     * Get the configured exit-code threshold.
     *
     * @return null|'fail'|'never'|'warn'
     */
    protected function failOn(): ?string
    {
        $failOn = $this->input()->string('fail-on')->toString();

        if (! in_array($failOn, [Status::Fail->value, Status::Warn->value, 'never'], true)) {
            $this->error('The --fail-on option must be one of: fail, warn, never.');

            return null;
        }

        return $failOn;
    }

    /**
     * Determine whether the fix option is valid for the format.
     *
     * @param 'agent'|'cli'|'github'|'json' $format
     */
    protected function fixOptionIsValidFor(string $format): bool
    {
        if ($format === 'cli' || $format === 'agent' || ! $this->input()->boolean('fix')) {
            return true;
        }

        $this->error('The --fix option may only be used with the cli or agent output formats.');

        return false;
    }

    /**
     * Run Doctor for CLI output.
     *
     * @param 'fail'|'never'|'warn' $failOn
     */
    protected function runCli(Doctor $doctor, string $failOn): int
    {
        $report = $this->runner($doctor)
            ->when($this->shouldUseTasks(), fn (PendingRun $runner) => $runner->observeUsing($this->taskObserver()))
            ->fixUsing(fn (DiagnosticOutcome $outcome): bool|string => $this->fixDecision($outcome))
            ->beforeRerun(function (): void {
                $this->restorePrompts();

                info('Re-running diagnostics after applying fixes...');
            })
            ->run();

        if ($report->diagnostics() === []) {
            if (! $doctor->hasDiagnostics()) {
                info('No diagnostics registered.');
            } else {
                warning('No diagnostics matched the selected filters.');
            }

            return self::SUCCESS;
        }

        $this->renderer()->render($report);

        return $this->exitCode($report, $failOn);
    }

    /**
     * Run Doctor for JSON output.
     *
     * @param 'fail'|'never'|'warn' $failOn
     */
    protected function runJson(Doctor $doctor, string $failOn): int
    {
        $report = $this->runner($doctor)->run();

        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $this->exitCode($report, $failOn);
    }

    /**
     * Run Doctor for GitHub Actions output.
     *
     * @param 'fail'|'never'|'warn' $failOn
     */
    protected function runGithub(Doctor $doctor, string $failOn): int
    {
        $report = $this->runner($doctor)->run();

        foreach ((new GithubRenderer)->annotations($report) as $annotation) {
            $this->line($annotation);
        }

        return $this->exitCode($report, $failOn);
    }

    /**
     * Run Doctor for agent-optimized output.
     *
     * @param 'fail'|'never'|'warn' $failOn
     */
    protected function runAgent(Doctor $doctor, string $failOn): int
    {
        $report = $this->runner($doctor)
            ->when($this->input()->boolean('fix'), fn (PendingRun $runner) => $runner->fixUsing(
                static fn (DiagnosticOutcome $outcome): bool => ! $outcome->fixRequiresOption(),
            ))
            ->run();

        $this->line((new AgentRenderer)->render($report));

        return $this->exitCode($report, $failOn);
    }

    /**
     * Begin a run configured from the command options.
     */
    protected function runner(Doctor $doctor): PendingRun
    {
        return $doctor->only(...$this->optionList('only'))
            ->except(...$this->optionList('except'))
            ->bail($this->input()->boolean('bail'));
    }

    /**
     * Get a normalized list option.
     *
     * @return list<string>
     */
    protected function optionList(string $name): array
    {
        return array_values(array_filter($this->input()->array($name), is_string(...)));
    }

    /**
     * Determine whether Hypervel Prompts tasks should be used.
     */
    protected function shouldUseTasks(): bool
    {
        return $this->output->isDecorated()
            && $this->input->isInteractive();
    }

    /**
     * Get the callback that observes each diagnostic group as a console task.
     *
     * @return Closure(string, GroupContinuation): void
     */
    protected function taskObserver(): Closure
    {
        return function (string $group, Closure $next): void {
            task(
                label: sprintf('%s diagnostics', ucfirst($group)),
                limit: 0,
                keepSummary: true,
                callback: fn (Logger $logger) => $this->observeGroupTask($next, $logger),
            );
        };
    }

    /**
     * Observe a diagnostic group within a console task.
     *
     * @param GroupContinuation $next
     */
    protected function observeGroupTask(Closure $next, Logger $logger): void
    {
        $next(
            before: function (Diagnostic $diagnostic) use ($logger): void {
                $logger->subLabel($diagnostic->name);
            },
            after: function (DiagnosticOutcome $outcome) use ($logger): void {
                $this->logOutcome($logger, $outcome);
            },
        );

        $logger->subLabel('');
    }

    /**
     * Write a diagnostic outcome to the task logger.
     */
    protected function logOutcome(Logger $logger, DiagnosticOutcome $outcome): void
    {
        $name = $outcome->diagnostic->name;

        match ($outcome->result->status) {
            Status::Pass => $logger->success($name),
            Status::Notice, Status::Skip => $logger->line($name . ': ' . $outcome->result->summary),
            Status::Warn => $logger->warning($name . ': ' . $outcome->result->summary),
            Status::Fail, Status::Error => $logger->error($name . ': ' . $outcome->result->summary),
        };
    }

    /**
     * Decide whether a fix should be applied and with which option.
     */
    protected function fixDecision(DiagnosticOutcome $outcome): bool|string
    {
        if ($this->input()->boolean('fix')) {
            return ! $outcome->fixRequiresOption();
        }

        if ($outcome->fixRequiresOption()) {
            return $this->chooseFix($outcome);
        }

        if (! $this->input->isInteractive()) {
            return false;
        }

        $this->restorePrompts();

        $this->renderer()->renderFixConfirmation($outcome);

        return confirm($this->confirmationPrompt($outcome), default: true);
    }

    /**
     * Let the user choose between the fix options or decline the fix.
     *
     * Fixes that require a choice are never applied under --fix or without
     * an interactive terminal; the failure renders with its remediation.
     */
    protected function chooseFix(DiagnosticOutcome $outcome): bool|string
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        $this->restorePrompts();

        $this->renderer()->renderFixConfirmation($outcome);

        $choice = select(
            label: $this->confirmationPrompt($outcome),
            options: [
                ...$outcome->result->fixOptions,
                self::SKIP_FIX => $outcome->result->fixDeclineLabel ?? 'Skip — leave unfixed',
            ],
        );

        // PHP coerces numeric-string array keys to integers, so restore the
        // declared string option value before it flows back to the runner.
        return $choice === self::SKIP_FIX ? false : (string) $choice;
    }

    /**
     * Restore the prompts configuration.
     *
     * Fixes may run nested Artisan commands, which re-bind the prompts
     * output and fallbacks to the nested command. Without a restore, Doctor's
     * remaining output is swallowed and pending confirmations appear to hang.
     */
    protected function restorePrompts(): void
    {
        $this->configurePrompts($this->input);
    }

    /**
     * Get the confirmation prompt for a diagnostic fix.
     */
    protected function confirmationPrompt(DiagnosticOutcome $outcome): string
    {
        return $outcome->result->confirmation
            ?? ($outcome->fixRequiresOption()
                ? sprintf('How should "%s" be fixed?', $outcome->diagnostic->name)
                : sprintf('Fix "%s"?', $outcome->diagnostic->name));
    }

    /**
     * Get the interactive CLI renderer.
     */
    protected function renderer(): CliRenderer
    {
        return $this->renderer ??= new CliRenderer($this->output);
    }

    /**
     * Determine the command exit code for the report.
     *
     * @param 'fail'|'never'|'warn' $failOn
     */
    protected function exitCode(DiagnosticReport $report, string $failOn): int
    {
        if ($failOn === 'never') {
            return self::SUCCESS;
        }

        if ($failOn === Status::Warn->value) {
            return $report->hasFailures() || $report->hasWarnings() ? self::FAILURE : self::SUCCESS;
        }

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
