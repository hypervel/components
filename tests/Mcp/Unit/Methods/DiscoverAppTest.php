<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Methods\DiscoverAppTest;

use Hypervel\Mcp\Enums\Extension;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\AppResource;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Tests\Mcp\Fixtures\ArrayTransport;
use Hypervel\Tests\Mcp\TestCase;

class DiscoverAppTest extends TestCase
{
    public function testAdvertisesTheUiExtensionWhenAppResourcesAreRegistered(): void
    {
        $server = new class(new ArrayTransport) extends Server {
            protected array $resources = [
                AutoDetectAppResource::class,
            ];
        };

        $server->start();

        $extensions = $server->createContext()->serverCapabilities['extensions'];

        $this->assertArrayHasKey('io.modelcontextprotocol/ui', $extensions);
        $this->assertEquals((object) [], $extensions['io.modelcontextprotocol/ui']);
    }

    public function testAdvertisesNoExtensionsWhenOnlyRegularResourcesAreRegistered(): void
    {
        $server = new class(new ArrayTransport) extends Server {
            protected array $resources = [
                RegularResource::class,
            ];
        };

        $server->start();

        $this->assertArrayNotHasKey('extensions', $server->createContext()->serverCapabilities);
    }

    public function testAdvertisesTheExtensionsDeclaredOnTheServer(): void
    {
        $server = new class(new ArrayTransport) extends Server {
            protected array $extensions = [
                Extension::Ui,
            ];
        };

        $extensions = $server->createContext()->serverCapabilities['extensions'];

        $this->assertArrayHasKey('io.modelcontextprotocol/ui', $extensions);
    }
}

class AutoDetectAppResource extends AppResource
{
    /**
     * Return the app HTML.
     */
    public function handle(Request $request): Response
    {
        return Response::text('<html></html>');
    }
}

class RegularResource extends Resource
{
    protected string $uri = 'file://resources/regular';

    protected string $mimeType = 'text/plain';

    /**
     * Return the plain resource content.
     */
    public function handle(): string
    {
        return 'plain content';
    }
}
