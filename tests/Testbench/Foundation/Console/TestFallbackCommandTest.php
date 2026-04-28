<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console;

use Hypervel\Testbench\Foundation\Console\TestFallbackCommand;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

class TestFallbackCommandTest extends TestCase
{
    #[Test]
    public function itReturnsFailureWhenCollisionInstallationIsDeclined(): void
    {
        $command = new TestFallbackCommandHarness(confirmed: false);

        $this->assertSame(TestFallbackCommand::FAILURE, $command->handle());
        $this->assertFalse($command->installCollisionDependenciesCalled);
    }

    #[Test]
    public function itInstallsCollisionWhenTheUserConfirms(): void
    {
        $command = new TestFallbackCommandHarness(confirmed: true);

        $this->assertSame(TestFallbackCommand::SUCCESS, $command->handle());
        $this->assertTrue($command->installCollisionDependenciesCalled);
    }
}

final class TestFallbackCommandHarness extends TestFallbackCommand
{
    /**
     * Indicates whether the install routine was called.
     */
    public bool $installCollisionDependenciesCalled = false;

    /**
     * Create a new test fallback command harness.
     */
    public function __construct(
        private readonly bool $confirmed,
    ) {
        parent::__construct();
    }

    /**
     * Confirm a console question.
     */
    #[Override]
    public function confirm(string $question, bool $default = false): bool
    {
        return $this->confirmed;
    }

    /**
     * Stub the dependency installer for tests.
     */
    #[Override]
    protected function installCollisionDependencies(): void
    {
        $this->installCollisionDependenciesCalled = true;
    }
}
