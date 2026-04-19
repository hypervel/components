<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Foundation\Application;
use Hypervel\Testbench\Concerns\CreatesApplication;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;
use Hypervel\Testbench\PHPUnit\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

class CreatesApplicationTest extends TestCase
{
    use CreatesApplication;

    #[Override]
    protected function tearDown(): void
    {
        TestbenchApplication::flushState($this);

        parent::tearDown();
    }

    #[Test]
    public function itProperlyLoadsHypervelApplication()
    {
        $app = $this->createApplication();

        $this->assertInstanceOf(Application::class, $app);
        $this->assertTrue($app->bound('config'));
        $this->assertTrue($app->bound('view'));
    }
}
