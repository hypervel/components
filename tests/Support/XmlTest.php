<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Xml;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

/**
 * @internal
 * @coversNothing
 */
class XmlTest extends TestCase
{
    public function testToArray()
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
        $data = [
            'return_code' => 'SUCCESS',
            'return_msg' => 'OK',
        ];
        $this->assertSame($data, Xml::toArray($xml));
    }

    public function testToArrayException()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Syntax error.');

        Xml::toArray('xxxxx');
    }

    public function testToXml()
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
        $data = [
            'return_code' => 'SUCCESS',
            'return_msg' => 'OK',
        ];
        $this->assertSame(Xml::toXml(Xml::toArray($xml), null, 'xml'), Xml::toXml($data, null, 'xml'));
    }

    public function testXmlFailed()
    {
        $this->expectException(InvalidArgumentException::class);
        Xml::toArray('{"hype');
    }
}
