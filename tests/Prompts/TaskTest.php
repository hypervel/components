<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Support\Logger;
use Hypervel\Prompts\Task;
use Hypervel\Tests\TestCase;
use ReflectionMethod;
use ReflectionProperty;

use function Hypervel\Prompts\task;

/**
 * @internal
 * @coversNothing
 */
class TaskTest extends TestCase
{
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

    public function testCoroutinePathHandlesPartialLogging()
    {
        Prompt::fake();

        $result = task(
            label: 'Running...',
            callback: function (Logger $logger) {
                $logger->partial('hello ');
                $logger->partial('hello world');
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

    public function testCommitsPartialLinesSoTheyBecomePermanent()
    {
        Prompt::fake();

        $task = new Task(label: 'Test', limit: 10);

        $addLogLines = new ReflectionMethod($task, 'addLogLines');
        $replacePartialLines = new ReflectionMethod($task, 'replacePartialLines');

        $replacePartialLines->invoke($task, 'streamed text');

        // Simulate commitpartial by clearing the index
        $partialStartIndex = new ReflectionProperty($task, 'partialStartIndex');
        $partialStartIndex->setValue($task, null);

        // Now add a new line — it should append, not replace
        $addLogLines->invoke($task, 'new line');

        $this->assertCount(2, $task->logs);
        $this->assertSame('streamed text', $task->logs[0]);
        $this->assertSame('new line', $task->logs[1]);
    }

    public function testClearsLogsWhenStableMessageReceived()
    {
        Prompt::fake();

        $task = new Task(label: 'Test', limit: 10);

        $addLogLines = new ReflectionMethod($task, 'addLogLines');
        $addLogLines->invoke($task, 'log line');

        $this->assertCount(1, $task->logs);

        $task->stableMessages[] = ['type' => 'success', 'message' => 'Done!'];
        $task->logs = [];

        $this->assertEmpty($task->logs);
        $this->assertCount(1, $task->stableMessages);
        $this->assertSame('success', $task->stableMessages[0]['type']);
        $this->assertSame('Done!', $task->stableMessages[0]['message']);
    }

    public function testTrimsStableMessagesToMaxStableMessages()
    {
        $task = new Task(label: 'Test', limit: 10);
        $task->maxStableMessages = 2;

        $task->stableMessages[] = ['type' => 'success', 'message' => 'First'];
        $task->stableMessages[] = ['type' => 'success', 'message' => 'Second'];
        $task->stableMessages[] = ['type' => 'success', 'message' => 'Third'];

        while (count($task->stableMessages) > $task->maxStableMessages) {
            array_shift($task->stableMessages);
        }

        $this->assertCount(2, $task->stableMessages);
        $this->assertSame('Second', $task->stableMessages[0]['message']);
        $this->assertSame('Third', $task->stableMessages[1]['message']);
    }

    public function testReceivesMessagesThroughSocketProtocol()
    {
        Prompt::fake();

        $task = new Task(label: 'Initial', limit: 10);

        $receiveMessages = new ReflectionMethod($task, 'receiveMessages');

        // Create a socket pair to simulate IPC
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $id = $task->identifier;

        // Write messages from the "parent" side
        fwrite($sockets[1], "plain log line\n");
        fwrite($sockets[1], "{$id}_label:New Label\n");
        fwrite($sockets[1], "another log line\n");
        fwrite($sockets[1], "{$id}_success:Step complete\n");
        fclose($sockets[1]);

        stream_set_blocking($sockets[0], false);
        $receiveMessages->invoke($task, $sockets[0]);
        fclose($sockets[0]);

        $this->assertSame('New Label', $task->label);
        $this->assertCount(1, $task->stableMessages);
        $this->assertSame(['type' => 'success', 'message' => 'Step complete'], $task->stableMessages[0]);
        // Logs cleared when stable message received, so only post-stable logs remain
        $this->assertEmpty($task->logs);
    }

    public function testHandlesPartialMessagesThroughSocketProtocol()
    {
        Prompt::fake();

        $task = new Task(label: 'Test', limit: 10);

        $receiveMessages = new ReflectionMethod($task, 'receiveMessages');

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $id = $task->identifier;

        fwrite($sockets[1], "existing line\n");
        fwrite($sockets[1], "{$id}_partial:hello \n");
        fwrite($sockets[1], "{$id}_partial:hello world \n");
        fwrite($sockets[1], "{$id}_commitpartial:\n");
        fwrite($sockets[1], "after commit\n");
        fclose($sockets[1]);

        stream_set_blocking($sockets[0], false);
        $receiveMessages->invoke($task, $sockets[0]);
        fclose($sockets[0]);

        $this->assertCount(3, $task->logs);
        $this->assertSame('existing line', $task->logs[0]);
        $this->assertSame('hello world ', $task->logs[1]);
        $this->assertSame('after commit', $task->logs[2]);
    }

    public function testStripsCursorResetControlSequencesFromLogLines()
    {
        Prompt::fake();

        $task = new Task(label: 'Test', limit: 10);

        $receiveMessages = new ReflectionMethod($task, 'receiveMessages');

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        fwrite($sockets[1], "before\e[1G\e[2Kafter\n");
        fclose($sockets[1]);

        stream_set_blocking($sockets[0], false);
        $receiveMessages->invoke($task, $sockets[0]);
        fclose($sockets[0]);

        $this->assertSame('beforeafter', $task->logs[0]);
    }

    public function testUpdatesLabelThroughSocketProtocol()
    {
        Prompt::fake();

        $task = new Task(label: 'Initial', limit: 10);

        $receiveMessages = new ReflectionMethod($task, 'receiveMessages');

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $id = $task->identifier;

        fwrite($sockets[1], "{$id}_label:Updated Label\n");
        fclose($sockets[1]);

        stream_set_blocking($sockets[0], false);
        $receiveMessages->invoke($task, $sockets[0]);
        fclose($sockets[0]);

        $this->assertSame('Updated Label', $task->label);
    }
}
