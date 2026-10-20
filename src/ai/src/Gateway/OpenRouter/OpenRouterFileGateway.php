<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenRouter;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Gateway\FileGateway;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\Concerns\EncodesResourceIds;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\Concerns\PreparesStorableFiles;
use Hypervel\Ai\Gateway\OpenRouter\Concerns\CreatesOpenRouterClient;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\FileResponse;
use Hypervel\Ai\Responses\StoredFileResponse;

class OpenRouterFileGateway implements FileGateway
{
    use CreatesOpenRouterClient;
    use EncodesResourceIds;
    use HandlesFailoverErrors;
    use PreparesStorableFiles;

    /**
     * Get a file by its ID.
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        /** @var FileProvider&Provider $provider */
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->get("files/{$this->resourceId($fileId)}")
        );

        return (new FileResponse(
            id: $response->json('id'),
            mimeType: $response->json('mime_type'),
        ))->withRawResponse($response);
    }

    /**
     * Store the given file.
     */
    public function putFile(
        FileProvider $provider,
        StorableFile $file,
    ): StoredFileResponse {
        /** @var FileProvider&Provider $provider */
        [$content, $mime, $name] = $this->prepareStorableFile($file);

        [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($file, Lab::OpenRouter);

        $provider = $provider->withHeaders($headers);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->attach('file', $content, $name, ['Content-Type' => $mime])
                ->post('files', $providerOptions)
        );

        return (new StoredFileResponse($response->json('id')))->withRawResponse($response);
    }

    /**
     * Get the base URL for the Files API, which rejects the in-region endpoints with a 403.
     */
    protected function baseUrl(Provider $provider): string
    {
        return 'https://openrouter.ai/api/v1';
    }

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(FileProvider $provider, string $fileId): void
    {
        /** @var FileProvider&Provider $provider */
        $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->delete("files/{$this->resourceId($fileId)}")
        );
    }
}
