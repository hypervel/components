<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Integration;

use Exception;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Sentry\Integration\ModelViolations\ModelViolationReporter;
use Hypervel\Tests\Sentry\SentryTestCase;
use Swoole\Coroutine\Channel;

/**
 * Verify that model violation dedupe is per-request (coroutine-scoped),
 * not per-worker (instance property on a long-lived object).
 *
 * @internal
 * @coversNothing
 */
class ModelViolationDedupeTest extends SentryTestCase
{
    public function testDedupeIsIsolatedBetweenCoroutines()
    {
        $reported = [];
        $reporter = new ConcreteViolationReporter(
            callback: function (Model $model, string $property) use (&$reported) {
                $reported[] = get_class($model) . ':' . $property;
            },
            suppressDuplicateReports: true,
            reportAfterResponse: false,
        );

        $model = new ModelViolationDedupeTestModel();
        $model->exists = true;

        // Report in the parent coroutine
        $reporter($model, 'name');
        $this->assertCount(1, $reported);

        // Same violation in parent — should be suppressed
        $reporter($model, 'name');
        $this->assertCount(1, $reported);

        $childReportCount = null;
        $channel = new Channel(1);

        // In a child coroutine, the same violation should report again
        // because dedupe is per-coroutine, not per-worker
        Coroutine::create(function () use ($reporter, $model, &$reported, &$childReportCount, $channel) {
            $countBefore = count($reported);
            $reporter($model, 'name');
            $childReportCount = count($reported) - $countBefore;
            $channel->push(true);
        });

        $channel->pop(1.0);

        // Child coroutine should have reported the violation (not suppressed)
        $this->assertSame(1, $childReportCount);
        $this->assertCount(2, $reported);
    }

    public function testDifferentReporterTypesDontSuppressEachOther()
    {
        $reported = [];
        $callback = function (Model $model, string $property) use (&$reported) {
            $reported[] = get_class($model) . ':' . $property;
        };

        $reporterA = new ConcreteViolationReporter(
            callback: $callback,
            suppressDuplicateReports: true,
            reportAfterResponse: false,
        );

        $reporterB = new AnotherConcreteViolationReporter(
            callback: $callback,
            suppressDuplicateReports: true,
            reportAfterResponse: false,
        );

        $model = new ModelViolationDedupeTestModel();
        $model->exists = true;

        // Report same model+property via reporter A
        $reporterA($model, 'name');
        $this->assertCount(1, $reported);

        // Same model+property via reporter B — should NOT be suppressed
        $reporterB($model, 'name');
        $this->assertCount(2, $reported);

        // Reporter A again — should be suppressed (already reported by A)
        $reporterA($model, 'name');
        $this->assertCount(2, $reported);
    }
}

class ConcreteViolationReporter extends ModelViolationReporter
{
    protected function getViolationContext(Model $model, string $property): array
    {
        return ['property' => $property, 'kind' => 'concrete'];
    }

    protected function getViolationException(Model $model, string $property): Exception
    {
        return new Exception("Concrete violation: {$property}");
    }
}

class AnotherConcreteViolationReporter extends ModelViolationReporter
{
    protected function getViolationContext(Model $model, string $property): array
    {
        return ['property' => $property, 'kind' => 'another'];
    }

    protected function getViolationException(Model $model, string $property): Exception
    {
        return new Exception("Another violation: {$property}");
    }
}

class ModelViolationDedupeTestModel extends Model
{
    protected ?string $table = 'test_models';
}
