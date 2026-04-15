<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Testbench\Attributes\Define;
use Hypervel\Testbench\Attributes\DefineDatabase;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\DefineRoute;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DefineTest extends TestCase
{
    #[Test]
    public function itCanResolveEnvironmentDefinition(): void
    {
        $attribute = (new Define('env', 'setupEnvironmentData'))->resolve();

        $this->assertInstanceOf(DefineEnvironment::class, $attribute);
        $this->assertSame('setupEnvironmentData', $attribute->method);
    }

    #[Test]
    public function itCanResolveDatabaseDefinition(): void
    {
        $attribute = (new Define('db', 'setupDatabaseData'))->resolve();

        $this->assertInstanceOf(DefineDatabase::class, $attribute);
        $this->assertSame('setupDatabaseData', $attribute->method);
    }

    #[Test]
    public function itCanResolveRouteDefinition(): void
    {
        $attribute = (new Define('route', 'setupRouteData'))->resolve();

        $this->assertInstanceOf(DefineRoute::class, $attribute);
        $this->assertSame('setupRouteData', $attribute->method);
    }

    #[Test]
    public function itCannotResolveUnknownDefinition(): void
    {
        $attribute = (new Define('unknown', 'setupRouteData'))->resolve();

        $this->assertNull($attribute);
    }
}
