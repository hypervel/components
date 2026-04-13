<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class WithEnvTest extends TestCase
{
    #[Test]
    public function itCanResolveDefinedEnvVariables(): void
    {
        $attribute = new WithEnv('TESTING_USING_ATTRIBUTE', '(true)');

        $this->assertNull(Env::get('TESTING_USING_ATTRIBUTE'));

        $callback = $attribute(m::mock(ApplicationContract::class));

        $this->assertTrue(Env::get('TESTING_USING_ATTRIBUTE'));

        value($callback);

        $this->assertNull(Env::get('TESTING_USING_ATTRIBUTE'));
    }

    #[Test]
    public function itDoesNotPersistDefinedEnvVariablesBetweenTests(): void
    {
        $this->assertNull(Env::get('TESTING_USING_ATTRIBUTE'));
    }

    #[Test]
    public function itCannotChangeDefinedEnvVariables(): void
    {
        $_ENV['HYPERVEL_KEY'] = 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF';

        $attribute = new WithEnv('HYPERVEL_KEY', 'hypervel');

        $this->assertSame('AckfSECXIvnK5r28GVIWUAxmbBSjTsmF', Env::get('HYPERVEL_KEY'));

        $callback = $attribute(m::mock(ApplicationContract::class));

        $this->assertSame('AckfSECXIvnK5r28GVIWUAxmbBSjTsmF', Env::get('HYPERVEL_KEY'));

        value($callback);

        $this->assertSame('AckfSECXIvnK5r28GVIWUAxmbBSjTsmF', Env::get('HYPERVEL_KEY'));

        unset($_ENV['HYPERVEL_KEY']);
    }
}
