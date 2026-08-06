<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Foundation\Application;
use Hypervel\Testbench\Concerns\CreatesApplication;
use Hypervel\Testbench\PHPUnit\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CreatesApplicationTest extends TestCase
{
    use CreatesApplication;

    #[Test]
    public function itProperlyLoadsHypervelApplication()
    {
        $app = $this->createApplication();

        $this->assertInstanceOf(Application::class, $app);
        $this->assertTrue($app->bound('config'));
        $this->assertTrue($app->bound('view'));
    }
}
