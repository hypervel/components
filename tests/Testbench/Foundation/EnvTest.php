<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation;

use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class EnvTest extends TestCase
{
    #[Test]
    public function itCanDeterminedHasEnvValues()
    {
        $_ENV['TESTING_TRUE_EXAMPLE'] = true;
        $_ENV['TESTING_FALSE_EXAMPLE'] = false;
        $_ENV['TESTING_EMPTY_EXAMPLE'] = '';

        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER'), Env::has('APP_KEY'));
        $this->assertFalse(Env::has('ASSET_URL'));
        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER') ? true : false, Env::has('LOG_DEPRECATIONS_CHANNEL'));
        $this->assertTrue(Env::has('TESTING_TRUE_EXAMPLE'));
        $this->assertTrue(Env::has('TESTING_FALSE_EXAMPLE'));
        $this->assertTrue(Env::has('TESTING_EMPTY_EXAMPLE'));

        unset(
            $_ENV['TESTING_TRUE_EXAMPLE'],
            $_ENV['TESTING_FALSE_EXAMPLE'],
            $_ENV['TESTING_EMPTY_EXAMPLE']
        );
    }

    #[Test]
    #[WithEnv('TESTING_USING_ATTRIBUTE', '(true)')]
    public function itCanCorrectlyForwardEnvValues()
    {
        $_ENV['TESTING_TRUE_EXAMPLE'] = true;
        $_ENV['TESTING_FALSE_EXAMPLE'] = false;
        $_ENV['TESTING_EMPTY_EXAMPLE'] = '';

        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER') ? 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF' : false, Env::forward('APP_KEY'));
        $this->assertFalse(Env::forward('ASSET_URL'));
        $this->assertSame('(null)', Env::forward('ASSET_URL', null));
        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER') ? '(null)' : false, Env::forward('LOG_DEPRECATIONS_CHANNEL'));
        $this->assertSame('(null)', Env::forward('LOG_DEPRECATIONS_CHANNEL', null));
        $this->assertSame('(true)', Env::forward('TESTING_TRUE_EXAMPLE'));
        $this->assertSame('(false)', Env::forward('TESTING_FALSE_EXAMPLE'));
        $this->assertSame('(empty)', Env::forward('TESTING_EMPTY_EXAMPLE'));

        unset(
            $_ENV['TESTING_TRUE_EXAMPLE'],
            $_ENV['TESTING_FALSE_EXAMPLE'],
            $_ENV['TESTING_EMPTY_EXAMPLE']
        );
    }
}
