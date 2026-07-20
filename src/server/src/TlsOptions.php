<?php

declare(strict_types=1);

namespace Hypervel\Server;

final readonly class TlsOptions
{
    /**
     * @param array<string, mixed> $settings
     */
    private function __construct(private array $settings)
    {
    }

    /**
     * Create TLS options from a Laravel-style stream context.
     */
    public static function fromArray(array $options): self
    {
        $settings = [];
        $map = [
            'local_cert' => 'ssl_cert_file',
            'local_pk' => 'ssl_key_file',
            'passphrase' => 'ssl_passphrase',
            'verify_peer' => 'ssl_verify_peer',
            'allow_self_signed' => 'ssl_allow_self_signed',
            'cafile' => 'ssl_client_cert_file',
            'ciphers' => 'ssl_ciphers',
            'crypto_method' => 'ssl_protocols',
        ];

        foreach ($options as $key => $value) {
            if ($value === null) {
                continue;
            }

            $key = (string) $key;

            if (isset($map[$key])) {
                $settings[$map[$key]] = $value;
            } elseif (str_starts_with($key, 'ssl_')) {
                $settings[$key] = $value;
            }
        }

        return new self($settings);
    }

    /**
     * Determine whether TLS is enabled.
     */
    public function enabled(): bool
    {
        return isset($this->settings['ssl_cert_file'])
            || isset($this->settings['ssl_key_file']);
    }

    /**
     * Apply TLS to a Swoole socket type when enabled.
     */
    public function socketType(int $type = SWOOLE_SOCK_TCP): int
    {
        return $this->enabled() ? $type | SWOOLE_SSL : $type;
    }

    /**
     * Get the translated Swoole settings.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->settings;
    }
}
