<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client\Transport;

use Hypervel\Mcp\Client\Transport\HttpTransport;
use Hypervel\Mcp\Client\Transport\StdioTransport;
use Hypervel\Mcp\Client\Transport\TransportFactory;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Tests\Mcp\TestCase;

class TransportFactoryTest extends TestCase
{
    public function testRebuildsAStdioTransportFromItsRecipe(): void
    {
        $recipe = (new StdioTransport('node', ['server.js'], ['API_KEY' => 'secret']))->recipe();
        $recipe['timeoutSeconds'] = 12.0;

        $transport = TransportFactory::fromRecipe($recipe);

        $this->assertInstanceOf(StdioTransport::class, $transport);
        $this->assertSame([
            'driver' => 'stdio',
            'command' => 'node',
            'args' => ['server.js'],
            'env' => ['API_KEY' => 'secret'],
            'timeoutSeconds' => 12.0,
        ], $transport->recipe());
    }

    public function testRebuildsAnHttpTransportWithItsTokenAndTimeoutFromARecipe(): void
    {
        $source = new HttpTransport('https://mcp.test/mcp');
        $source->withToken('tok');
        $source->setTimeoutSeconds(7.5);

        $transport = TransportFactory::fromRecipe($source->recipe());

        $this->assertInstanceOf(HttpTransport::class, $transport);
        $this->assertSame([
            'driver' => 'http',
            'url' => 'https://mcp.test/mcp',
            'token' => 'tok',
            'headers' => [],
            'timeoutSeconds' => 7.5,
        ], $transport->recipe());
    }

    public function testRebuildsAnHttpTransportWithItsCustomHeadersFromARecipe(): void
    {
        $source = new HttpTransport('https://mcp.test/mcp');
        $source->withHeaders(['Authorization' => 'raw-api-key', 'X-Tenant-Id' => 'acme']);

        $transport = TransportFactory::fromRecipe($source->recipe());

        $this->assertInstanceOf(HttpTransport::class, $transport);
        $this->assertSame([
            'driver' => 'http',
            'url' => 'https://mcp.test/mcp',
            'token' => null,
            'headers' => ['Authorization' => 'raw-api-key', 'X-Tenant-Id' => 'acme'],
            'timeoutSeconds' => 30.0,
        ], $transport->recipe());
    }

    public function testThrowsForAnUnknownTransportDriver(): void
    {
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Unable to rebuild transport from an unknown recipe.');
        TransportFactory::fromRecipe(['driver' => 'carrier-pigeon']);
    }

    public function testThrowsWhenAStdioRecipeIsMissingItsCommand(): void
    {
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid stdio transport recipe.');
        TransportFactory::fromRecipe(['driver' => 'stdio', 'args' => []]);
    }

    public function testThrowsWhenAnHttpRecipeIsMissingItsUrl(): void
    {
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid http transport recipe.');
        TransportFactory::fromRecipe(['driver' => 'http']);
    }
}
