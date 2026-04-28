<?php

declare(strict_types=1);

namespace Hypervel\Encryption\Commands;

use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Encryption\Encrypter;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'key:generate')]
class KeyGenerateCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'key:generate
                    {--show : Display the key instead of modifying files}
                    {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     */
    protected string $description = 'Set the application key';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $key = $this->generateRandomKey();

        if ($this->option('show')) {
            return $this->line('<comment>' . $key . '</comment>'); // @phpstan-ignore method.void
        }

        // Next, we will replace the application key in the environment file so it is
        // automatically setup for this developer. This key gets generated using a
        // secure random byte generator and is later base64 encoded for storage.
        if (! $this->setKeyInEnvironmentFile($key)) {
            return;
        }

        $this->hypervel['config']['app.key'] = $key;

        $this->components->info('Application key set successfully.');
    }

    /**
     * Generate a random key for the application.
     */
    protected function generateRandomKey(): string
    {
        return 'base64:' . base64_encode(
            Encrypter::generateKey($this->hypervel['config']['app.cipher'])
        );
    }

    /**
     * Set the application key in the environment file.
     */
    protected function setKeyInEnvironmentFile(string $key): bool
    {
        $currentKey = $this->hypervel['config']['app.key'];

        if (strlen($currentKey) !== 0 && (! $this->confirmToProceed())) {
            return false;
        }

        if (! $this->writeNewEnvironmentFileWith($key)) {
            return false;
        }

        return true;
    }

    /**
     * Write a new environment file with the given key.
     */
    protected function writeNewEnvironmentFileWith(string $key): bool
    {
        $replaced = preg_replace(
            $this->keyReplacementPattern(),
            'APP_KEY=' . $key,
            $input = file_get_contents($this->hypervel->environmentFilePath())
        );

        if ($replaced === $input || $replaced === null) {
            if (isset($_ENV['APP_KEY'])) {
                $this->components->error('Unable to set application key. APP_KEY is already present in the environment.');
            } else {
                $this->components->error('Unable to set application key. No APP_KEY variable was found in the .env file.');
            }

            return false;
        }

        file_put_contents($this->hypervel->environmentFilePath(), $replaced);

        return true;
    }

    /**
     * Get a regex pattern that will match env APP_KEY with any random key.
     */
    protected function keyReplacementPattern(): string
    {
        $escaped = preg_quote('=' . $this->hypervel['config']['app.key'], '/');

        return "/^APP_KEY{$escaped}/m";
    }
}
