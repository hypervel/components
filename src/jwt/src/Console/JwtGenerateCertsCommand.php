<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Console;

use Hypervel\Console\Command;
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
    public function handle(): int
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

        if ($details === false || ! is_string($details['key'] ?? null)) {
            throw new RuntimeException('Unable to export JWT public key.');
        }

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory [{$directory}].");
        }

        if (file_put_contents($privateKeyPath, $privateKey) === false) {
            throw new RuntimeException("Unable to write private key to [{$privateKeyPath}].");
        }

        if (! chmod($privateKeyPath, 0600)) {
            throw new RuntimeException("Unable to secure private key [{$privateKeyPath}].");
        }

        if (file_put_contents($publicKeyPath, $details['key']) === false) {
            throw new RuntimeException("Unable to write public key to [{$publicKeyPath}].");
        }

        Env::writeVariables([
            'JWT_ALGO' => $algorithmIdentifier,
            'JWT_PRIVATE_KEY' => 'file://' . $privateKeyPath,
            'JWT_PUBLIC_KEY' => 'file://' . $publicKeyPath,
            'JWT_PASSPHRASE' => $passphrase ?? '',
        ], $environmentFile, overwrite: true);

        $this->components->info('JWT certificates generated successfully.');

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
            return $this->secret('Passphrase') ?: null;
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
