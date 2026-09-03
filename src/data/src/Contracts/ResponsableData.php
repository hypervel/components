<?php

declare(strict_types=1);

namespace Hypervel\Data\Contracts;

use Hypervel\Contracts\Support\Responsable;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface ResponsableData extends Responsable
{
    /**
     * Create an HTTP response that represents the data object.
     */
    public function toResponse(Request $request): Response;

    /**
     * Get the JSON serialization options for the resource response.
     */
    public static function jsonOptions(): int;

    /**
     * Customize the outgoing resource response.
     */
    public function withResponse(Request $request, JsonResponse $response): void;

    /**
     * Get the request properties that may be included.
     */
    public static function allowedRequestIncludes(): ?array;

    /**
     * Get the request properties that may be excluded.
     */
    public static function allowedRequestExcludes(): ?array;

    /**
     * Get the request properties allowed by an only selection.
     */
    public static function allowedRequestOnly(): ?array;

    /**
     * Get the request properties allowed by an except selection.
     */
    public static function allowedRequestExcept(): ?array;
}
