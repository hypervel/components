<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Console;

use Dotenv\Parser\Parser;
use Exception;
use Hypervel\Console\Command;
use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Support\Env;
use Hypervel\Support\Str;
use Sentry\Dsn;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'sentry:publish')]
class PublishCommand extends Command
{
    protected ?string $signature = <<<'COMMAND'
sentry:publish
    { --dsn= : The DSN to configure }
    { --without-test : Do not send a test event }
    { --with-send-default-pii : Include information such as request headers, IP address and the authenticated user to events collected by the SDK }
    { --without-performance-monitoring : Do not enable performance monitoring }
COMMAND;

    protected string $description = 'Publish and configure the Sentry config.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $arg = [];
        $env = [];

        $dsn = $this->option('dsn');

        if (! empty($dsn) || ! $this->isEnvKeySet('SENTRY_HYPERVEL_DSN')) {
            if (empty($dsn)) {
                $dsnFromInput = $this->askForDsnInput();

                if (empty($dsnFromInput)) {
                    $this->error('Please provide a valid DSN using the `--dsn` option or setting `SENTRY_HYPERVEL_DSN` in your `.env` file!');

                    return 1;
                }

                $dsn = $dsnFromInput;
            }

            $env['SENTRY_HYPERVEL_DSN'] = $dsn;
            $arg['--dsn'] = $dsn;
        }

        $sendDefaultPii = $this->confirm(
            "Do you want to include information such as request headers, IP address and the authenticated user to events collected by the SDK?\n You can read more about this on https://docs.sentry.io/platforms/php/guides/laravel/data-management/data-collected/",
            $this->option('with-send-default-pii') === true
        );

        if ($sendDefaultPii) {
            $env['SENTRY_SEND_DEFAULT_PII'] = 'true';
        } elseif ($this->isEnvKeySet('SENTRY_SEND_DEFAULT_PII')) {
            $env['SENTRY_SEND_DEFAULT_PII'] = 'false';
        }

        $testCommandPrompt = 'Do you want to send a test event to Sentry?';

        if ($this->confirm('Enable Performance Monitoring?', ! $this->option('without-performance-monitoring'))) {
            $testCommandPrompt = 'Do you want to send a test event & transaction to Sentry?';

            $env['SENTRY_TRACES_SAMPLE_RATE'] = '1.0';

            $arg['--transaction'] = true;
        } elseif ($this->isEnvKeySet('SENTRY_TRACES_SAMPLE_RATE')) {
            $env['SENTRY_TRACES_SAMPLE_RATE'] = '0';
        }

        if ($this->confirm($testCommandPrompt, ! $this->option('without-test'))) {
            $testResult = $this->call('sentry:test', $arg);

            if ($testResult === 1) {
                return 1;
            }
        }

        $this->info('Publishing Sentry config...');
        $this->call('vendor:publish', ['--provider' => SentryServiceProvider::class]);

        if (! $this->setEnvValues($env)) {
            return 1;
        }

        return 0;
    }

    /**
     * Write the given values to the `.env` file.
     */
    private function setEnvValues(array $values): bool
    {
        $envFilePath = app()->environmentFilePath();

        try {
            Env::writeVariables($values, $envFilePath, overwrite: true);
        } catch (Throwable $exception) {
            $this->error("Updating the `.env` file failed: {$exception->getMessage()}");

            return false;
        }

        foreach (array_keys($values) as $envKey) {
            $this->info("Set {$envKey} in your `.env` file.");
        }

        return true;
    }

    /**
     * Determine if the given key has a value in the `.env` file contents.
     */
    private function isEnvKeySet(string $envKey, ?string $envFileContents = null): bool
    {
        $envFileContents = $envFileContents ?? file_get_contents(app()->environmentFilePath());
        $value = null;

        // The loader uses the last assignment, and an empty value leaves the option unset.
        foreach ((new Parser)->parse($envFileContents) as $entry) {
            if ($entry->getName() === $envKey) {
                $value = $entry->getValue()->getOrElse(null);
            }
        }

        return $value !== null && $value->getChars() !== '';
    }

    /**
     * Ask the user for a valid DSN.
     */
    private function askForDsnInput(): string
    {
        if ($this->option('no-interaction')) {
            return '';
        }

        while (true) {
            $this->info('');

            $this->question('Please paste the DSN here');

            $dsn = $this->ask('DSN');

            // In case someone copies it with SENTRY_HYPERVEL_DSN= or SENTRY_DSN=
            $dsn = Str::after($dsn, '=');

            try {
                Dsn::createFromString($dsn);

                return $dsn;
            } catch (Exception) {
                // Not a valid DSN do it again
                $this->error('The DSN is not valid, please make sure to paste a valid DSN!');
            }
        }
    }
}
