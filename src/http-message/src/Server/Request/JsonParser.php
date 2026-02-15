<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Server\Request;

use Hyperf\Codec\Json;
use Hypervel\HttpMessage\Exceptions\BadRequestHttpException;
use Hypervel\HttpMessage\Server\RequestParserInterface;
use InvalidArgumentException;

class JsonParser implements RequestParserInterface
{
    public bool $asArray = true;

    public bool $throwException = true;

    /**
     * Parse the raw body as JSON.
     */
    public function parse(string $rawBody, string $contentType): array
    {
        try {
            $parameters = Json::decode($rawBody, $this->asArray);
            return is_array($parameters) ? $parameters : [];
        } catch (InvalidArgumentException $e) {
            if ($this->throwException) {
                throw new BadRequestHttpException('Invalid JSON data in request body: ' . $e->getMessage());
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
