<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Events\Dispatchable as EventDispatchable;
use Hypervel\PHPStan\Rules\CheckDispatchArgumentTypesCompatibleWithClassConstructorRule;
use PHPStan\Rules\FunctionCallParametersCheck;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<CheckDispatchArgumentTypesCompatibleWithClassConstructorRule> */
class CheckDispatchArgumentTypesCompatibleWithClassConstructorRuleTest extends RuleTestCase
{
    private string $dispatchableClass;

    protected function getRule(): Rule
    {
        $broker = $this->createReflectionProvider();

        return new CheckDispatchArgumentTypesCompatibleWithClassConstructorRule(
            $broker,
            self::getContainer()->getByType(FunctionCallParametersCheck::class),
            $this->dispatchableClass,
        );
    }

    public function testJobDispatch(): void
    {
        $this->dispatchableClass = Dispatchable::class;

        $this->analyse([__DIR__ . '/data/job-dispatch.php'], [
            ['Job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor invoked with 0 parameters in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatch(), 2 required.', 5],
            ['Job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor invoked with 0 parameters in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchSync(), 2 required.', 6],
            // REMOVED: dispatchNow() cases. Hypervel's Dispatchable has no dispatchNow() method.
            ['Job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor invoked with 0 parameters in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchAfterResponse(), 2 required.', 7],
            ['Job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor invoked with 1 parameter in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatch(), 2 required.', 9],
            ['Parameter #1 $foo of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatch(), int given.', 10],
            ['Parameter #2 $bar of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatch(), string given.', 10],
            ['Parameter #1 $foo of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatch(), true given.', 11],
            ['Parameter #2 $bar of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatch(), false given.', 11],
            ['Job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor invoked with 1 parameter in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchSync(), 2 required.', 13],
            ['Parameter #1 $foo of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchSync(), int given.', 14],
            ['Parameter #2 $bar of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchSync(), string given.', 14],
            ['Parameter #1 $foo of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchSync(), true given.', 15],
            ['Parameter #2 $bar of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchSync(), false given.', 15],
            ['Job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor invoked with 1 parameter in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchAfterResponse(), 2 required.', 17],
            ['Parameter #1 $foo of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchAfterResponse(), int given.', 18],
            ['Parameter #2 $bar of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchAfterResponse(), string given.', 18],
            ['Parameter #1 $foo of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchAfterResponse(), true given.', 19],
            ['Parameter #2 $bar of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchAfterResponse(), false given.', 19],
            ['Parameter #1 $foo of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchIf(), int given.', 21],
            ['Parameter #2 $bar of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchIf(), string given.', 21],
            ['Parameter #1 $foo of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchUnless(), int given.', 22],
            ['Job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJobWithoutConstructor does not have a constructor and must be dispatched without any parameters.', 25],
            ['Job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJobWithoutConstructor does not have a constructor and must be dispatched without any parameters.', 27],
            ['Job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJobWithoutConstructor does not have a constructor and must be dispatched without any parameters.', 29],
            ['Job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJobWithoutConstructor does not have a constructor and must be dispatched without any parameters.', 32],
            ['Parameter #1 $foo of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchIf(), int given.', 36],
            ['Parameter #2 $bar of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatchIf(), string given.', 36],
            ['Missing parameter $bar (int) in call to Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor.', 37],
            ['Parameter #1 $foo of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatch(), int given.', 42],
            ['Parameter #2 $bar of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int in Hypervel\Tests\PHPStan\Rules\Data\HypervelJob::dispatch(), string given.', 42],
            ['Parameter #1 $bar of job class Hypervel\Tests\PHPStan\Rules\Data\HypervelChildJob constructor expects int in Hypervel\Tests\PHPStan\Rules\Data\HypervelChildJob::dispatch(), string given.', 70],
        ]);
    }

    public function testEventDispatch(): void
    {
        $this->dispatchableClass = EventDispatchable::class;

        $this->analyse([__DIR__ . '/data/event-dispatch.php'], [
            ['Event class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor invoked with 0 parameters in Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent::dispatch(), 2 required.', 5],
            ['Event class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor invoked with 1 parameter in Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent::dispatch(), 2 required.', 7],
            ['Parameter #1 $foo of event class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor expects string in Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent::dispatch(), int given.', 8],
            ['Parameter #2 $bar of event class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor expects int in Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent::dispatch(), string given.', 8],
            ['Parameter #1 $foo of event class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor expects string in Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent::dispatch(), true given.', 9],
            ['Parameter #2 $bar of event class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor expects int in Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent::dispatch(), false given.', 9],
            ['Parameter #1 $foo of event class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor expects string in Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent::dispatchIf(), int given.', 11],
            ['Parameter #2 $bar of event class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor expects int in Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent::dispatchIf(), string given.', 11],
            ['Parameter #1 $foo of event class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor expects string in Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent::dispatchUnless(), int given.', 12],
            ['Event class Hypervel\Tests\PHPStan\Rules\Data\HypervelEventWithoutConstructor does not have a constructor and must be dispatched without any parameters.', 15],
            ['Event class Hypervel\Tests\PHPStan\Rules\Data\HypervelEventWithoutConstructor does not have a constructor and must be dispatched without any parameters.', 18],
            ['Event class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor invoked with 1 parameter in Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent::broadcast(), 2 required.', 21],
            ['Parameter #1 $count of event class Hypervel\Tests\PHPStan\Rules\Data\HypervelEventWithReference constructor expects int in Hypervel\Tests\PHPStan\Rules\Data\HypervelEventWithReference::dispatch(), string given.', 25],
        ]);
    }
}
