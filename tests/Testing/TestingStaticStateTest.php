<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Testing\PendingCommand;
use Hypervel\Testing\TestComponent;
use Hypervel\Testing\TestView;
use Hypervel\Tests\TestCase;

class TestingStaticStateTest extends TestCase
{
    public function testTestComponentFlushStateClearsMacros(): void
    {
        TestComponent::macro('testingStaticStateProbe', static fn (): string => 'ok');

        $this->assertTrue(TestComponent::hasMacro('testingStaticStateProbe'));

        TestComponent::flushState();

        $this->assertFalse(TestComponent::hasMacro('testingStaticStateProbe'));
    }

    public function testTestViewFlushStateClearsMacros(): void
    {
        TestView::macro('testingStaticStateProbe', static fn (): string => 'ok');

        $this->assertTrue(TestView::hasMacro('testingStaticStateProbe'));

        TestView::flushState();

        $this->assertFalse(TestView::hasMacro('testingStaticStateProbe'));
    }

    public function testPendingCommandFlushStateClearsMacros(): void
    {
        PendingCommand::macro('testingStaticStateProbe', static fn (): string => 'ok');

        $this->assertTrue(PendingCommand::hasMacro('testingStaticStateProbe'));

        PendingCommand::flushState();

        $this->assertFalse(PendingCommand::hasMacro('testingStaticStateProbe'));
    }
}
