<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\NotifyPrompt;
use Hypervel\Tests\TestCase;

class TestableNotifyPrompt extends NotifyPrompt
{
    /** @var array<int, string> */
    public array $executedCommand = [];

    protected function execute(array $command): bool
    {
        $this->executedCommand = $command;

        return true;
    }
}

/**
 * @internal
 * @coversNothing
 */
class NotifyPromptTest extends TestCase
{
    public function testSetsTheTitle()
    {
        $prompt = new NotifyPrompt('Hello');

        $this->assertSame('Hello', $prompt->title);
        $this->assertSame('', $prompt->body);
    }

    public function testSetsTheTitleAndBody()
    {
        $prompt = new NotifyPrompt('Hello', 'World');

        $this->assertSame('Hello', $prompt->title);
        $this->assertSame('World', $prompt->body);
    }

    public function testSetsMacosOptions()
    {
        $prompt = new NotifyPrompt(
            title: 'Hello',
            body: 'World',
            subtitle: 'Sub',
            sound: 'Glass',
        );

        $this->assertSame('Sub', $prompt->subtitle);
        $this->assertSame('Glass', $prompt->sound);
    }

    public function testSetsLinuxOptions()
    {
        $prompt = new NotifyPrompt(
            title: 'Hello',
            body: 'World',
            icon: '/path/to/icon.png',
        );

        $this->assertSame('/path/to/icon.png', $prompt->icon);
    }

    public function testBuildsCorrectMacosCommand()
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('macOS only');
        }

        $prompt = new TestableNotifyPrompt('Hello', 'World');

        $prompt->prompt();

        $this->assertSame('osascript', $prompt->executedCommand[0]);
        $this->assertSame('-e', $prompt->executedCommand[1]);
        $this->assertStringContainsString('display notification "World"', $prompt->executedCommand[2]);
        $this->assertStringContainsString('with title "Hello"', $prompt->executedCommand[2]);
        $this->assertStringNotContainsString('subtitle', $prompt->executedCommand[2]);
        $this->assertStringNotContainsString('sound name', $prompt->executedCommand[2]);
    }

    public function testIncludesSubtitleAndSoundInMacosCommand()
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('macOS only');
        }

        $prompt = new TestableNotifyPrompt(
            title: 'Hello',
            body: 'World',
            subtitle: 'Sub',
            sound: 'Glass',
        );

        $prompt->prompt();

        $this->assertStringContainsString('subtitle "Sub"', $prompt->executedCommand[2]);
        $this->assertStringContainsString('sound name "Glass"', $prompt->executedCommand[2]);
    }
}
