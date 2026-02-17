<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage;

use Hypervel\HttpMessage\Server\Request\Parser;
use Hypervel\HttpMessage\Server\RequestParserInterface;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                RequestParserInterface::class => Parser::class,
            ],
        ];
    }
}
