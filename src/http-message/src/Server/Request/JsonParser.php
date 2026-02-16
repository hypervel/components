<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Server\Request;

use Hypervel\Support\Json;
use Hypervel\HttpMessage\Exceptions\BadRequestHttpException;
use Hypervel\HttpMessage\Server\RequestParserInterface;
use JsonException;

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
        } catch (JsonException $e) {
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
