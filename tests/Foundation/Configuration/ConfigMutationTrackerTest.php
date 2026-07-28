<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Configuration;

use Hypervel\Config\Repository;
use Hypervel\Foundation\Configuration\ConfigMutationTracker;
use Hypervel\Tests\TestCase;
use RuntimeException;

class ConfigMutationTrackerTest extends TestCase
{
    public function testReplaysRawAndSemanticMutationsInTheirOriginalOrder(): void
    {
        $tracker = new ConfigMutationTracker;
        $master = new Repository(['worker' => 'master']);
        $tracker->observe($master);

        $master->set('package', ['value' => 'raw']);
        $tracker->applyAndRecord($master, static function (Repository $config): void {
            $config->set('package.value', $config->string('worker'));
        });
        $master->set('package.value', 'final');

        $worker = new Repository(['worker' => 'worker']);
        $tracker->replay($worker);

        $this->assertSame('final', $worker->get('package.value'));
    }

    public function testSemanticMutationUsesFreshRepositoryState(): void
    {
        $tracker = new ConfigMutationTracker;
        $master = new Repository(['environment' => 'master']);
        $tracker->observe($master);

        $tracker->applyAndRecord($master, static function (Repository $config): void {
            $config->set('package.environment', $config->string('environment'));
        });

        $this->assertSame('master', $master->get('package.environment'));

        $worker = new Repository(['environment' => 'worker']);
        $tracker->replay($worker);

        $this->assertSame('worker', $worker->get('package.environment'));
    }

    public function testSemanticMutationDoesNotRecordItsInternalSet(): void
    {
        $tracker = new ConfigMutationTracker;
        $master = new Repository(['environment' => 'master']);
        $tracker->observe($master);

        $tracker->applyAndRecord($master, static function (Repository $config): void {
            $config->set('package.environment', $config->string('environment'));
        });

        $worker = new Repository(['environment' => 'worker']);
        $setCalls = 0;
        $worker->setMutationObserver(static function () use (&$setCalls): void {
            ++$setCalls;
        });
        $tracker->replay($worker);

        $this->assertSame('worker', $worker->get('package.environment'));
        $this->assertSame(1, $setCalls);
    }

    public function testReplayDoesNotGrowTheMutationLog(): void
    {
        $tracker = new ConfigMutationTracker;
        $master = new Repository;
        $tracker->observe($master);
        $runs = 0;

        $tracker->applyAndRecord($master, static function (Repository $config) use (&$runs): void {
            ++$runs;
            $config->set('package.runs', $runs);
        });

        $firstWorker = new Repository;
        $tracker->replay($firstWorker);
        $secondWorker = new Repository;
        $tracker->replay($secondWorker);

        $this->assertSame(3, $runs);
        $this->assertSame(3, $secondWorker->get('package.runs'));
    }

    public function testFailedSemanticMutationRestoresRecordingAndIsNotAppended(): void
    {
        $tracker = new ConfigMutationTracker;
        $master = new Repository;
        $tracker->observe($master);
        $exception = null;

        try {
            $tracker->applyAndRecord($master, static function (Repository $config): void {
                $config->set('package.partial', true);

                throw new RuntimeException('Failed operation.');
            });
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame('Failed operation.', $exception->getMessage());

        $master->set('package.recorded', true);

        $worker = new Repository;
        $tracker->replay($worker);

        $this->assertNull($worker->get('package.partial'));
        $this->assertTrue($worker->boolean('package.recorded'));
    }

    public function testMutationsAfterReplayAreNotRecorded(): void
    {
        $tracker = new ConfigMutationTracker;
        $master = new Repository;
        $tracker->observe($master);
        $master->set('package.value', 'boot');

        $tracker->replay(new Repository);
        $master->set('package.value', 'runtime');

        $worker = new Repository;
        $tracker->replay($worker);

        $this->assertSame('boot', $worker->get('package.value'));
    }
}
