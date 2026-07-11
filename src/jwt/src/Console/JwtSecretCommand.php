<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Env;
use Hypervel\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'jwt:secret')]
class JwtSecretCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'jwt:secret
        {--s|show : Display the key instead of modifying files}
        {--always-no : Skip generating key if it already exists}
        {--f|force : Skip confirmation when overwriting an existing key}';

    /**
     * The console command description.
     */
    protected string $description = 'Set the JWT secret key used to sign tokens';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $key = Str::random(64);

        if ($this->option('show')) {
            $this->comment($key);

            return self::SUCCESS;
        }

        $environmentFile = $this->hypervel->environmentFilePath();

        if (! file_exists($environmentFile)) {
            $this->error("The file [{$environmentFile}] does not exist.");

            return self::FAILURE;
        }

        if ($this->envHasKey($environmentFile, 'JWT_SECRET') && ! $this->option('force')) {
            if ($this->option('always-no')) {
                $this->comment('JWT secret already exists. Skipping...');

                return self::SUCCESS;
            }

            if (! $this->confirm('This will invalidate all existing tokens. Are you sure you want to override the JWT secret?')) {
                $this->comment('No changes were made to your JWT secret.');

                return self::SUCCESS;
            }
        }

        Env::writeVariables([
            'JWT_SECRET' => $key,
            'JWT_ALGO' => 'HS256',
        ], $environmentFile, overwrite: true);

        $this->components->info('JWT secret set successfully.');

        return self::SUCCESS;
    }

    /**
     * Determine if an environment variable exists.
     */
    protected function envHasKey(string $environmentFile, string $key): bool
    {
        $contents = file_get_contents($environmentFile);

        if ($contents === false) {
            throw new RuntimeException('Unable to read environment file.');
        }

        return preg_match('/^' . preg_quote($key, '/') . '=/m', $contents) === 1;
    }
}
