<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Renderers;

use Hypervel\Console\OutputStyle;
use Hypervel\Doctor\Results\DiagnosticFixOutcome;
use Hypervel\Doctor\Results\DiagnosticOutcome;
use Hypervel\Doctor\Results\DiagnosticReport;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Status;
use Hypervel\Prompts\Elements\Element;
use Hypervel\Prompts\Elements\ElementContract;
use Hypervel\Prompts\Elements\Link as PromptLink;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;

use function Hypervel\Prompts\callout;
use function Hypervel\Prompts\error;
use function Hypervel\Prompts\info;
use function Hypervel\Prompts\intro;
use function Hypervel\Prompts\warning;

class CliRenderer
{
    /**
     * Create a new CLI renderer instance.
     */
    public function __construct(protected OutputStyle $output)
    {
    }

    /**
     * Render the diagnostic report.
     */
    public function render(DiagnosticReport $report): void
    {
        intro('Results');

        $verbose = $this->output->isVerbose();
        $notices = [];
        $failedFixes = $this->failedFixesByDiagnostic($report->fixes());
        $mergedFixes = [];

        foreach ($report->diagnostics() as $outcome) {
            if ($outcome->result->status === Status::Notice) {
                $notices[] = $outcome;

                continue;
            }

            if ($this->isReportableIssue($outcome)) {
                $failedFix = $failedFixes[$outcome->diagnostic::class] ?? null;

                if ($failedFix !== null) {
                    $mergedFixes[] = $failedFix;
                }

                $this->renderIssue($outcome, $failedFix);

                continue;
            }

            if (! $verbose && in_array($outcome->result->status, [Status::Pass, Status::Skip], true)) {
                continue;
            }

            $this->output->writeln(sprintf(
                '[%s] %s (%s): %s',
                $outcome->result->status->value,
                $outcome->diagnostic->name,
                $outcome->source()->label(),
                $outcome->result->summary,
            ));

            $this->renderDiagnosticDetails($outcome);
        }

        $this->renderNotices($notices);
        $this->renderFixes(array_values(array_filter(
            $report->fixes(),
            static fn (DiagnosticFixOutcome $fix): bool => ! in_array($fix, $mergedFixes, true),
        )));

        if ($report->hasFailures()) {
            error('Doctor found failing diagnostics.');

            return;
        }

        if ($report->hasWarnings()) {
            warning('Doctor found warnings.');

            return;
        }

        if ($report->fixes() !== []) {
            info('All diagnostics passed or were fixed.');

            return;
        }

        info('All diagnostics passed.');
    }

    /**
     * Render a diagnostic fix confirmation as a callout.
     */
    public function renderFixConfirmation(DiagnosticOutcome $outcome): void
    {
        callout(
            label: $outcome->diagnostic->name,
            content: $this->fixConfirmationCalloutContent($outcome),
            type: $outcome->result->status === Status::Warn ? 'warning' : 'error',
            info: $outcome->source()->label(),
        );
    }

    /**
     * Determine whether the diagnostic should be displayed as an issue.
     */
    protected function isReportableIssue(DiagnosticOutcome $outcome): bool
    {
        return in_array($outcome->result->status, [Status::Warn, Status::Fail, Status::Error], true);
    }

    /**
     * Render an issue diagnostic as a callout.
     */
    protected function renderIssue(DiagnosticOutcome $outcome, ?DiagnosticFixOutcome $failedFix = null): void
    {
        callout(
            label: $outcome->diagnostic->name,
            content: $this->diagnosticCalloutContent($outcome, failedFix: $failedFix),
            type: $this->issueCalloutType($outcome, $failedFix),
            info: $outcome->source()->label(),
        );
    }

    /**
     * Get the callout type for an issue, escalating when a fix failed.
     */
    protected function issueCalloutType(DiagnosticOutcome $outcome, ?DiagnosticFixOutcome $failedFix): string
    {
        if ($failedFix !== null) {
            return 'error';
        }

        return $outcome->result->status === Status::Warn ? 'warning' : 'error';
    }

    /**
     * Key the failed fixes by the diagnostic that attempted them.
     *
     * @param list<DiagnosticFixOutcome> $fixes
     * @return array<class-string, DiagnosticFixOutcome>
     */
    protected function failedFixesByDiagnostic(array $fixes): array
    {
        $failed = [];

        foreach ($fixes as $fix) {
            if ($fix->result->status->failed()) {
                $failed[$fix->diagnostic::class] = $fix;
            }
        }

        return $failed;
    }

    /**
     * Render diagnostic details, remediation, and links.
     */
    protected function renderDiagnosticDetails(DiagnosticOutcome $outcome): void
    {
        if ($outcome->result->details !== null) {
            $this->output->writeln('    ' . $outcome->result->details);
        }

        if ($outcome->result->remediation !== null) {
            $this->output->writeln('    ' . $outcome->result->remediation);
        }

        foreach ($outcome->result->links as $link) {
            $this->output->writeln('    ' . $link->url);
        }
    }

    /**
     * Render notice diagnostics as callouts grouped by source.
     *
     * @param list<DiagnosticOutcome> $notices
     */
    protected function renderNotices(array $notices): void
    {
        if ($notices === []) {
            return;
        }

        foreach ($this->noticesBySource($notices) as $source => $sourceNotices) {
            callout(
                label: Str::plural('Notice', count($sourceNotices)),
                content: $this->noticesCalloutContent($sourceNotices),
                info: $source,
            );
        }
    }

