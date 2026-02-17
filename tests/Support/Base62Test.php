<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Base62;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

/**
 * @internal
 * @coversNothing
 */
class Base62Test extends TestCase
{
    public function testEncode()
    {
        $this->assertSame('fMYsmVDc', Base62::encode(145667762035560));
    }

    public function testDecode()
    {
        $this->assertSame(145667762035560, Base62::decode('fMYsmVDc'));
    }

    public function testDecodeWithInvalidCharactersThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);

        Base62::decode('fMYsmVDc***');
    }

    public function testDecodeEmptyStringThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);

        Base62::decode('');
    }
}
