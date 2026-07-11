<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Closure;
use DateTimeImmutable;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Http\StreamOutput;
use Hypervel\ObjectPool\PoolErrorReporter;
use Hypervel\Support\Str;
use League\Flysystem\UnableToReadFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class FileResponseBuilder
{
    private const CHUNK_SIZE = 64 * 1024;

    /**
     * Build and stream a range-aware file response.
     *
     * @param Closure(): (false|string) $mimeType
     * @param Closure(): int $size
     * @param Closure(?int, ?int): mixed $streamResolver
     */
    public function build(
        Request $request,
        Response $response,
        string $path,
        ?string $name,
        array $headers,
        string $disposition,
        Closure $mimeType,
        Closure $size,
        Closure $streamResolver,
    ): Response {
        $headers['Content-Type'] ??= $mimeType();

        if (! array_key_exists('Content-Disposition', $headers)) {
            $filename = $name ?? basename($path);
            $headers['Content-Disposition'] = HeaderUtils::makeDisposition(
                $disposition,
                $filename,
                $this->fallbackName($filename),
            );
        }

        $headers['Accept-Ranges'] = in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)
            ? 'bytes'
            : 'none';

        foreach ($headers as $key => $value) {
            $response->headers->set(
                $key,
                is_string($value) || is_array($value) || $value === null ? $value : (string) $value,
            );
        }

        [$start, $end, $fileSize] = $this->resolveRange($request, $response, $size);
        $remaining = $start === null || $end === null ? null : $end - $start + 1;
        $rangeHeaders = $remaining === null
            ? []
            : ['Content-Range' => sprintf('bytes %d-%d/%d', $start, $end, $fileSize)];

        if ($remaining !== null) {
            $response->setStatusCode(206);
        }

        return $response->stream(function (StreamOutput $output) use (
            $streamResolver,
            $path,
            $start,
            $end,
            $remaining,
        ): void {
            $stream = $streamResolver($start, $end);

            if (! is_resource($stream)) {
                throw UnableToReadFile::fromLocation($path, 'The stream resolver did not return an open resource.');
            }

            try {
                $this->stream($stream, $output, $path, $remaining);
            } catch (Throwable $primaryException) {
                $this->closeStream($stream, $primaryException);

                throw $primaryException;
            }

            $this->closeStream($stream);
        }, $rangeHeaders);
    }

    /**
     * Resolve a satisfiable single byte range for the response.
     *
     * @param Closure(): int $size
     * @return array{0: ?int, 1: ?int, 2: ?int}
     */
    private function resolveRange(Request $request, Response $response, Closure $size): array
    {
        if ($request->getMethod() !== 'GET' || ! $request->headers->has('Range')) {
            return [null, null, null];
        }

        $range = $request->headers->get('Range', '');

        if (! preg_match('/^bytes=(\d*)-(\d*)$/i', $range, $matches)
            || ($matches[1] === '' && $matches[2] === '')
        ) {
            return [null, null, null];
        }

        $ifRange = $request->headers->get('If-Range');

        if ($ifRange !== null && ! $this->hasValidIfRangeHeader($response, $ifRange)) {
            return [null, null, null];
        }

        $fileSize = $size();

        return [...$this->validateRange($matches[1], $matches[2], $fileSize), $fileSize];
    }

    /**
     * Validate a syntactically valid byte range against the file size.
     *
     * @return array{int, int}
     */
    private function validateRange(string $start, string $end, int $fileSize): array
    {
        if ($start === '') {
            $suffixLength = (int) $end;

            if ($suffixLength <= 0 || $fileSize <= 0) {
                throw $this->unsatisfiableRange($fileSize);
            }

            return [max(0, $fileSize - $suffixLength), $fileSize - 1];
        }

        $rangeStart = (int) $start;

        if ($rangeStart < 0 || $rangeStart >= $fileSize) {
            throw $this->unsatisfiableRange($fileSize);
        }

        $rangeEnd = $end === '' ? $fileSize - 1 : (int) $end;

        if ($rangeStart > $rangeEnd) {
            throw $this->unsatisfiableRange($fileSize);
        }

        return [$rangeStart, min($rangeEnd, $fileSize - 1)];
    }

    /**
     * Create an unsatisfiable-range exception with the required response header.
     */
    private function unsatisfiableRange(int $fileSize): HttpException
    {
        return new HttpException(416, '', null, [
            'Content-Range' => sprintf('bytes */%d', $fileSize),
        ]);
    }

    /**
     * Determine if an If-Range validator matches the response metadata.
     */
    private function hasValidIfRangeHeader(Response $response, string $header): bool
    {
        if ($response->headers->get('ETag') === $header) {
            return true;
        }

        $lastModified = $response->headers->get('Last-Modified');

        if ($lastModified === null) {
            return false;
        }

        $lastModified = DateTimeImmutable::createFromFormat(DATE_RFC2822, $lastModified);

        return $lastModified !== false
            && $lastModified->format('D, d M Y H:i:s') . ' GMT' === $header;
    }

    /**
     * Stream bytes until EOF, client disconnect, or the range is complete.
     *
     * @param resource $stream
     */
    private function stream(mixed $stream, StreamOutput $output, string $path, ?int $remaining): void
    {
        while ($remaining === null || $remaining > 0) {
            if (feof($stream)) {
                if ($remaining !== null) {
                    throw UnableToReadFile::fromLocation($path, 'The stream ended before the requested range was complete.');
                }

                return;
            }

            $length = $remaining === null ? self::CHUNK_SIZE : min(self::CHUNK_SIZE, $remaining);
            $content = fread($stream, $length);

            if ($content === false) {
                throw UnableToReadFile::fromLocation($path, 'Unable to read from the stream.');
            }

            if ($content === '') {
                continue;
            }

            if (! $output->write($content)) {
                return;
            }

            if ($remaining !== null) {
                $remaining -= strlen($content);
            }
        }
    }

    /**
     * Close a response stream while preserving an earlier failure.
     *
     * @param resource $stream
     */
    private function closeStream(mixed $stream, ?Throwable $primaryException = null): void
    {
        if (! is_resource($stream)) {
            return;
        }

        try {
            if (! fclose($stream)) {
                throw new RuntimeException('Unable to close the file response stream.');
            }
        } catch (Throwable $closeException) {
            if ($primaryException === null) {
                throw $closeException;
            }

            PoolErrorReporter::report($closeException);
        }
    }

    /**
     * Convert a filename fallback to safe ASCII characters.
     */
    private function fallbackName(string $name): string
    {
        return str_replace('%', '', Str::ascii($name));
    }
}
