<?php

declare(strict_types=1);

namespace Hypervel\Scout\Services\Turbopuffer;

class TurbopufferNamespace
{
    /**
     * Create a new Turbopuffer namespace instance.
     */
    public function __construct(
        protected TurbopufferClient $client,
        protected string $name
    ) {
    }

    /**
     * Query the namespace.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function query(array $parameters): array
    {
        return $this->client->request('POST', $this->uri() . '/query', ['json' => $parameters]);
    }

    /**
     * Write documents to the namespace.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function write(array $parameters): array
    {
        return $this->client->request('POST', $this->uri(), ['json' => $parameters]);
    }

    /**
     * Delete the namespace.
     *
     * @return array<string, mixed>
     */
    public function delete(): array
    {
        return $this->client->request('DELETE', $this->uri());
    }

    /**
     * Get the namespace API URI.
     */
    protected function uri(): string
    {
        return '/v2/namespaces/' . rawurlencode($this->name);
    }
}
