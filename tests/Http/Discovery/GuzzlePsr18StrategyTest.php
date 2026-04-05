<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Discovery;

use GuzzleHttp\Client as GuzzleClient;
use Hypervel\Http\Discovery\GuzzlePsr18Strategy;
use Hypervel\Tests\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * @internal
 * @coversNothing
 */
class GuzzlePsr18StrategyTest extends TestCase
{
    public function testReturnsGuzzleForPsr18ClientInterface()
    {
        $candidates = GuzzlePsr18Strategy::getCandidates(ClientInterface::class);

        $this->assertCount(1, $candidates);
        $this->assertSame(GuzzleClient::class, $candidates[0]['class']);
        $this->assertSame(GuzzleClient::class, $candidates[0]['condition']);
    }

    public function testReturnsEmptyArrayForOtherTypes()
    {
        $this->assertSame([], GuzzlePsr18Strategy::getCandidates(RequestFactoryInterface::class));
        $this->assertSame([], GuzzlePsr18Strategy::getCandidates('SomeRandomClass'));
    }
}
