<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Composer\Semver\Semver;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\ComposerJson;
use Hypervel\Doctor\Support\InstalledPackages;

class PhpVersionSatisfiesComposerRequirement extends Diagnostic
{
    public string $name = 'PHP satisfies Composer';

    public string $group = 'environment';

    /**
     * Create a new diagnostic instance.
     */
    public function __construct(protected ComposerJson $composer)
    {
        parent::__construct();
    }

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'constraint-missing' => 'No applicable Composer PHP version constraints were found.',
            'satisfied' => 'The current PHP version satisfies the application and installed packages.',
            'unsatisfied' => Message::make(
                summary: 'PHP {version} does not satisfy the application or installed package requirements.',
                remediation: 'Use a compatible PHP version or install dependency versions that support this PHP version.',
            )->link(Link::docs('deployment', 'server-requirements')),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $requirements = app(InstalledPackages::class)->platformRequirements($this->composer)['php']['requirements'] ?? [];

        if ($requirements === []) {
            return $this->skip('constraint-missing');
        }

        $unsatisfied = [];

        foreach ($requirements as $package => $constraint) {
            if (! Semver::satisfies(PHP_VERSION, $constraint)) {
                $unsatisfied[] = "- {$package} requires php {$constraint}.";
            }
        }

        if ($unsatisfied === []) {
            return $this->pass('satisfied');
        }

        return $this->fail('unsatisfied', [
            'version' => PHP_VERSION,
        ])->withDetails(implode(PHP_EOL, $unsatisfied));
    }
}
