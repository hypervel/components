<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Hypervel\Http\Request;
use Hypervel\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;
use Symfony\Component\HttpFoundation\Response;

class ServeFile
{
    /**
     * Create a new invokable controller to serve files.
     */
    public function __construct(
        protected string $disk,
        protected array $config,
        protected bool $isProduction,
    ) {
    }

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, string $path): Response
    {
        abort_unless(
            $this->hasValidSignature($request),
            $this->isProduction ? 404 : 403
        );
        try {
            $disk = Storage::disk($this->disk);

            abort_unless($disk->exists($path), 404);

            $headers = [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            ];

            return tap(
                // The contract omits adapter response methods, which every shipped disk provides.
                // @phpstan-ignore method.notFound
                $disk->serve($request, $path, headers: $headers),
                function ($response) use ($headers) {
                    if (! $response->headers->has('Content-Security-Policy')) {
                        $response->headers->replace($headers);
                    }
                }
            );
        } catch (PathTraversalDetected) {
            abort(404);
        }
    }

    /**
     * Determine if the request has a valid signature if applicable.
     */
    protected function hasValidSignature(Request $request): bool
    {
        return ! $request->boolean('upload') && (
            ($this->config['visibility'] ?? 'private') === 'public'
            || $request->hasValidRelativeSignature()
        );
    }
}
