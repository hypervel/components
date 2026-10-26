<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Support\Facades\File;

class EnvironmentFileExists extends Diagnostic implements Fixable
{
    public string $name = '.env file exists';

    public string $group = 'environment';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'exists' => 'The selected environment file [{path}] exists.',
            'external' => 'The selected environment file [{path}] is absent; production may supply environment variables externally.',
            'missing' => Message::make(
                summary: 'The selected environment file [{path}] does not exist.',
                remediation: 'Create [{path}] with the application\'s environment variables.',
                confirmation: 'Create an empty environment file at [{path}]?',
            )->link(Link::docs('configuration', 'environment-configuration')),
            'missing-with-example' => Message::make(
                summary: 'The selected environment file [{path}] does not exist.',
                remediation: 'Copy [{example}] to [{path}], then review the copied values.',
                confirmation: 'Copy [{example}] to [{path}]?',
            )->link(Link::docs('configuration', 'environment-configuration')),
            'already-exists' => 'The environment file [{path}] already exists.',
            'creation-failed' => 'The environment file [{path}] could not be created from [{example}].',
            'created' => 'The environment file [{path}] was created from [{example}].',
            'empty-creation-failed' => 'The empty environment file [{path}] could not be created.',
            'empty-created' => 'An empty environment file [{path}] was created.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $path = app()->environmentFilePath();
        $example = app()->environmentPath() . '/.env.example';

        if (is_file($path)) {
            return $this->pass('exists', ['path' => $path]);
        }

        if (EnvironmentMode::current()->isProduction()) {
            return $this->skip('external', ['path' => $path]);
        }

        if (is_file($example)) {
            return $this->fail('missing-with-example', ['path' => $path, 'example' => $example])->fixable(EnvironmentMode::Local);
        }

        return $this->fail('missing', ['path' => $path])->fixable(EnvironmentMode::Local);
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        $target = app()->environmentFilePath();
        $example = app()->environmentPath() . '/.env.example';
        $replace = ['path' => $target, 'example' => $example];

        if (is_file($target)) {
            return $this->fixed('already-exists', $replace);
        }

        if (file_exists($target) || is_link($target)) {
            return $this->fixFailed('creation-failed', $replace);
        }

        if (! is_file($example)) {
            if (File::put($target, '') === false) {
                return $this->fixFailed('empty-creation-failed', $replace);
            }

            return $this->fixed('empty-created', $replace)
                ->withDetails('Add the application environment variables and run `php artisan key:generate`.');
        }

        if (! @copy($example, $target)) {
            return $this->fixFailed('creation-failed', $replace);
        }

        return $this->fixed('created', $replace)
            ->withDetails(sprintf('Review [%s] and run `php artisan key:generate` if APP_KEY is empty.', $target));
    }
}
