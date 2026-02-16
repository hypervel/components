<?php

declare(strict_types=1);

namespace Hypervel\Encryption\Commands;

use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Encryption\Encrypter;
use Symfony\Component\Console\Input\InputOption;

class KeyGenerateCommand extends Command
{
    use ConfirmableTrait;

    public function __construct(
        protected Container $container,
        protected Repository $config
    ) {
        parent::__construct('key:generate');
    }

    public function handle()
    {
        $key = $this->generateRandomKey();

        if ($this->option('show')) {
            return $this->line('<comment>' . $key . '</comment>');
        }

        // Next, we will replace the application key in the environment file so it is
        // automatically setup for this developer. This key gets generated using a
        // secure random byte generator and is later base64 encoded for storage.
        if (! $this->setKeyInEnvironmentFile($key)) {
            return;
        }

        $this->config->set('app.key', $key);

        $this->info('Application key set successfully.');
    }

    protected function configure()
    {
        $this->setDescription('Set the application key')
            ->addOption('show', null, InputOption::VALUE_OPTIONAL, 'Display the key instead of modifying files')
            ->addOption('force', null, InputOption::VALUE_OPTIONAL, 'Force the operation to run when in production');
    }

    /**
     * Generate a random key for the application.
     */
    protected function generateRandomKey(): string
    {
        $cipher = $this->config->get('app.cipher')
            ?: $this->config->get('encryption.cipher');

        return 'base64:' . base64_encode(
            Encrypter::generateKey($cipher)
        );
    }

    /**
     * Set the application key in the environment file.
     */
    protected function setKeyInEnvironmentFile(string $key): bool
    {
        $currentKey = $this->config->get('app.key')
            ?: $this->config->get('encryption.key');

        if (strlen($currentKey ?: '') !== 0 && (! $this->confirmToProceed())) {
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
            $input = file_get_contents($envPath = BASE_PATH . DIRECTORY_SEPARATOR . '.env')
        );

        if ($replaced === $input || $replaced === null) {
            $this->error('Unable to set application key. No APP_KEY variable was found in the .env file.');

            return false;
        }

        file_put_contents($envPath, $replaced);

        return true;
    }

    /**
     * Get a regex pattern that will match env APP_KEY with any random key.
     */
    protected function keyReplacementPattern(): string
    {
        $key = $this->config->get('app.key')
            ?: $this->config->get('encryption.key');

        $escaped = preg_quote('=' . $key, '/');

        return "/^APP_KEY{$escaped}/m";
    }
}
