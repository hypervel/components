<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

#[WithConfig('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF')]
class EncryptionTest extends TestCase
{
    #[Test]
    public function itCanEncryptString(): void
    {
        $this->assertIsString(encrypt('hypervel'));
    }
}
