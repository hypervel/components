<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;

class WithEnvTest extends TestCase
{
    #[Test]
    public function itCanResolveDefinedEnvVariables(): void
    {
        $attribute = new WithEnv('TESTING_USING_ATTRIBUTE', '(true)');
        $callback = null;

        try {
            $this->assertNull(Env::get('TESTING_USING_ATTRIBUTE'));

            $callback = $attribute(m::mock(ApplicationContract::class));

            $this->assertTrue(Env::get('TESTING_USING_ATTRIBUTE'));
        } finally {
            if ($callback !== null) {
                value($callback);
            }
        }

        $this->assertNull(Env::get('TESTING_USING_ATTRIBUTE'));
    }

    #[Test]
    public function itCanResolveSpecialCharactersAndZero(): void
    {
        $value = "O'Reilly\\path\" \$HOME\nline\rreturn\fform\ttab\vvertical # hash";
        $app = m::mock(ApplicationContract::class);
        $specialCallback = null;
        $zeroCallback = null;

        try {
            $specialCallback = (new WithEnv('TESTING_SPECIAL_ATTRIBUTE', $value))($app);
            $zeroCallback = (new WithEnv('TESTING_ZERO_ATTRIBUTE', '0'))($app);

            $this->assertSame($value, Env::get('TESTING_SPECIAL_ATTRIBUTE'));
            $this->assertSame('0', Env::get('TESTING_ZERO_ATTRIBUTE'));
        } finally {
            if ($zeroCallback !== null) {
                value($zeroCallback);
            }

            if ($specialCallback !== null) {
                value($specialCallback);
            }
        }

        $this->assertNull(Env::get('TESTING_SPECIAL_ATTRIBUTE'));
        $this->assertNull(Env::get('TESTING_ZERO_ATTRIBUTE'));
    }

    #[Test]
    public function itDoesNotPersistDefinedEnvVariablesBetweenTests(): void
    {
        $this->assertNull(Env::get('TESTING_USING_ATTRIBUTE'));
    }

    #[Test]
    public function itCannotChangeDefinedEnvVariables(): void
    {
        $callback = null;

        try {
            $_ENV['HYPERVEL_KEY'] = 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF';

            $attribute = new WithEnv('HYPERVEL_KEY', 'hypervel');

            $this->assertSame('AckfSECXIvnK5r28GVIWUAxmbBSjTsmF', Env::get('HYPERVEL_KEY'));

            $callback = $attribute(m::mock(ApplicationContract::class));

            $this->assertSame('AckfSECXIvnK5r28GVIWUAxmbBSjTsmF', Env::get('HYPERVEL_KEY'));

            value($callback);
            $callback = null;

            $this->assertSame('AckfSECXIvnK5r28GVIWUAxmbBSjTsmF', Env::get('HYPERVEL_KEY'));
        } finally {
            if ($callback !== null) {
                value($callback);
            }

            unset($_ENV['HYPERVEL_KEY']);
        }
    }
}
