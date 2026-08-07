<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Spinner;
use Hypervel\Prompts\Task;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use Swoole\Coroutine as SwooleCoroutine;

class CoroutineCreateFailureTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[RunInSeparateProcess]
    public function testSpinnerRestoresItsTerminalStateWhenAnimationCreationFails(): void
    {
        Prompt::fake();
        SwooleCoroutine::set(['max_coroutine' => 1]);

        SwooleCoroutine\run(function (): void {
            $callbackRan = false;
            $spinner = new Spinner('Running');

            try {
                $spinner->spin(function () use (&$callbackRan): void {
                    $callbackRan = true;
                });
                $this->fail('Expected animation coroutine creation to fail.');
            } catch (CoroutineCreateException) {
                $this->assertFalse($callbackRan);
                $this->assertFalse(
                    (new ReflectionProperty(Prompt::class, 'cursorHidden'))
                        ->getValue(),
                );
            }

            $content = Prompt::content();
            unset($spinner);

            $this->assertSame($content, Prompt::content());
            $this->assertFalse(
                (new ReflectionProperty(Prompt::class, 'cursorHidden'))
                    ->getValue(),
            );
        });
    }

    #[RunInSeparateProcess]
    public function testTaskRestoresItsTerminalStateWhenAnimationCreationFails(): void
    {
        Prompt::fake();
        SwooleCoroutine::set(['max_coroutine' => 1]);

        SwooleCoroutine\run(function (): void {
            $callbackRan = false;
            $task = new Task('Running');

            try {
                $task->run(function () use (&$callbackRan): void {
                    $callbackRan = true;
                });
                $this->fail('Expected animation coroutine creation to fail.');
            } catch (CoroutineCreateException) {
                $this->assertFalse($callbackRan);
                $this->assertTrue(
                    (new ReflectionProperty($task, 'finished'))->getValue($task),
                );
                $this->assertFalse(
                    (new ReflectionProperty(Prompt::class, 'cursorHidden'))
                        ->getValue(),
                );
            }

            $content = Prompt::content();
            unset($task);

            $this->assertSame($content, Prompt::content());
            $this->assertFalse(
                (new ReflectionProperty(Prompt::class, 'cursorHidden'))
                    ->getValue(),
            );
        });
    }
}
