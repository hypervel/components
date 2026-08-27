<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Prompts\Output\BufferedConsoleOutput;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Support\InProcessLogger;
use Hypervel\Prompts\Support\Logger;
use Hypervel\Prompts\Support\TaskFrame;
use Hypervel\Prompts\Task;
use Hypervel\Prompts\Themes\Default\TaskRenderer;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

use function Hypervel\Prompts\task;

class TaskTest extends TestCase
{
    public function testPrintsCompletionLineAfterSuccessfulStaticTaskWithSummary(): void
    {
        Prompt::fake();

        $task = new Task(label: 'My Task', keepSummary: true);
        (new ReflectionMethod($task, 'renderStatically'))
            ->invoke($task, fn (Logger $logger): null => null);

        Prompt::assertStrippedOutputContains('✔ My Task');
    }

    public function testStaticTaskLoggerUpdatesTaskStateAndRendersStableSummary(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Starting', keepSummary: true);
        $result = (new ReflectionMethod($task, 'renderStatically'))
            ->invoke($task, function (Logger $logger): string {
                $logger->label('Finished');
                $logger->subLabel('All steps complete');
                $logger->success('Deployment complete');

                return 'done';
            });

        $this->assertSame('done', $result);
        $this->assertSame('Finished', $task->label);
        $this->assertSame('All steps complete', $task->subLabel);
        $this->assertSame([
            ['type' => 'success', 'message' => 'Deployment complete'],
        ], $task->stableMessages);
        Prompt::assertStrippedOutputContains('Finished');
        Prompt::assertStrippedOutputContains('Deployment complete');
        Prompt::assertStrippedOutputDoesntContain('✔ Finished');
    }

    public function testDoesNotPrintStaticCompletionLineWithoutSummary(): void
    {
        Prompt::fake();

        $task = new Task(label: 'My Task');
        (new ReflectionMethod($task, 'renderStatically'))
            ->invoke($task, fn (Logger $logger): null => null);

        Prompt::assertStrippedOutputDoesntContain('✔ My Task');
    }

