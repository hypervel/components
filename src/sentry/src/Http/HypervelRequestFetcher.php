<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Http;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\Integration\RequestFetcherInterface;
use Sentry\SentrySdk;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

class HypervelRequestFetcher implements RequestFetcherInterface
{
    /**
     * The context key of the request a child coroutine inherits for Sentry only.
     *
     * Child coroutines keep a reference to the request that started them under this key,
     * leaving the application's request context as the framework set it.
     */
    public const string REQUEST_CONTEXT_KEY = '__sentry.request';

    /**
     * Get the current request, or the request the current coroutine inherited for Sentry.
     *
     * @internal
     */
    public static function currentRequest(): ?Request
    {
        return RequestContext::getOrNull() ?? CoroutineContext::get(self::REQUEST_CONTEXT_KEY);
    }

    /**
     * Fetch the current request as a PSR-7 server request for Sentry.
     */
    public function fetchRequest(): ?ServerRequestInterface
    {
        $request = self::currentRequest();

        // Without a request we are not dealing with an HTTP request
        if ($request === null) {
            return null;
        }

        // Converted only when an event needs it, instead of caching a PSR-7 copy of every
        // request in the worker-shared container as upstream's SetRequestMiddleware does.
        // Symfony's PSR-7 bridge is not used because it decodes every JSON body, which
        // Sentry only reads for legacy error events with bodies within the size limit.
        try {
            // Set valid headers together instead of cloning the request for each header.
            $serverRequest = $this->createServerRequest($request, $request->headers->all());
        } catch (InvalidArgumentException) {
            $serverRequest = $this->createServerRequest($request, []);

            foreach ($request->headers->all() as $name => $values) {
                try {
                    $serverRequest = $serverRequest->withHeader((string) $name, $values);
                } catch (InvalidArgumentException) {
                    // Skip the headers PSR-7 rejects, such as null values, so they cannot drop the event
                }
            }
        }

        $cookies = [];

        foreach ($request->cookies->all() as $name => $value) {
            $cookies[$name] = SensitiveCookieFilter::filterValue($name, $value);
        }

        $canCollectLegacyBody = $this->canCollectLegacyBody($request);

        // Only the legacy body collector reads uploaded files
        if ($canCollectLegacyBody) {
            $serverRequest = $serverRequest->withUploadedFiles($this->convertUploadedFiles($request->files->all()));
        }

        return $serverRequest
            ->withCookieParams($cookies)
            ->withParsedBody($this->parsedBody($request, $canCollectLegacyBody));
    }

    /**
     * Create the PSR-7 request with the given headers.
     */
    private function createServerRequest(Request $request, array $headers): ServerRequest
    {
        $queryString = $request->server->getString('QUERY_STRING');

        // The URI uses the query string as it was received
        return new ServerRequest(
            $request->getMethod(),
            $request->getSchemeAndHttpHost() . $request->getBaseUrl() . $request->getPathInfo() . ($queryString !== '' ? '?' . $queryString : ''),
            $headers,
            $request->getContent(),
            serverParams: $request->server->all(),
        );
    }

    /**
     * Determine if the legacy body collector can read the request body.
     *
     * It only reads bodies with a known length within its limit, and it is the only reader of
     * uploaded files and of the parsed bodies of requests that are not forms.
     */
    private function canCollectLegacyBody(Request $request): bool
    {
        $limit = DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub())->getLegacyRequestBodyLimit();
        $length = (int) $request->headers->get('Content-Length');

        return $limit !== null && $length > 0 && ($limit === -1 || $length <= $limit);
    }

    /**
     * Get the parsed body of the given request.
     *
     * Only form requests use their parsed body with data collection, so all other bodies are read
     * raw. An empty parsed body would also hide the raw body from the legacy body collector.
     */
    private function parsedBody(Request $request, bool $canCollectLegacyBody): ?array
    {
        if ($this->isFormRequest($request)) {
            return $request->request->all();
        }

        if (! $canCollectLegacyBody) {
            return null;
        }

        $body = $request->getContentTypeFormat() === 'json'
            ? json_decode($request->getContent(), true, flags: JSON_BIGINT_AS_STRING)
            : $request->request->all();

        return is_array($body) && $body !== [] ? $body : null;
    }

    /**
     * Determine if the request body is a form.
     */
    private function isFormRequest(Request $request): bool
    {
        $mediaType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'), 2)[0]));

        return $mediaType === 'application/x-www-form-urlencoded' || $mediaType === 'multipart/form-data';
    }

    /**
     * Convert the given uploaded files to PSR-7 uploaded files.
     *
     * Sentry only reads their metadata, so the files are not opened.
     *
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private function convertUploadedFiles(array $files): array
    {
        $uploadedFiles = [];

        foreach ($files as $key => $file) {
            $uploadedFiles[$key] = match (true) {
                $file === null => new UploadedFile('', 0, UPLOAD_ERR_NO_FILE),
                $file instanceof SymfonyUploadedFile => new UploadedFile(
                    $file->getPathname(),
                    // An upload the application moved no longer exists at its temporary path
                    $file->isFile() ? (int) $file->getSize() : 0,
                    $file->getError(),
                    $file->getClientOriginalName(),
                    $file->getClientMimeType(),
                ),
                default => $this->convertUploadedFiles($file),
            };
        }

        return $uploadedFiles;
    }
}
