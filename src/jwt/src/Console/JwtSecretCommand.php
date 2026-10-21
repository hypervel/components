<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Console;

use Dotenv\Parser\Parser;
use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Env;
use Hypervel\Support\Str;
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
    public function handle(Filesystem $files): int
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

        if ($this->hasSecret($files->get($environmentFile))) {
            if ($this->option('always-no')) {
                $this->comment('JWT secret already exists. Skipping...');

                return self::SUCCESS;
            }

            if (! $this->option('force') && ! $this->confirm('This will invalidate all existing tokens. Are you sure you want to override the JWT secret?')) {
                $this->comment('No changes were made to your JWT secret.');

                return self::SUCCESS;
            }
        }

        Env::writeVariables([
            'JWT_SECRET' => $key,
        ], $environmentFile, overwrite: true);

        $this->components->info('JWT secret set successfully.');
        $this->components->warn(
            'Restart the server and every other long-running application process, including queue workers and custom server processes, '
            . 'before issuing tokens with the new secret. The [php artisan server:reload] command only replaces server workers and is not sufficient.'
        );

        return self::SUCCESS;
    }

    /**
     * Determine if the environment file assigns a JWT secret.
     */
    protected function hasSecret(string $contents): bool
    {
        $secret = null;

        // Values are not resolved, so a reference such as "${OTHER}" still counts as a secret.
        foreach ((new Parser)->parse($contents) as $entry) {
            if ($entry->getName() === 'JWT_SECRET') {
                $secret = $entry->getValue()->getOrElse(null);
            }
        }

        return $secret !== null && $secret->getChars() !== '';
    }
}