    /**
     * Group notices by their source.
     *
     * @param list<DiagnosticOutcome> $notices
     * @return array<string, list<DiagnosticOutcome>>
     */
    protected function noticesBySource(array $notices): array
    {
        return collect($notices)
            ->groupBy(static fn (DiagnosticOutcome $notice): string => $notice->source()->label())
            ->map(static fn (Collection $notices): array => array_values($notices->all()))
            ->all();
    }

    /**
     * Format notice content for a callout.
     *
     * @param list<DiagnosticOutcome> $notices
     * @return list<ElementContract|string>
     */
    protected function noticesCalloutContent(array $notices): array
    {
        $content = [];

        foreach ($notices as $notice) {
            $content[] = $this->noticeItem($notice);

            foreach ($notice->result->links as $link) {
                $content[] = $this->promptLink($link);
            }
        }

        return $content;
    }

    /**
     * Format a notice as a compact list item.
     */
    protected function noticeItem(DiagnosticOutcome $outcome): string
    {
        $parts = [$outcome->result->summary];

        if ($outcome->result->details !== null) {
            $parts[] = $outcome->result->details;
        }

        if ($outcome->result->remediation !== null) {
            $parts[] = $outcome->result->remediation;
        }

        return Str::squish(implode(' ', $parts));
    }

    /**
     * Render diagnostic fixes as callouts grouped by source.
     *
     * @param list<DiagnosticFixOutcome> $fixes
     */
    protected function renderFixes(array $fixes): void
    {
        if ($fixes === []) {
            return;
        }

        foreach ($this->fixesBySource($fixes) as $source => $sourceFixes) {
            callout(
                label: count($sourceFixes) === 1 ? $sourceFixes[0]->diagnostic->name : 'Fixes',
                content: $this->fixesCalloutContent($sourceFixes),
                type: $this->fixesCalloutType($sourceFixes),
                info: $source,
            );
        }
    }

    /**
     * Group fixes by their source.
     *
     * @param list<DiagnosticFixOutcome> $fixes
     * @return array<string, list<DiagnosticFixOutcome>>
     */
    protected function fixesBySource(array $fixes): array
    {
        return collect($fixes)
            ->groupBy(static fn (DiagnosticFixOutcome $fix): string => $fix->source()->label())
            ->map(static fn (Collection $fixes): array => array_values($fixes->all()))
            ->all();
    }

    /**
     * Format fix content for a callout.
     *
     * @param list<DiagnosticFixOutcome> $fixes
     * @return list<ElementContract|string>
     */
    protected function fixesCalloutContent(array $fixes): array
    {
        if (count($fixes) === 1) {
            return [$this->fixSummary($fixes[0])];
        }

        return [Element::bulletedList(array_map(
            fn (DiagnosticFixOutcome $fix): string => $this->fixItem($fix),
            $fixes,
        ), spaced: true)];
    }

    /**
     * Format a fix outcome as a compact list item.
     */
    protected function fixItem(DiagnosticFixOutcome $outcome): string
    {
        return sprintf('%s: %s', $outcome->diagnostic->name, $this->fixSummary($outcome));
    }

    /**
     * Format a fix outcome's summary and details as a single line.
     */
    protected function fixSummary(DiagnosticFixOutcome $outcome): string
    {
        $parts = [$outcome->result->summary];

        if ($outcome->result->details !== null) {
            $parts[] = $outcome->result->details;
        }

        return Str::squish(implode(' ', $parts));
    }

    /**
     * Get the callout type for the fix outcomes.
     *
     * @param list<DiagnosticFixOutcome> $fixes
     */
    protected function fixesCalloutType(array $fixes): ?string
    {
        foreach ($fixes as $fix) {
            if ($fix->result->status->failed()) {
                return 'error';
            }
        }

        foreach ($fixes as $fix) {
            if ($fix->result->status === Status::Warn) {
                return 'warning';
            }
        }

        return null;
    }

    /**
     * Format diagnostic content for a callout.
     *
     * @return list<ElementContract|string>
     */
    protected function diagnosticCalloutContent(DiagnosticOutcome $outcome, bool $includeRemediation = true, ?DiagnosticFixOutcome $failedFix = null): array
    {
        $content = [$outcome->result->summary];

        if ($outcome->result->details !== null) {
            $content[] = Element::heading('Details');
            $content[] = $outcome->result->details;
        }

        if ($failedFix !== null) {
            $content[] = Element::heading('Fix failed');
            $content[] = $this->fixSummary($failedFix);
        }

        if ($includeRemediation && $failedFix === null && $outcome->result->remediation !== null) {
            $content[] = Element::heading('Suggested fix');
            $content[] = $outcome->result->remediation;
        }

        foreach ($outcome->result->links as $link) {
            $content[] = $this->promptLink($link);
        }

        return $content;
    }

    /**
     * Format fix confirmation content for a callout.
     *
     * @return list<ElementContract|string>
     */
    protected function fixConfirmationCalloutContent(DiagnosticOutcome $outcome): array
    {
        return $this->diagnosticCalloutContent($outcome, includeRemediation: false);
    }

    /**
     * Format a related link as a native Prompts element with a shortened URL.
     */
    protected function promptLink(Link $link): PromptLink
    {
        return Element::link(
            url: $link->url,
            label: Str::before(Str::after($link->url, '://'), '#'),
        );
    }
}
