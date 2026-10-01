<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Console;

use Hypervel\Testbench\Console\Task;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TaskTest extends TestCase
{
    #[Test]
    public function itCanDispatchAnActionAndResponse(): void
    {
        $actionCalled = false;
        $response = null;

        $task = Task::action(function () use (&$actionCalled): bool {
            $actionCalled = true;

            return true;
        })->response(function (bool $result, bool $pretending) use (&$response): void {
            $response = [$result, $pretending];
        });

        $task->dispatch();

        $this->assertTrue($actionCalled);
        $this->assertSame([true, false], $response);
    }

    #[Test]
    public function itCanDispatchATaskWhenPretending(): void
    {
        $actionCalled = false;
        $response = null;

        $task = Task::action(function () use (&$actionCalled): bool {
            $actionCalled = true;

            return false;
        })->response(function (bool $result, bool $pretending) use (&$response): void {
            $response = [$result, $pretending];
        });

        $task->dispatch(true);

        $this->assertFalse($actionCalled);
        $this->assertSame([true, true], $response);
    }

    #[Test]
    public function itDoesNotDispatchWhenRequirementsAreNotMet(): void
    {
        $actionCalled = false;
        $responseCalled = false;

        $task = Task::action(function () use (&$actionCalled): bool {
            $actionCalled = true;

            return true;
        })->response(function () use (&$responseCalled): void {
            $responseCalled = true;
        })->requirements(function (): bool {
            return false;
        });

        $task();

        $this->assertFalse($actionCalled);
        $this->assertFalse($responseCalled);
    }

    #[Test]
    public function itCanBeInvoked(): void
    {
        $response = null;

        $task = Task::action(fn (): bool => false)
            ->response(function (bool $result, bool $pretending) use (&$response): void {
                $response = [$result, $pretending];
            });

        $task(true);

        $this->assertSame([true, true], $response);
    }
}
