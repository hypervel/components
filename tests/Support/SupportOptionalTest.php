<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Optional;
use Hypervel\Tests\TestCase;
use stdClass;

class SupportOptionalTest extends TestCase
{
    public function testGetExistItemOnObject(): void
    {
        $expected = 'test';

        $targetObj = new stdClass;
        $targetObj->item = $expected;

        $optional = new Optional($targetObj);

        $this->assertEquals($expected, $optional->item);
    }

    public function testGetNotExistItemOnObject(): void
    {
        $targetObj = new stdClass;

        $optional = new Optional($targetObj);

        $this->assertNull($optional->item);
    }

    public function testIssetExistItemOnObject(): void
    {
        $targetObj = new stdClass;
        $targetObj->item = '';

        $optional = new Optional($targetObj);

        $this->assertTrue(isset($optional->item));
    }

    public function testIssetNotExistItemOnObject(): void
    {
        $targetObj = new stdClass;

        $optional = new Optional($targetObj);

        $this->assertFalse(isset($optional->item));
    }

    public function testGetExistItemOnArray(): void
    {
        $expected = 'test';

        $targetArr = [
            'item' => $expected,
        ];

        $optional = new Optional($targetArr);

        $this->assertEquals($expected, $optional['item']);
    }

    public function testGetNotExistItemOnArray(): void
    {
        $targetObj = [];

        $optional = new Optional($targetObj);

        $this->assertNull($optional['item']);
    }

    public function testIssetExistItemOnArray(): void
    {
        $targetArr = [
            'item' => '',
        ];

        $optional = new Optional($targetArr);

        $this->assertTrue(isset($optional['item']));
        $this->assertTrue(isset($optional->item));
    }

    public function testIssetNotExistItemOnArray(): void
    {
        $targetArr = [];

        $optional = new Optional($targetArr);

        $this->assertFalse(isset($optional['item']));
        $this->assertFalse(isset($optional->item));
    }

    public function testIssetExistItemOnNull(): void
    {
        $targetNull = null;

        $optional = new Optional($targetNull);

        $this->assertFalse(isset($optional->item));
    }

    public function testFlushStateClearsMacros(): void
    {
        Optional::macro('flushable', fn () => true);
        $this->assertTrue(Optional::hasMacro('flushable'));

        Optional::flushState();

        $this->assertFalse(Optional::hasMacro('flushable'));
    }
}
