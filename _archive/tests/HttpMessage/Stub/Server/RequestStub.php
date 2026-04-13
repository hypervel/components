<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpMessage\Stub\Server;

use Hypervel\HttpMessage\Server\Request;
use Hypervel\HttpMessage\Server\RequestParserInterface;
use Psr\Http\Message\RequestInterface;

class RequestStub extends Request
{
    public static function normalizeParsedBody(array $data = [], ?RequestInterface $request = null): array
    {
        return parent::normalizeParsedBody($data, $request);
    }

    public static function setParser(?RequestParserInterface $parser): void
    {
        static::$parser = $parser;
    }

    public static function getParser(): RequestParserInterface
    {
        return parent::getParser();
    }
}
