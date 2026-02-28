<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Server\Request;

use Hypervel\HttpMessage\Server\RequestParserInterface;
use InvalidArgumentException;

class Parser implements RequestParserInterface
{
    protected array $parsers = [];

    public function __construct()
    {
        $jsonParser = new JsonParser();
        $xmlParser = new XmlParser();

        $this->parsers = [
            'application/json' => $jsonParser,
            'text/json' => $jsonParser,
            'application/xml' => $xmlParser,
            'text/xml' => $xmlParser,
        ];
    }

    /**
     * Parse the raw body using the appropriate parser for the content type.
     */
    public function parse(string $rawBody, string $contentType): array
    {
        $contentType = strtolower($contentType);
        if (! array_key_exists($contentType, $this->parsers)) {
            throw new InvalidArgumentException("The '{$contentType}' request parser is not defined.");
        }

        $parser = $this->parsers[$contentType];
        if (! $parser instanceof RequestParserInterface) {
            throw new InvalidArgumentException("The '{$contentType}' request parser is invalid. It must implement the Hypervel\\HttpMessage\\Server\\RequestParserInterface.");
        }

        return $parser->parse($rawBody, $contentType);
    }

    /**
     * Determine if a parser exists for the given content type.
     */
    public function has(string $contentType): bool
    {
        return array_key_exists(strtolower($contentType), $this->parsers);
    }
}
