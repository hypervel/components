<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\Composer;
use Hypervel\Doctor\Support\Details;
use Hypervel\Support\Facades\Process;
use Hypervel\Support\Str;

class ComposerAuditPasses extends Diagnostic
{
    public string $name = 'Composer audit passes';

    public string $group = 'security';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'lock-missing' => 'The application does not have a composer.lock file.',
            'vulnerable' => Message::make(
                summary: 'Composer audit found vulnerable dependencies.',
                remediation: 'Update or replace the vulnerable packages reported by `composer audit`.',
            ),
            'audit-failed' => Message::make(
                summary: 'Composer audit could not be completed.',
                remediation: 'Run `composer audit` locally and resolve the reported issue.',
            ),
            'policy-failed' => Message::make(
                summary: 'Composer audit found dependencies that fail the configured policy.',
                remediation: 'Run `composer audit` and resolve the reported policy findings.',
            ),
            'clean' => 'Composer audit did not find vulnerable dependencies.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (! is_file(base_path('composer.lock'))) {
            return $this->skip('lock-missing');
        }

        $process = Process::path(base_path())->timeout(10)->run([
            ...Composer::command(),
            'audit',
            '--format=json',
            '--no-interaction',
        ]);

        $report = json_decode($process->output(), true);
        $completed = is_array($report) && is_array($report['advisories'] ?? null);
        $advisories = 0;

        foreach ($completed ? $report['advisories'] : [] as $packageAdvisories) {
            $advisories += count($packageAdvisories);
        }

        if ($advisories > 0) {
            return $this->fail('vulnerable')
                ->withDetails(sprintf(
                    '%d security %s %s reported.',
                    $advisories,
                    Str::plural('advisory', $advisories),
                    $advisories === 1 ? 'was' : 'were',
                ));
        }

        if (! $process->successful()) {
            if ($completed) {
                return $this->fail('policy-failed')
                    ->withDetails(Details::bullets($this->policyDetails($report)));
            }

            return $this->fail('audit-failed')
                ->withDetails(Details::processOutput(
                    $process->output(),
                    $process->errorOutput(),
                    'Composer exited without audit details.',
                ));
        }

        return $this->pass('clean');
    }

    /**
     * Describe the non-advisory findings reported by Composer.
     *
     * @return list<string>
     */
    private function policyDetails(array $report): array
    {
        $details = [];

        foreach ($report['abandoned'] ?? [] as $package => $replacement) {
            $details[] = "{$package} is abandoned."
                . ($replacement === null ? '' : " Suggested replacement: {$replacement}.");
        }

        foreach ($report['filter'] ?? [] as $package => $entries) {
            foreach ($entries as $entry) {
                $details[] = "{$package} matches filter list [{$entry['listName']}]."
                    . (isset($entry['reason']) ? " {$entry['reason']}" : '');
            }
        }

        return $details;
    }
}
