<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Base62;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class Base62Test extends TestCase
{
    public function testEncode(): void
    {
        $this->assertSame('fMYsmVDc', Base62::encode(145667762035560));
    }

    public function testEncodeZero(): void
    {
        $this->assertSame('0', Base62::encode(0));
    }

    public function testEncodeNegativeNumberThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Base62::encode(-1);
    }

    public function testDecode(): void
    {
        $this->assertSame(145667762035560, Base62::decode('fMYsmVDc'));
    }

    public function testDecodeWithInvalidCharactersThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Base62::decode('fMYsmVDc***');
    }

    public function testDecodeEmptyStringThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Base62::decode('');
    }
}
