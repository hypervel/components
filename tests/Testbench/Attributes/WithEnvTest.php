<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;

class WithEnvTest extends TestCase
{
    #[Test]
    public function itCanResolveDefinedEnvVariables(): void
    {
        $attribute = new WithEnv('TESTING_USING_ATTRIBUTE', '(true)');
        $callback = null;

        try {
            $this->assertNull(Env::get('TESTING_USING_ATTRIBUTE'));

            $callback = $attribute($this->app);

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
        $specialCallback = null;
        $zeroCallback = null;

        try {
            $specialCallback = (new WithEnv('TESTING_SPECIAL_ATTRIBUTE', $value))($this->app);
            $zeroCallback = (new WithEnv('TESTING_ZERO_ATTRIBUTE', '0'))($this->app);

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
    #[TestWith([false])]
    #[TestWith([true])]
    public function itRestoresValuesAfterScopedEnvironmentChanges(bool $hasOriginalValue): void
    {
        $key = 'TESTBENCH_ATTRIBUTE_OWNERSHIP';

        $this->withEnvironmentValue($key, null, function () use ($key, $hasOriginalValue): void {
            $originalValue = $hasOriginalValue ? '"original"' : null;

            if ($originalValue !== null) {
                Env::getRepository()->set($key, $originalValue);
            }

            $restore = (new WithEnv($key, 'temporary'))($this->app);

            try {
                $this->assertSame('temporary', Env::get($key));

                $this->withEnvironmentValue('TESTBENCH_OTHER_ATTRIBUTE_SETTING', 'other', static fn () => null);

                $this->assertSame('temporary', Env::get($key));
            } finally {
                $restore();
            }

            $this->assertSame($originalValue, Env::getRepository()->get($key));
        });
    }

    #[Test]
    public function itCannotChangeDefinedEnvVariables(): void
    {
        $callback = null;

        try {
            $_ENV['HYPERVEL_KEY'] = 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF';

            $attribute = new WithEnv('HYPERVEL_KEY', 'hypervel');

            $this->assertSame('AckfSECXIvnK5r28GVIWUAxmbBSjTsmF', Env::get('HYPERVEL_KEY'));

            $callback = $attribute($this->app);

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
