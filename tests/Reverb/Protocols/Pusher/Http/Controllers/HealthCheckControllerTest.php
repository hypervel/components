<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Tests\Reverb\ReverbTestCase;

/**
 * @internal
 * @coversNothing
 */
class HealthCheckControllerTest extends ReverbTestCase
{
    public function testCanRespondToAHealthCheckRequest()
    {
        $response = $this->reverbGet('/up');

        $response->assertStatus(200);
        $this->assertSame('{"health":"OK"}', $response->getContent());
    }

    public function testDoesNotRequireAuthentication()
    {
        // No signed request needed — health check is unauthenticated
        $response = $this->reverbGet('/up');

        $response->assertStatus(200);
    }
}
