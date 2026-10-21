<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\ElevenLabs;

use Hypervel\Ai\Ai;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Tests\Ai\TestCase;

class GatewayCachingTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.eleven.key', 'test-key');
    }

    public function testAudioGatewayIsMemoizedAcrossCalls(): void
    {
        $provider = Ai::audioProvider('eleven');

        $this->assertSame($provider->audioGateway(), $provider->audioGateway());
    }

    public function testTranscriptionGatewayIsMemoizedAcrossCalls(): void
    {
        $provider = Ai::transcriptionProvider('eleven');

        $this->assertSame($provider->transcriptionGateway(), $provider->transcriptionGateway());
    }
}
