<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Functions;

use Hypervel\Tests\TestCase;
use stdClass;

use function Hypervel\Testbench\defined_environment_variables;

class DefinedEnvironmentVariablesTest extends TestCase
{
    /**
     * @var array<array-key, mixed>
     */
    private array $originalEnvironment;

    /**
     * @var array<array-key, mixed>
     */
    private array $originalServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironment = $_ENV;
        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnvironment;
        $_SERVER = $this->originalServer;

        parent::tearDown();
    }

    public function testItReturnsStringKeyedScalarAndNullEnvironmentValues(): void
    {
        $_ENV['TEST_DEFINED_OVERLAP'] = 'environment';
        $_SERVER['TEST_DEFINED_OVERLAP'] = 'server';
        $_ENV['TEST_DEFINED_NULL_FALLBACK'] = null;
        $_SERVER['TEST_DEFINED_NULL_FALLBACK'] = 'server';
        $_ENV['TEST_DEFINED_NULL'] = null;
        $_ENV['TEST_DEFINED_FALSE'] = false;
        $_ENV['TEST_DEFINED_ZERO'] = 0;
        $_ENV['TEST_DEFINED_EMPTY'] = '';
        $_ENV['TEST_DEFINED_ARRAY'] = ['invalid'];
        $_SERVER['TEST_DEFINED_OBJECT'] = new stdClass;
        $_ENV[987654] = 'numeric-environment';
        $_SERVER[987655] = 'numeric-server';

        $environment = defined_environment_variables();

        $this->assertSame('environment', $environment['TEST_DEFINED_OVERLAP']);
        $this->assertSame('server', $environment['TEST_DEFINED_NULL_FALLBACK']);
        $this->assertNull($environment['TEST_DEFINED_NULL']);
        $this->assertFalse($environment['TEST_DEFINED_FALSE']);
        $this->assertSame(0, $environment['TEST_DEFINED_ZERO']);
        $this->assertSame('', $environment['TEST_DEFINED_EMPTY']);
        $this->assertArrayNotHasKey('TEST_DEFINED_ARRAY', $environment);
        $this->assertArrayNotHasKey('TEST_DEFINED_OBJECT', $environment);
        $this->assertArrayNotHasKey(987654, $environment);
        $this->assertArrayNotHasKey(987655, $environment);
    }
}
