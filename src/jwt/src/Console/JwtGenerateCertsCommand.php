<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Env;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'jwt:generate-certs')]
class JwtGenerateCertsCommand extends Command
{
    /**
     * EC curves required by the JWA ES algorithms.
     */
    protected const array EC_CURVES = [
        256 => 'prime256v1',
        384 => 'secp384r1',
        512 => 'secp521r1',
    ];

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'jwt:generate-certs
        {--force : Override certificates if they already exist}
        {--algo=rsa : Algorithm: rsa or ec}
        {--bits=4096 : RSA key length}
        {--sha=512 : SHA variant}
        {--dir=storage/certs : Directory where certificates should be written}
        {--curve=prime256v1 : EC curve name}
        {--passphrase= : Passphrase}
        {--ask-passphrase : Prompt for the passphrase}';

    /**
     * The console command description.
     */
    protected string $description = 'Generate a JWT certificate pair';

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $files): int
    {
        $directory = $this->resolvePath((string) $this->option('dir'));
        $algorithm = strtolower((string) $this->option('algo'));
        $bits = (int) $this->option('bits');
        $sha = (int) $this->option('sha');
        $curve = (string) $this->option('curve');
        $passphrase = $this->resolvePassphrase();
        $environmentFile = $this->hypervel->environmentFilePath();

        if (! file_exists($environmentFile)) {
            $this->error("The file [{$environmentFile}] does not exist.");

            return self::FAILURE;
        }

        $this->validateSha($sha);

        [$keyType, $algorithmIdentifier] = match ($algorithm) {
            'rsa' => [OPENSSL_KEYTYPE_RSA, sprintf('RS%d', $sha)],
            'ec' => [OPENSSL_KEYTYPE_EC, sprintf('ES%d', $sha)],
            default => throw new RuntimeException('Unknown JWT certificate algorithm.'),
        };

        if ($keyType === OPENSSL_KEYTYPE_RSA && $bits < 2048) {
            throw new RuntimeException('JWT RSA certificates must use at least 2048 bits.');
        }

        if ($keyType === OPENSSL_KEYTYPE_EC) {
            $this->validateEcCurve($sha, $curve);
        }

        $keyIdentifier = $keyType === OPENSSL_KEYTYPE_EC ? $curve : (string) $bits;
        $privateKeyPath = sprintf('%s/jwt-%s-%s-private.pem', $directory, $algorithm, $keyIdentifier);
        $publicKeyPath = sprintf('%s/jwt-%s-%s-public.pem', $directory, $algorithm, $keyIdentifier);

        if (! $this->option('force') && (file_exists($privateKeyPath) || file_exists($publicKeyPath))) {
            $this->error('JWT certificates already exist. Use --force to overwrite them.');

            return self::FAILURE;
        }

        $options = [
            'digest_alg' => sprintf('sha%d', $sha),
            'private_key_type' => $keyType,
        ];

        if ($keyType === OPENSSL_KEYTYPE_RSA) {
            $options['private_key_bits'] = $bits;
        } else {
            $options['curve_name'] = $curve;
        }

        $key = openssl_pkey_new($options);

        if ($key === false) {
            throw new RuntimeException('Unable to create JWT key pair.');
        }

        $privateKey = '';

        if (! openssl_pkey_export($key, $privateKey, $passphrase)) {
            throw new RuntimeException('Unable to export JWT private key.');
        }

        $details = openssl_pkey_get_details($key);
        $publicKey = $details === false ? null : ($details['key'] ?? null);

        if (! is_string($publicKey)) {
            throw new RuntimeException('Unable to export JWT public key.');
        }

        $files->ensureDirectoryExists($directory);
        $files->replace($privateKeyPath, $privateKey, 0600);
        $files->replace($publicKeyPath, $publicKey, 0644);

        Env::writeVariables([
            'JWT_ALGO' => $algorithmIdentifier,
            'JWT_PRIVATE_KEY' => 'file://' . $privateKeyPath,
            'JWT_PUBLIC_KEY' => 'file://' . $publicKeyPath,
            'JWT_PASSPHRASE' => $passphrase ?? '',
        ], $environmentFile, overwrite: true);

        $this->components->info('JWT certificates generated successfully.');
        $this->components->warn(
            'Restart the server and every other long-running application process, including queue workers and custom server processes, '
            . 'before issuing tokens with the new certificate pair. The [php artisan server:reload] command only replaces server workers and is not sufficient.'
        );

        return self::SUCCESS;
    }

    /**
     * Validate the SHA variant.
     */
    protected function validateSha(int $sha): void
    {
        if (! in_array($sha, [256, 384, 512], true)) {
            throw new RuntimeException('JWT certificate SHA variant must be 256, 384, or 512.');
        }
    }

    /**
     * Validate the EC curve against the chosen ES algorithm.
     */
    protected function validateEcCurve(int $sha, string $curve): void
    {
        $requiredCurve = self::EC_CURVES[$sha];

        if ($curve !== $requiredCurve) {
            throw new RuntimeException("ES{$sha} requires the [{$requiredCurve}] curve.");
        }
    }

    /**
     * Resolve the passphrase option.
     */
    protected function resolvePassphrase(): ?string
    {
        if ($this->option('ask-passphrase')) {
            $passphrase = $this->secret('Passphrase');

            return $passphrase !== null && $passphrase !== '' ? $passphrase : null;
        }

        $passphrase = $this->option('passphrase');

        return is_string($passphrase) && $passphrase !== '' ? $passphrase : null;
    }

    /**
     * Resolve a path relative to the application base path.
     */
    protected function resolvePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $this->hypervel->basePath($path);
    }
}
