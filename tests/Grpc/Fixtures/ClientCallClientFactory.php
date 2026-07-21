<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc\Fixtures;

use Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface;
use Hypervel\Contracts\Engine\Http\V2\ClientInterface;
use LogicException;

class ClientCallClientFactory implements ClientFactoryInterface
{
    /** @var list<array{host: string, port: int, ssl: bool, settings: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<ClientCallClient> */
    private array $clients;

    private int $nextClient = 0;

    public function __construct(ClientCallClient ...$clients)
    {
        $this->clients = $clients;
    }

    /**
     * Create the configured fake HTTP/2 client.
     *
     * @param array<string, mixed> $settings
     */
    public function make(
        string $host,
        int $port = 80,
        bool $ssl = false,
        array $settings = [],
    ): ClientInterface {
        $this->calls[] = compact('host', 'port', 'ssl', 'settings');

        return $this->clients[$this->nextClient++] ?? throw new LogicException(
            'The fake HTTP/2 client factory has no client for this connection.',
        );
    }
}
