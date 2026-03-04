<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpMessage\Stub;

use Hypervel\HttpMessage\Server\RequestParserInterface;

class ParserStub implements RequestParserInterface
{
    public function parse(string $rawBody, string $contentType): array
    {
        return [
            'mock' => true,
        ];
    }

    public function has(string $contentType): bool
    {
        return true;
    }
}
