<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Server\Request;

use Hyperf\Codec\Xml;
use Hypervel\HttpMessage\Exceptions\BadRequestHttpException;
use Hypervel\HttpMessage\Server\RequestParserInterface;
use InvalidArgumentException;

class XmlParser implements RequestParserInterface
{
    public bool $throwException = true;

    /**
     * Parse the raw body as XML.
     */
    public function parse(string $rawBody, string $contentType): array
    {
        try {
            return Xml::toArray($rawBody);
        } catch (InvalidArgumentException $e) {
            if ($this->throwException) {
                throw new BadRequestHttpException('Invalid XML data in request body: ' . $e->getMessage());
            }
            return [];
        }
    }

    /**
     * Determine if the parser supports the given content type.
     */
    public function has(string $contentType): bool
    {
        return true;
    }
}
