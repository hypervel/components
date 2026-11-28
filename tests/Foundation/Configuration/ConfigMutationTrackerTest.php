<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Configuration;

use Hypervel\Config\Repository;
use Hypervel\Foundation\Configuration\ConfigMutationTracker;
use Hypervel\Tests\TestCase;
use RuntimeException;

class ConfigMutationTrackerTest extends TestCase
{
    public function testCollectsDistinctDirectKeysAndIgnoresProviderConfiguration(): void
    {
        $tracker = new ConfigMutationTracker;
        $config = new Repository;
        $tracker->observe($config);
        $config->set('app.name', 'first');
        $config->set('app.name', 'second');

        $exception = new RuntimeException('Invalid package configuration.');
        try {
            $tracker->withoutRecording($config, function (Repository $repository) use ($tracker, $exception): void {
                $tracker->withoutRecording($repository, fn () => $repository->set('inner', 1));
                $repository->set('outer', 2);
                throw $exception;
            });
            $this->fail('Provider configuration should have failed.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $config->set(['testing.value' => 1, 'app.name' => 'final']);
        $tracker->stopObserving();
        $config->set('runtime', true);

        $this->assertSame(['app.name', 'testing.value'], $tracker->keys());
    }
}
