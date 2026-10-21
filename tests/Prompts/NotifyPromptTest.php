<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\NotifyPrompt;
use Hypervel\Tests\TestCase;
use ReflectionMethod;

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

class NotifyPromptTest extends TestCase
{
    public function testSetsTheTitle(): void
    {
        $prompt = new NotifyPrompt('Hello');

        $this->assertSame('Hello', $prompt->title);
        $this->assertSame('', $prompt->body);
    }

    public function testSetsTheTitleAndBody(): void
    {
        $prompt = new NotifyPrompt('Hello', 'World');

        $this->assertSame('Hello', $prompt->title);
        $this->assertSame('World', $prompt->body);
    }

    public function testSetsMacosOptions(): void
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

    public function testSetsLinuxOptions(): void
    {
        $prompt = new NotifyPrompt(
            title: 'Hello',
            body: 'World',
            icon: '/path/to/icon.png',
        );

        $this->assertSame('/path/to/icon.png', $prompt->icon);
    }

    public function testBuildsCorrectMacosCommand(): void
    {
        $prompt = new TestableNotifyPrompt('Hello', 'World');

        (new ReflectionMethod($prompt, 'sendMacOS'))->invoke($prompt);

        $this->assertSame('osascript', $prompt->executedCommand[0]);
        $this->assertSame('-e', $prompt->executedCommand[1]);
        $this->assertStringContainsString('display notification "World"', $prompt->executedCommand[2]);
        $this->assertStringContainsString('with title "Hello"', $prompt->executedCommand[2]);
        $this->assertStringNotContainsString('subtitle', $prompt->executedCommand[2]);
        $this->assertStringNotContainsString('sound name', $prompt->executedCommand[2]);
    }

    public function testIncludesSubtitleAndSoundInMacosCommand(): void
    {
        $prompt = new TestableNotifyPrompt(
            title: 'Hello',
            body: 'World',
            subtitle: 'Sub',
            sound: 'Glass',
        );

        (new ReflectionMethod($prompt, 'sendMacOS'))->invoke($prompt);

        $this->assertStringContainsString('subtitle "Sub"', $prompt->executedCommand[2]);
        $this->assertStringContainsString('sound name "Glass"', $prompt->executedCommand[2]);
    }

    public function testEscapesQuotesAndBackslashesInMacosCommand(): void
    {
        $prompt = new TestableNotifyPrompt('Say "hi"', 'C:\path');

        (new ReflectionMethod($prompt, 'sendMacOS'))->invoke($prompt);

        $this->assertSame(
            'display notification "C:\\\path" with title "Say \"hi\""',
            $prompt->executedCommand[2],
        );
    }

    public function testBuildsNotifySendCommand(): void
    {
        $prompt = new TestableNotifyPrompt(title: 'Hello', body: 'World', icon: 'dialog-information');

        (new ReflectionMethod($prompt, 'sendLinuxNotifySend'))->invoke($prompt);

        $this->assertSame(['notify-send', '--icon', 'dialog-information', 'Hello', 'World'], $prompt->executedCommand);
    }

    public function testBuildsKdialogCommand(): void
    {
        $prompt = new TestableNotifyPrompt('Hello', 'World');

        (new ReflectionMethod($prompt, 'sendLinuxKDialog'))->invoke($prompt);

        $this->assertSame(['kdialog', '--passivepopup', 'Hello: World', '5', '--title', 'Hello'], $prompt->executedCommand);
    }
}
