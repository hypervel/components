<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Http;

use Hypervel\Reverb\Protocols\Pusher\Http\Controllers\Controller;
use Hypervel\Tests\Reverb\ReverbTestCase;
use ReflectionClass;

class VerifiedRequestContextTest extends ReverbTestCase
{
    public function testControllerHasNoMutableRequestState()
    {
        $reflection = new ReflectionClass(Controller::class);
        $properties = collect($reflection->getProperties())
            ->filter(fn ($prop) => $prop->getDeclaringClass()->getName() === Controller::class)
            ->map(fn ($prop) => $prop->getName())
            ->all();

        // The Controller base class should have NO instance properties for
        // request state ($application, $body, $query, $channels).
        // All request state is returned via VerifiedRequestContext DTO.
        $this->assertNotContains('application', $properties);
        $this->assertNotContains('body', $properties);
        $this->assertNotContains('query', $properties);
        $this->assertNotContains('channels', $properties);
    }
}
