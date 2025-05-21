<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Exception;
use Hyperf\Collection\Collection;
use Hypervel\HttpClient\Factory as HttpClientFactory;
use Hypervel\Support\Stringable;
use Hypervel\Validation\Contracts\UncompromisedVerifier;

class NotPwnedVerifier implements UncompromisedVerifier
{
    /**
     * Create a new uncompromised verifier.
     *
     * @param HttpClientFactory $factory the HTTP factory instance
     * @param int $timeout the number of seconds the request can run before timing out
     */
    public function __construct(
        protected HttpClientFactory $factory,
        protected int $timeout = 30
    ) {
    }

    /**
     * Verify that the given data has not been compromised in public breaches.
     */
    public function verify(array $data): bool
    {
        $value = $data['value'];
        $threshold = $data['threshold'];

        if (empty($value = (string) $value)) {
            return false;
        }

        [$hash, $hashPrefix] = $this->getHash($value);

        return ! $this->search($hashPrefix)
            ->contains(function ($line) use ($hash, $hashPrefix, $threshold) {
                [$hashSuffix, $count] = explode(':', $line);

                return $hashPrefix . $hashSuffix == $hash && $count > $threshold;
            });
    }

    /**
     * Get the hash and its first 5 chars.
     */
    protected function getHash(string $value): array
    {
        $hash = strtoupper(sha1((string) $value));

        $hashPrefix = substr($hash, 0, 5);

        return [$hash, $hashPrefix];
    }

    /**
     * Search by the given hash prefix and returns all occurrences of leaked passwords.
     */
    protected function search(string $hashPrefix): Collection
    {
        try {
            $response = $this->factory->withHeaders([
                'Add-Padding' => true,
            ])->timeout($this->timeout)->get(
                'https://api.pwnedpasswords.com/range/' . $hashPrefix
            );
        } catch (Exception $e) {
            report($e);
        }

        $body = (isset($response) && $response->successful())
            ? $response->body()
            : '';

        return (new Stringable($body))->trim()->explode("\n")->filter(function ($line) {
            return str_contains($line, ':');
        });
    }
}
