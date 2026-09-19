<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Dotenv\Parser\Lines;
use Exception;
use Hypervel\Console\Command;
use Hypervel\Encryption\Encrypter;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Str;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Prompts\password;
use function Hypervel\Prompts\select;

#[AsCommand(name: 'env:encrypt')]
class EnvironmentEncryptCommand extends Command
{
    protected ?string $signature = 'env:encrypt
                    {--key= : The encryption key}
                    {--cipher= : The encryption cipher}
                    {--env= : The environment to be encrypted}
                    {--readable : Encrypt each variable individually with readable names, updating existing files and preserving unchanged values}
                    {--prune : Delete the original environment file}
                    {--force : Re-encrypt all values, overwriting the existing encrypted environment file}';

    protected string $description = 'Encrypt an environment file';

    /**
     * Create a new command instance.
     */
    public function __construct(
        protected Filesystem $files,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $cipher = $this->option('cipher') ?: 'AES-256-CBC';

        $environmentFile = $this->option('env')
            ? Str::finish(dirname($this->hypervel->environmentFilePath()), DIRECTORY_SEPARATOR) . '.env.' . $this->option('env')
            : $this->hypervel->environmentFilePath();

        $encryptedFile = $environmentFile . '.encrypted';

        if (! $this->files->exists($environmentFile)) {
            $this->fail('Environment file not found.');
        }

        $encryptedFileExists = $this->files->exists($encryptedFile);

        $preserve = $this->option('readable') && $encryptedFileExists && ! $this->option('force');

        $key = $this->option('key');

        if (! $key && $this->input->isInteractive() && $preserve) {
            $key = password('What is the encryption key?');
        } elseif (! $key && $this->input->isInteractive()) {
            $ask = select(
                label: 'What encryption key would you like to use?',
                options: [
                    'generate' => 'Generate a random encryption key',
                    'ask' => 'Provide an encryption key',
                ],
                default: 'generate'
            );

            if ($ask === 'ask') {
                $key = password('What is the encryption key?');
            }
        }

        if ($preserve && ($key === null || $key === '')) {
            $this->fail('The existing encryption key is required to update the encrypted environment file.');
        }

        $keyPassed = $key !== null;

        if (! $keyPassed) {
            $key = Encrypter::generateKey($cipher);
        }

        if ($encryptedFileExists && ! $this->option('force') && ! $preserve) {
            $this->fail('Encrypted environment file already exists.');
        }

        try {
            $encrypter = new Encrypter($this->parseKey($key), $cipher);

            $contents = $this->files->get($environmentFile);

            $previous = $preserve
                ? $this->files->get($encryptedFile)
                : null;

            $encrypted = $this->option('readable')
                ? $this->encryptWhileMaintainingReadability($contents, $encrypter, $previous)
                : $encrypter->encrypt($contents);

            if ($encrypted !== $previous) {
                $mode = null;

                if ($encryptedFileExists) {
                    $permissions = $this->files->chmod($encryptedFile);

                    if (! is_string($permissions)) {
                        throw new RuntimeException("Unable to determine permissions for [{$encryptedFile}].");
                    }

                    $mode = octdec($permissions);
                }

                $this->files->replace($encryptedFile, $encrypted, $mode);
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Exception $e) {
            $this->fail($e->getMessage());
        }

        if ($this->option('prune')
            && ! $this->files->delete($environmentFile)
            && $this->files->exists($environmentFile)) {
            $this->fail("Unable to delete the environment file [{$environmentFile}].");
        }

        $this->components->info('Environment successfully encrypted.');

        $this->components->twoColumnDetail('Key', $keyPassed ? $key : 'base64:' . base64_encode($key));
        $this->components->twoColumnDetail('Cipher', $cipher);
        $this->components->twoColumnDetail('Encrypted file', $encryptedFile);

        $this->newLine();
    }

    /**
     * Encrypt the environment file in readable format.
     */
    protected function encryptWhileMaintainingReadability(string $contents, Encrypter $encrypter, ?string $previous = null): string
    {
        $result = '';
        $existing = [];
        $previousOutput = '';

        foreach ($previous === null ? [] : $this->readEncryptedEntries($previous, $encrypter) as $entry) {
            $existing[$entry['name']][] = $entry;
            $previousOutput .= $entry['name'] . '=' . $entry['encrypted'] . "\n";
        }

        foreach (Lines::process(preg_split('/\r\n|\r|\n/', $contents)) as $entry) {
            $pos = strpos($entry, '=');

            if ($pos === false) {
                continue;
            }

            $name = substr($entry, 0, $pos);
            $value = substr($entry, $pos + 1);

            $existingEntry = null;

            foreach ($existing[$name] ?? [] as $index => $candidate) {
                if ($candidate['value'] === $value) {
                    $existingEntry = $candidate;
                    unset($existing[$name][$index]);

                    break;
                }
            }

            $result .= $name . '=' . ($existingEntry !== null
                ? $existingEntry['encrypted']
                : $encrypter->encryptString($value)) . "\n";
        }

        return $previous !== null && $result === $previousOutput ? $previous : $result;
    }

    /**
     * Authenticate the existing readable entries, preserving order and duplicate names.
     *
     * @return list<array{name: string, value: string, encrypted: string}>
     */
    protected function readEncryptedEntries(string $contents, Encrypter $encrypter): array
    {
        if (Encrypter::appearsEncrypted($contents)) {
            $this->fail('The existing encrypted environment file is not in readable format. Use --force to overwrite it.');
        }

        $entries = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) as $index => $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            $pos = strpos($line, '=');

            if ($pos === false || $pos === 0) {
                $this->fail('Invalid encrypted environment entry on line ' . ($index + 1) . '.');
            }

            $encrypted = substr($line, $pos + 1);

            try {
                $value = $encrypter->decryptString($encrypted);
            } catch (Exception $e) {
                $this->fail('Unable to decrypt the encrypted environment entry on line ' . ($index + 1) . '.');
            }

            $name = substr($line, 0, $pos);

            $entries[] = compact('name', 'value', 'encrypted');
        }

        return $entries;
    }

    /**
     * Parse the encryption key.
     */
    protected function parseKey(string $key): string
    {
        if (Str::startsWith($key, $prefix = 'base64:')) {
            $key = base64_decode(Str::after($key, $prefix));
        }

        return $key;
    }
}
