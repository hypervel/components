<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpMessage;

use Hypervel\HttpMessage\Exceptions\BadRequestHttpException;
use Hypervel\HttpMessage\Server\Request\JsonParser;
use Hypervel\HttpMessage\Server\Request\XmlParser;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class RequestParserTest extends TestCase
{
    public function testJsonParserFailed()
    {
        $this->expectException(BadRequestHttpException::class);
        $parser = new JsonParser();
        $parser->parse('{"hy"', '');
    }

    public function testXmlParserFailed()
    {
        $this->expectException(BadRequestHttpException::class);
        $parser = new XmlParser();
        $parser->parse('{"hy"', '');
    }
}
