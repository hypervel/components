<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Mcp\Enums\ErrorCode;
use Hypervel\Mcp\Enums\MetaKey;
use Hypervel\Mcp\Enums\ProtocolHandshake;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Enums\RequestHeader;
use Hypervel\Mcp\Transport\HeaderValue;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Symfony\Component\HttpFoundation\Response;

class ValidateMcpHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $body = json_decode((string) $request->getContent(), true);

        if (! is_array($body) || ! isset($body['id']) || ! is_string($body['method'] ?? null)) {
            return $next($request);
        }

        $message = new JsonRpcRequest(
            id: is_int($body['id']) || is_string($body['id']) ? $body['id'] : 0,
            method: $body['method'],
            params: is_array($body['params'] ?? null) ? $body['params'] : [],
        );

        if ($message->isLegacy()) {
            return $this->unsupportedLegacyVersion($request, $body['id']) ?? $next($request);
        }

        $mismatch = $this->mismatch($request, RequestHeader::PROTOCOL_VERSION, $message->meta()[MetaKey::PROTOCOL_VERSION->value] ?? null, true)
            ?? $this->mismatch($request, RequestHeader::METHOD, $message->method, true)
            ?? $this->mismatch($request, RequestHeader::NAME, $message->name(), $message->requiresName());

        if ($mismatch === null) {
            return $next($request);
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $body['id'],
            'error' => [
                'code' => ErrorCode::HEADER_MISMATCH->value,
                'message' => $mismatch,
            ],
        ], 400, options: JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Validate the version on a request without modern metadata.
     *
     * Legacy clients never send the method and name headers, but they do send
     * the negotiated protocol version after the handshake.
     */
    protected function unsupportedLegacyVersion(Request $request, mixed $id): ?Response
    {
        $version = $request->header(RequestHeader::PROTOCOL_VERSION->value);

        if (! is_string($version) || $version === '' || in_array($version, ProtocolVersion::initializeSupported(), true)) {
            return null;
        }

        if (ProtocolVersion::tryFrom($version)?->handshake() === ProtocolHandshake::Discovery) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => ErrorCode::INVALID_PARAMS->value,
                    'message' => 'Invalid params: The request [_meta] is missing the required [' . MetaKey::PROTOCOL_VERSION->value . '] member.',
                ],
            ], 400, options: JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => ErrorCode::UNSUPPORTED_PROTOCOL_VERSION->value,
                'message' => 'Unsupported protocol version',
                'data' => [
                    'supported' => ProtocolVersion::initializeSupported(),
                    'requested' => $version,
                ],
            ],
        ], 400, options: JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Describe a missing or mismatched protocol header.
     */
    protected function mismatch(Request $request, RequestHeader $header, mixed $expected, bool $required): ?string
    {
        $value = $request->header($header->value);

        if (! is_string($value) || $value === '') {
            return $required ? "Header mismatch: The [{$header->value}] header is required." : null;
        }

        $headerValue = $header === RequestHeader::NAME
            ? HeaderValue::fromHeader($value)
            : new HeaderValue($value);

        if (! is_string($expected) || $headerValue->matches($expected)) {
            return null;
        }

        return "Header mismatch: The [{$header->value}] header value [{$headerValue->value}] does not match the request body value [{$expected}].";
    }
}
