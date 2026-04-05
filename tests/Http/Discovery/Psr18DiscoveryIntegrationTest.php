<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Discovery;

use GuzzleHttp\Client as GuzzleClient;
use Http\Discovery\Psr18ClientDiscovery;
use Hypervel\Http\Discovery\GuzzlePsr18Strategy;
use Hypervel\Http\HttpServiceProvider;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class Psr18DiscoveryIntegrationTest extends TestCase
{
    public function testDiscoveryReturnsGuzzleNotSymfony()
    {
        $client = Psr18ClientDiscovery::find();

        $this->assertInstanceOf(GuzzleClient::class, $client);
    }

    public function testStrategyIsRegisteredByHttpServiceProvider()
    {
        // HttpServiceProvider runs during app boot, so the strategy
        // should already be registered by the time we get here.
        $provider = $this->app->getProvider(HttpServiceProvider::class);

        $this->assertNotNull($provider);
        $this->assertContains(
            GuzzlePsr18Strategy::class,
            \Http\Discovery\ClassDiscovery::getStrategies()
        );
    }
}
