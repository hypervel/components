<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Dotenv\Parser\Lines;
use Exception;
use Hypervel\Console\Command;
use Hypervel\Encryption\Encrypter;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Env;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Prompts\password;

#[AsCommand(name: 'env:decrypt')]
class EnvironmentDecryptCommand extends Command
{
    protected ?string $signature = 'env:decrypt
                    {--key= : The encryption key}
                    {--cipher= : The encryption cipher}
                    {--env= : The environment to be decrypted}
                    {--force : Overwrite the existing environment file}
                    {--path= : Path to write the decrypted file}
                    {--filename= : Filename of the decrypted file}';

    protected string $description = 'Decrypt an environment file';

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
    public function handle()
    {
        $key = $this->option('key') ?: Env::get('HYPERVEL_ENV_ENCRYPTION_KEY');

        if (! $key && $this->input->isInteractive()) {
            $key = password('What is the decryption key?');
        }

        if (! $key) {
            $this->fail('A decryption key is required.');
        }

        $cipher = $this->option('cipher') ?: 'AES-256-CBC';

        $key = $this->parseKey($key);

        $encryptedFile = ($this->option('env')
            ? Str::finish(dirname($this->hypervel->environmentFilePath()), DIRECTORY_SEPARATOR) . '.env.' . $this->option('env')
            : $this->hypervel->environmentFilePath()) . '.encrypted';

        $outputFile = $this->outputFilePath();

        if (Str::endsWith($outputFile, '.encrypted')) {
            $this->fail('Invalid filename.');
        }

        if (! $this->files->exists($encryptedFile)) {
            $this->fail('Encrypted environment file not found.');
        }

        if ($this->files->exists($outputFile) && ! $this->option('force')) {
            $this->fail('Environment file already exists.');
        }

        try {
            $encrypter = new Encrypter($key, $cipher);

            $encryptedContents = $this->files->get($encryptedFile);

            $decrypted = $this->isReadableFormat($encryptedContents)
                ? $this->decryptReadableFormat($encryptedContents, $encrypter)
                : $encrypter->decrypt($encryptedContents);

            $this->files->put($outputFile, $decrypted);
        } catch (Exception $e) {
            $this->fail($e->getMessage());
        }

        $this->components->info('Environment successfully decrypted.');

        $this->components->twoColumnDetail('Decrypted file', $outputFile);

        $this->newLine();
    }

    /**
     * Determine if the content is in readable format where each variable still has its own plain-text key.
     */
    protected function isReadableFormat(string $contents): bool
    {
        return ! Encrypter::appearsEncrypted($contents);
    }

    /**
     * Decrypt the environment file from readable format.
     */
    protected function decryptReadableFormat(string $contents, Encrypter $encrypter): string
    {
        $result = '';

        foreach (Lines::process(preg_split('/\r\n|\r|\n/', $contents)) as $entry) {
            $pos = strpos($entry, '=');

            if ($pos === false) {
                continue;
            }

            $name = substr($entry, 0, $pos);
            $encryptedValue = substr($entry, $pos + 1);

            $result .= $name . '=' . $encrypter->decryptString($encryptedValue) . "\n";
        }

        return $result;
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

    /**
     * Get the output file path that should be used for the command.
     */
    protected function outputFilePath(): string
    {
        $path = Str::finish($this->option('path') ?: dirname($this->hypervel->environmentFilePath()), DIRECTORY_SEPARATOR);

        $outputFile = $this->option('filename') ?: ('.env' . ($this->option('env') ? '.' . $this->option('env') : ''));
        $outputFile = ltrim($outputFile, DIRECTORY_SEPARATOR);

        return $path . $outputFile;
    }
}
