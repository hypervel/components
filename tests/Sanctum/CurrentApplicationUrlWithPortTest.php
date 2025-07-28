<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class CurrentApplicationUrlWithPortTest extends TestCase
{
    public function testCurrentApplicationUrlWithPort(): void
    {
        $this->app->get(ConfigInterface::class)->set('app.url', 'https://www.example.com:8080');

        $result = Sanctum::currentApplicationUrlWithPort();

        $this->assertEquals(',www.example.com:8080', $result);
    }

    public function testCurrentApplicationUrlWithoutPort(): void
    {
        $this->app->get(ConfigInterface::class)->set('app.url', 'https://www.example.com');

        $result = Sanctum::currentApplicationUrlWithPort();

        $this->assertEquals(',www.example.com', $result);
    }

    public function testCurrentApplicationUrlWhenNotSet(): void
    {
        $this->app->get(ConfigInterface::class)->set('app.url', null);

        $result = Sanctum::currentApplicationUrlWithPort();

        $this->assertEquals('', $result);
    }
}