    public function testDoesNotPrintStaticCompletionLineAfterFailure(): void
    {
        Prompt::fake();

        $task = new Task(label: 'My Task', keepSummary: true);
        $renderStatically = new ReflectionMethod($task, 'renderStatically');

        try {
            $renderStatically->invoke($task, function (Logger $logger): never {
                throw new RuntimeException('boom');
            });

            $this->fail('Expected the task callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        Prompt::assertStrippedOutputDoesntContain('✔ My Task');
    }

    public function testPrintsCompletionLineAfterSuccessfulCoroutineTaskWithSummary(): void
    {
        Prompt::fake();

        (new Task(label: 'My Task', keepSummary: true))
            ->run(fn (Logger $logger): null => null);

        Prompt::assertStrippedOutputContains('✔ My Task');
    }

    public function testUndecoratedTaskWritesPlainStartAndCompletionLines(): void
    {
        Prompt::fake();
        Prompt::setOutput(new BufferedConsoleOutput(decorated: false));

        $result = (new Task(label: 'My Task', keepSummary: true))
            ->run(fn (Logger $logger): string => 'done');

        $this->assertSame('done', $result);
        $this->assertSame(2, substr_count(Prompt::content(), 'My Task'));
        $this->assertStringEndsWith(' ✔ My Task' . PHP_EOL, Prompt::content());
        $this->assertStringNotContainsString("\e", Prompt::content());
    }

    public function testTaskCanBeReusedWithoutStaleOperationStateOrAnimation(): void
    {
        Prompt::fake();
        $task = new Task(label: 'Running');

        $task->run(function (Logger $logger): void {
            $logger->line('first');
        });
        $task->run(function (Logger $logger): void {
            $logger->line('second');
        });

        $this->assertSame(['second'], $task->logs);
        $this->assertTrue($task->finished);

        $content = Prompt::content();
        usleep(150_000);

        $this->assertSame($content, Prompt::content());
    }

    public function testRejectsNegativeLogLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The task log limit must be zero or greater.');

        new Task(limit: -1);
    }

    public function testRejectsNegativeLogLimitAtOperationEntryWithoutResettingState(): void
    {
        Prompt::fake();
        $task = new Task(label: 'My Task', limit: 10);
        (new InProcessLogger($task))->line('kept');
        $task->limit = -1;
        $callbackRuns = 0;

        try {
            $task->run(function (Logger $logger) use (&$callbackRuns): void {
                ++$callbackRuns;
            });

            $this->fail('Expected the task log limit to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('The task log limit must be zero or greater.', $exception->getMessage());
        }

        $this->assertSame(0, $callbackRuns);
        $this->assertSame(['kept'], $task->logs);
    }

    public function testZeroLogLimitDiscardsLogLines(): void
    {
        Prompt::fake();
        $task = new Task(limit: 0);

        $task->run(function (Logger $logger): void {
            $logger->line('hidden');
        });

        $this->assertSame([], $task->logs);
    }

    public function testTinyTerminalUsesZeroLogLimitWithoutChangingConfiguration(): void
    {
        Prompt::fake();
        Prompt::terminal()->shouldReceive('lines')->andReturn(5); // @phpstan-ignore-line
        $task = new Task(limit: 10);

        $task->run(function (Logger $logger): void {
            $logger->line('hidden');
        });

        $this->assertSame([], $task->logs);
        $this->assertSame(10, $task->limit);
    }

    public function testReusedTaskRecalculatesLogLimitWhenTheTerminalGrows(): void
    {
        Prompt::fake();
        Prompt::setOutput(new BufferedConsoleOutput(decorated: false));
        Prompt::terminal()->shouldReceive('lines')->andReturn(5, 5, 30, 30); // @phpstan-ignore-line
        $task = new Task(limit: 10);

        $task->run(function (Logger $logger): void {
            $logger->line('first');
        });

        $this->assertSame([], $task->logs);
        $this->assertSame(10, $task->limit);

        $task->run(function (Logger $logger): void {
            $logger->line('second');
            $logger->line('third');
        });

        $this->assertSame(['second', 'third'], $task->logs);
        $this->assertSame(10, $task->limit);
    }

    public function testRendersTaskAndReturnsValue()
    {
        Prompt::fake();

        $result = task(
            label: 'Running...',
            callback: function (Logger $logger) {
                usleep(1000);

                return 'done';
            },
        );

        $this->assertSame('done', $result);

        Prompt::assertOutputContains('Running...');
    }

    public function testReturnsNullWhenCallbackDoesNotReturnValue()
    {
        Prompt::fake();

        $result = task(
            label: 'Working...',
            callback: function (Logger $logger) {
                usleep(1000);
            },
        );

        $this->assertNull($result);
    }

    public function testCoroutinePathRendersLoggerOutput()
    {
        Prompt::fake();

        $result = task(
            label: 'Running...',
            callback: function (Logger $logger) {
                $logger->line('hello world');

                return 'done';
            },
        );

        $this->assertSame('done', $result);
        Prompt::assertOutputContains('hello world');
    }

    public function testCoroutinePathRendersStableMessages()
    {
        Prompt::fake();

        $result = task(
            label: 'Running...',
            callback: function (Logger $logger) {
                $logger->success('step complete');

                return 'done';
            },
        );

        $this->assertSame('done', $result);
        Prompt::assertOutputContains('step complete');
        Prompt::assertOutputContains('✔');
    }

    public function testCoroutinePathUpdatesLabel()
    {
        Prompt::fake();

        $result = task(
            label: 'Initial Label',
            callback: function (Logger $logger) {
                $logger->label('Updated Label');

                return 'done';
            },
        );

        $this->assertSame('done', $result);
        Prompt::assertOutputContains('Updated Label');
    }

    public function testCoroutinePathUpdatesSubLabel(): void
    {
        Prompt::fake();

        $result = task(
            label: 'Deploying',
            callback: function (Logger $logger) {
                $logger->subLabel('Building assets');

                return 'done';
            },
        );

        $this->assertSame('done', $result);
        Prompt::assertOutputContains('Building assets');
    }

    public function testTaskHelperAcceptsDocumentedNamedArguments(): void
    {
        Prompt::fake();

        $result = task(
            label: 'Deploying',
            callback: function (Logger $logger) {
                $logger->success('Assets built');

                return 'done';
            },
            keepSummary: true,
            subLabel: 'Preparing...',
        );

        $this->assertSame('done', $result);
        Prompt::assertOutputContains('Preparing...');
        Prompt::assertOutputContains('Assets built');
    }

    public function testCoroutinePathHandlesPartialLogging(): void
    {
        Prompt::fake();

        $result = task(
            label: 'Running...',
            callback: function (Logger $logger) {
                $logger->partial('hello ');
                $logger->partial('world');
                $logger->commitPartial();
                $logger->line('after commit');

                return 'done';
            },
        );

        $this->assertSame('done', $result);
        Prompt::assertOutputContains('hello world');
        Prompt::assertOutputContains('after commit');
    }

    public function testReceivesLogLinesIntoRingBuffer()
    {
        $task = new Task(label: 'Test', limit: 3);

        $reflection = new ReflectionMethod($task, 'addLogLines');

        $reflection->invoke($task, 'line one');
        $reflection->invoke($task, 'line two');
        $reflection->invoke($task, 'line three');
        $reflection->invoke($task, 'line four');

        $this->assertCount(3, $task->logs);
        $this->assertSame('line two', $task->logs[0]);
        $this->assertSame('line three', $task->logs[1]);
        $this->assertSame('line four', $task->logs[2]);
    }

    public function testWrapsLongLinesAndRespectsLimit()
    {
        Prompt::fake();

        $task = new Task(label: 'Test', limit: 3);

        $reflection = new ReflectionMethod($task, 'addLogLines');

        // 80 cols - 10 = 70 char width, this line is well over that
        $longLine = str_repeat('a ', 50);
        $reflection->invoke($task, $longLine);

        // Should have been wrapped into multiple lines, trimmed to limit
        $this->assertLessThanOrEqual(3, count($task->logs));
    }

    public function testReplacesPartialLinesOnEachUpdate()
    {
        Prompt::fake();

        $task = new Task(label: 'Test', limit: 10);

        $addLogLines = new ReflectionMethod($task, 'addLogLines');
        $replacePartialLines = new ReflectionMethod($task, 'replacePartialLines');

        $addLogLines->invoke($task, 'existing line');

        $this->assertCount(1, $task->logs);

        $replacePartialLines->invoke($task, 'hello');
        $this->assertCount(2, $task->logs);
        $this->assertSame('existing line', $task->logs[0]);
        $this->assertSame('hello', $task->logs[1]);

        // Next partial replaces, not appends
        $replacePartialLines->invoke($task, 'hello world');
        $this->assertCount(2, $task->logs);
        $this->assertSame('existing line', $task->logs[0]);
        $this->assertSame('hello world', $task->logs[1]);
    }

    public function testCommitsPartialLinesSoTheyBecomePermanent(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Test', limit: 10);

        $logger = new InProcessLogger($task);
        $partialStartIndex = new ReflectionProperty($task, 'partialStartIndex');

        $logger->partial('streamed text');
        $this->assertSame(0, $partialStartIndex->getValue($task));

        $logger->commitPartial();
        $this->assertNull($partialStartIndex->getValue($task));

        $logger->line('new line');

        $this->assertCount(2, $task->logs);
        $this->assertSame('streamed text', $task->logs[0]);
        $this->assertSame('new line', $task->logs[1]);
    }

    public function testCommittedPartialLinesAreNotReplayedByTheNextPartial(): void
    {
        Prompt::fake();
        $task = new Task(label: 'Test', limit: 10);
        $logger = new InProcessLogger($task);

        $logger->partial('first');
        $logger->commitPartial();
        $logger->partial('second');
        $logger->commitPartial();

        $this->assertSame(['first', 'second'], $task->logs);
    }

    public function testClearsLogsWhenStableMessageReceived(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Test', limit: 10);

        $logger = new InProcessLogger($task);
        $logger->line('log line');

        $this->assertCount(1, $task->logs);

        $logger->success('Done!');

        $this->assertEmpty($task->logs);
        $this->assertCount(1, $task->stableMessages);
        $this->assertSame('success', $task->stableMessages[0]['type']);
        $this->assertSame('Done!', $task->stableMessages[0]['message']);
    }

    public function testTrimsStableMessagesToMaxStableMessages(): void
    {
        $task = new Task(label: 'Test', limit: 10);
        $task->maxStableMessages = 2;
        $logger = new InProcessLogger($task);

        $logger->success('First');
        $logger->success('Second');
        $logger->success('Third');

        $this->assertCount(2, $task->stableMessages);
        $this->assertSame('Second', $task->stableMessages[0]['message']);
        $this->assertSame('Third', $task->stableMessages[1]['message']);
    }

    public function testReceivesMessagesThroughSocketProtocol(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Initial', limit: 10);

        $receiveMessages = new ReflectionMethod($task, 'receiveMessages');

        // Create a socket pair to simulate IPC
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        fwrite(
            $sockets[1],
            TaskFrame::encode(null, "plain\nlog\0line")
            . TaskFrame::encode('label', 'New Label')
            . TaskFrame::encode(null, 'another log line')
            . TaskFrame::encode('success', 'Step complete')
            . TaskFrame::encode(null, "after\nsettlement\0line"),
        );
        fclose($sockets[1]);

        stream_set_blocking($sockets[0], false);
        $receiveMessages->invoke($task, $sockets[0]);
        fclose($sockets[0]);

        $this->assertSame('New Label', $task->label);
        $this->assertCount(1, $task->stableMessages);
        $this->assertSame(['type' => 'success', 'message' => 'Step complete'], $task->stableMessages[0]);
        $this->assertSame(['after', "settlement\0line"], $task->logs);
    }

    public function testHandlesPartialMessagesThroughSocketProtocol(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Test', limit: 10);

        $receiveMessages = new ReflectionMethod($task, 'receiveMessages');

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        fwrite(
            $sockets[1],
            TaskFrame::encode(null, 'existing line')
            . TaskFrame::encode('partial', 'hello ')
            . TaskFrame::encode('partial', 'world ')
            . TaskFrame::encode('commitpartial', '')
            . TaskFrame::encode(null, 'after commit'),
        );
        fclose($sockets[1]);

        stream_set_blocking($sockets[0], false);
        $receiveMessages->invoke($task, $sockets[0]);
        fclose($sockets[0]);

        $this->assertCount(3, $task->logs);
        $this->assertSame('existing line', $task->logs[0]);
        $this->assertSame('hello world ', $task->logs[1]);
        $this->assertSame('after commit', $task->logs[2]);
    }

    public function testStripsCursorResetControlSequencesFromLogLines(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Test', limit: 10);

        $receiveMessages = new ReflectionMethod($task, 'receiveMessages');

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        fwrite($sockets[1], TaskFrame::encode(null, "before\e[1G\e[2Kafter"));
        fclose($sockets[1]);

        stream_set_blocking($sockets[0], false);
        $receiveMessages->invoke($task, $sockets[0]);
        fclose($sockets[0]);

        $this->assertSame('beforeafter', $task->logs[0]);
    }

    public function testUpdatesLabelThroughSocketProtocol(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Initial', limit: 10);

        $receiveMessages = new ReflectionMethod($task, 'receiveMessages');

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        fwrite($sockets[1], TaskFrame::encode('label', 'Updated Label'));
        fclose($sockets[1]);

        stream_set_blocking($sockets[0], false);
        $receiveMessages->invoke($task, $sockets[0]);
        fclose($sockets[0]);

        $this->assertSame('Updated Label', $task->label);
    }

    public function testUpdatesSubLabelThroughSocketProtocol(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Running', limit: 10);
        $task->maxStableMessages = 3;
        $task->stableMessages = [
            ['type' => 'success', 'message' => 'one'],
            ['type' => 'success', 'message' => 'two'],
            ['type' => 'success', 'message' => 'three'],
        ];

        $receiveMessages = new ReflectionMethod($task, 'receiveMessages');
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        fwrite($sockets[1], TaskFrame::encode('sublabel', 'Now doing a thing'));
        fclose($sockets[1]);

        stream_set_blocking($sockets[0], false);
        $receiveMessages->invoke($task, $sockets[0]);
        fclose($sockets[0]);

        $this->assertSame('Now doing a thing', $task->subLabel);
        $this->assertLessThan(3, $task->maxStableMessages);
        $this->assertLessThanOrEqual($task->maxStableMessages, count($task->stableMessages));

        $previousBudget = $task->maxStableMessages;

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($sockets[1], TaskFrame::encode('sublabel', ''));
        fclose($sockets[1]);
        stream_set_blocking($sockets[0], false);
        $receiveMessages->invoke($task, $sockets[0]);
        fclose($sockets[0]);

        $this->assertSame('', $task->subLabel);
        $this->assertSame($previousBudget + 1, $task->maxStableMessages);
    }

    public function testUpdatesSubLabelThroughCoroutinePath(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Running', limit: 10);
        $task->maxStableMessages = 3;
        $task->stableMessages = [
            ['type' => 'success', 'message' => 'one'],
            ['type' => 'success', 'message' => 'two'],
            ['type' => 'success', 'message' => 'three'],
        ];

        $logger = new InProcessLogger($task);
        $logger->subLabel('Now doing a thing');

        $this->assertSame('Now doing a thing', $task->subLabel);
        $this->assertLessThan(3, $task->maxStableMessages);
        $this->assertLessThanOrEqual($task->maxStableMessages, count($task->stableMessages));

        $previousBudget = $task->maxStableMessages;

        $logger->subLabel('');

        $this->assertSame('', $task->subLabel);
        $this->assertSame($previousBudget + 1, $task->maxStableMessages);
    }

    public function testProcessAndInProcessMessagesProduceTheSameTaskState(): void
    {
        Prompt::fake();

        $inProcessTask = new Task(label: 'Initial', limit: 10);
        $logger = new InProcessLogger($inProcessTask);
        $logger->label('Updated');
        $logger->subLabel('Working');
        $logger->line("first\n\nsecond");
        $logger->partial('streamed ');
        $logger->partial('output');
        $logger->commitPartial();
        $logger->warning('Done');
        $logger->line('after');

        $processTask = new Task(label: 'Initial', limit: 10);
        $receiveMessages = new ReflectionMethod($processTask, 'receiveMessages');
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite(
            $sockets[1],
            TaskFrame::encode('label', 'Updated')
            . TaskFrame::encode('sublabel', 'Working')
            . TaskFrame::encode(null, "first\n\nsecond")
            . TaskFrame::encode('partial', 'streamed ')
            . TaskFrame::encode('partial', 'output')
            . TaskFrame::encode('commitpartial', '')
            . TaskFrame::encode('warning', 'Done')
            . TaskFrame::encode(null, 'after'),
        );
        fclose($sockets[1]);
        stream_set_blocking($sockets[0], false);
        $receiveMessages->invoke($processTask, $sockets[0]);
        fclose($sockets[0]);

        $this->assertSame($inProcessTask->label, $processTask->label);
        $this->assertSame($inProcessTask->subLabel, $processTask->subLabel);
        $this->assertSame($inProcessTask->logs, $processTask->logs);
        $this->assertSame($inProcessTask->stableMessages, $processTask->stableMessages);
    }

    public function testPreservesBlankLinesInCompleteAndPartialOutput(): void
    {
        Prompt::fake();
        $task = new Task(limit: 10);
        $logger = new InProcessLogger($task);

        $logger->line("a\n\nb");
        $this->assertSame(['a', '', 'b'], $task->logs);

        $logger->partial("c\n\nd");
        $this->assertSame(['a', '', 'b', 'c', '', 'd'], $task->logs);

        $logger->commitPartial();
        $this->assertSame(['a', '', 'b', 'c', '', 'd'], $task->logs);
    }

    public function testIncrementalPartialParsingHandlesSplitUtf8AnsiAndHyperlinks(): void
    {
        Prompt::fake();
        $task = new Task(limit: 10);
        $logger = new InProcessLogger($task);
        $character = 'é';

        $logger->partial(substr($character, 0, 1));
        $logger->partial(substr($character, 1) . " \e[31");
        $logger->partial("mred\e[0m \e]8;;https://example.com\e");
        $logger->partial("\\link\e]8;;\e\\");

        $this->assertSame(
            "é \e[31mred\e[0m \e]8;;https://example.com\e\\link\e]8;;\e\\",
            $task->logs[0],
        );
    }

    public function testIncrementalPartialWrappingMatchesCompleteWordWrapping(): void
    {
        Prompt::fake();
        Prompt::terminal()->shouldReceive('cols')->andReturn(13); // @phpstan-ignore-line

        $cases = [
            'abc d' => ['abc', 'd'],
            'ab cd' => ['ab', 'cd'],
            'abc ' => ['abc'],
            'a  b' => ['a ', 'b'],
            'abcdef gh' => ['abc', 'def', 'gh'],
        ];

        foreach ($cases as $input => $expected) {
            $task = new Task(limit: 10);
            $logger = new InProcessLogger($task);
            $logger->partial($input);
            $logger->commitPartial();

            $this->assertSame($expected, $task->logs, "Failed wrapping [{$input}].");
        }
    }

    public function testIncrementalPartialParsingHandlesCrLfSplitAcrossChunks(): void
    {
        Prompt::fake();
        $task = new Task(limit: 10);
        $logger = new InProcessLogger($task);

        $logger->partial("first\r");
        $logger->partial("\nsecond");
        $logger->commitPartial();

        $this->assertSame(['first', 'second'], $task->logs);
    }

    public function testIncrementalPartialCommitPreservesIncompleteEscapesLiterally(): void
    {
        Prompt::fake();

        $csiTask = new Task(limit: 10);
        $csiLogger = new InProcessLogger($csiTask);
        $csiLogger->partial("abc\e[31");
        $csiLogger->commitPartial();

        $oscTask = new Task(limit: 10);
        $oscLogger = new InProcessLogger($oscTask);
        $oscLogger->partial("abc\e]8;;https://hypervel.org");
        $oscLogger->commitPartial();

        $this->assertSame(["abc\e[31"], $csiTask->logs);
        $this->assertSame(["abc\e]8;;https://hypervel.org"], $oscTask->logs);
    }

    public function testCompleteNonFormattingControlsAreRemovedFromEveryTaskOutputPath(): void
    {
        Prompt::fake();

        $completeTask = new Task(limit: 10);
        (new ReflectionMethod($completeTask, 'addLogLines'))
            ->invoke($completeTask, "before\e[1G\e[2Kafter\e]0;title\x07");

        $partialTask = new Task(limit: 10);
        $partialLogger = new InProcessLogger($partialTask);
        $partialLogger->partial("before\e[1G\e[2Kafter\e]0;title\x07");
        $partialLogger->commitPartial();

        $this->assertSame(['beforeafter'], $completeTask->logs);
        $this->assertSame($completeTask->logs, $partialTask->logs);
    }

    public function testIncrementalPartialParsingUsesSharedEffectiveSgrState(): void
    {
        Prompt::fake();
        $task = new Task(limit: 10);
        $logger = new InProcessLogger($task);

        $logger->partial("\e[1mBold \e[58;2;255;0;0munderlined\e[59m text");
        $logger->commitPartial();

        $this->assertSame([
            "\e[1mBold \e[0m\e[1m\e[58;2;255;0;0munderlined\e[0m\e[1m text\e[0m",
        ], $task->logs);
    }

    public function testIncrementalPartialStateIsBoundedByVisibleOutput(): void
    {
        Prompt::fake();
        Prompt::terminal()->shouldReceive('cols')->andReturn(14); // @phpstan-ignore-line
        $task = new Task(limit: 3);
        $logger = new InProcessLogger($task);

        for ($index = 0; $index < 1000; ++$index) {
            $logger->partial('x');
        }

        $this->assertSame(['xxxx', 'xxxx', 'xxxx'], $task->logs);
        $this->assertSame('', (new ReflectionProperty($task, 'partialInputBuffer'))->getValue($task));
        $this->assertLessThanOrEqual(3, count((new ReflectionProperty($task, 'partialLines'))->getValue($task)));
        $this->assertLessThanOrEqual(4, count((new ReflectionProperty($task, 'partialLineTokens'))->getValue($task)));
        $this->assertLessThanOrEqual(4, count((new ReflectionProperty($task, 'partialWordTokens'))->getValue($task)));
    }

    public function testPartialBoundaryTracksRingBufferTrimmingAndResetsOnStableOutput(): void
    {
        Prompt::fake();
        $task = new Task(limit: 2);
        $logger = new InProcessLogger($task);
        $partialStartIndex = new ReflectionProperty($task, 'partialStartIndex');

        $logger->line('prefix');
        $logger->partial("one\ntwo\nthree");

        $this->assertSame(['two', 'three'], $task->logs);
        $this->assertSame(0, $partialStartIndex->getValue($task));

        $logger->success('complete');

        $this->assertSame([], $task->logs);
        $this->assertNull($partialStartIndex->getValue($task));
    }

    public function testCoroutineTaskWaitsForAnInFlightAnimationBeforeSettling(): void
    {
        Prompt::fake();
        $task = new TaskAnimationFixture('Running');
        $task->interval = 1;

        $result = $task->run(function (Logger $logger) use ($task): string {
            $this->assertTrue($task->renderStarted->pop(1));

            Coroutine::fork(function () use ($task): void {
                usleep(20_000);
                $task->renderRelease->push(true);
            });

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertFalse($task->rendering);
        $renderCalls = $task->renderCalls;
        usleep(150_000);
        $this->assertSame($renderCalls, $task->renderCalls);
    }

    public function testCoroutineTaskSurfacesAnimationRenderFailure(): void
    {
        Prompt::fake();
        $failure = new RuntimeException('animation failed');
        $task = new TaskAnimationFixture('Running');
        $task->interval = 1;
        $task->renderFailure = $failure;
        $callbackRan = false;
        $thrown = null;

        try {
            $task->run(function (Logger $logger) use (&$callbackRan): void {
                $callbackRan = true;
                usleep(5_000);
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertSame($failure, $thrown);
        $this->assertTrue($callbackRan);
    }

    public function testRendererDisplaysSubLabel(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Running', limit: 10, subLabel: 'Building assets');

        $renderer = new TaskRenderer($task);
        $output = (string) $renderer($task);

        $this->assertStringContainsString('Running', $output);
        $this->assertStringContainsString('Building assets', $output);
    }

    public function testDoesNotKeepSummaryByDefault(): void
    {
        $task = new Task(label: 'Running', limit: 10);

        $this->assertFalse($task->keepSummary);
    }

    public function testRendersLabelAndStableMessagesWhenFinishedWithKeepSummaryEnabled(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Running', limit: 10, keepSummary: true);
        $task->finished = true;
        $task->stableMessages[] = ['type' => 'success', 'message' => 'Step one done'];
        $task->stableMessages[] = ['type' => 'error', 'message' => 'Step two failed'];

        $renderer = new TaskRenderer($task);
        $output = (string) $renderer($task);

        $this->assertStringContainsString('Running', $output);
        $this->assertStringContainsString('Step one done', $output);
        $this->assertStringContainsString('Step two failed', $output);
        $this->assertStringNotContainsString('─', $output);
        $this->assertStringEndsWith(PHP_EOL . PHP_EOL, $output);
    }

    public function testRendersNothingSpecialWhenFinishedWithNoStableMessages(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Running', limit: 10, keepSummary: true);
        $task->finished = true;

        $renderer = new TaskRenderer($task);
        $output = (string) $renderer($task);

        $this->assertStringContainsString('Running', $output);
    }

    public function testDoesNotTakeSummaryBranchWhenKeepSummaryIsDisabled(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Running', limit: 10, keepSummary: false);
        $task->finished = true;
        $task->stableMessages[] = ['type' => 'success', 'message' => 'Step one done'];

        $renderer = new TaskRenderer($task);
        $output = (string) $renderer($task);

        $this->assertStringContainsString('Running', $output);
        $this->assertStringContainsString('Step one done', $output);
    }

    public function testFinishRenderingKeepsSummaryWithoutErasing(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Running', limit: 10, keepSummary: true);
        $task->finished = true;
        $task->stableMessages[] = ['type' => 'success', 'message' => 'Step one done'];

        $finishRendering = new ReflectionMethod($task, 'finishRendering');
        $finishRendering->invoke($task);

        Prompt::assertOutputContains('Running');
        Prompt::assertOutputContains('Step one done');
        $this->assertStringNotContainsString("\e[J", Prompt::content());
    }

    public function testFinishRenderingErasesWhenSummaryIsNotKept(): void
    {
        Prompt::fake();

        $task = new Task(label: 'Running', limit: 10);
        $task->finished = true;
        $task->stableMessages[] = ['type' => 'success', 'message' => 'Step one done'];

        $finishRendering = new ReflectionMethod($task, 'finishRendering');
        $finishRendering->invoke($task);

        $this->assertStringContainsString("\e[J", Prompt::content());
    }
}

class TaskAnimationFixture extends Task
{
    /** @var Channel<true> */
    public Channel $renderStarted;

    /** @var Channel<true> */
    public Channel $renderRelease;

    public int $renderCalls = 0;

    public bool $rendering = false;

    public ?RuntimeException $renderFailure = null;

    public function __construct(string $label)
    {
        parent::__construct($label);

        $this->renderStarted = new Channel(1);
        $this->renderRelease = new Channel(1);
    }

    /**
     * Render the task while exposing animation lifecycle checkpoints.
     */
    protected function render(): void
    {
        ++$this->renderCalls;

        if ($this->renderCalls !== 2) {
            return;
        }

        if ($this->renderFailure !== null) {
            throw $this->renderFailure;
        }

        $this->rendering = true;
        $this->renderStarted->push(true);
        $this->renderRelease->pop();
        $this->rendering = false;
    }
}
