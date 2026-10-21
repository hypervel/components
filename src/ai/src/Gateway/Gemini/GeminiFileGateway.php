<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Gemini;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Gateway\FileGateway;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\Concerns\CreatesClient;
use Hypervel\Ai\Gateway\Concerns\EncodesResourceIds;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\Concerns\PreparesStorableFiles;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\FileResponse;
use Hypervel\Ai\Responses\StoredFileResponse;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Support\Str;

class GeminiFileGateway implements FileGateway
{
    use CreatesClient;
    use EncodesResourceIds;
    use HandlesFailoverErrors;
    use PreparesStorableFiles;

    /**
     * Get a file by its ID.
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        /** @var FileProvider&Provider $provider */
        $fileId = $this->normalizeFileId($fileId);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->get($this->baseUrl($provider) . "/{$fileId}")->throw(),
        );

        return (new FileResponse(
            id: $response->json('name'),
            mimeType: $response->json('mimeType'),
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

        $uploadUrl = str_replace('/v1beta', '/upload/v1beta', $this->baseUrl($provider));

        [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($file, Lab::Gemini);

        $provider = $provider->withHeaders($headers);

        $response = $this->withErrorHandling($provider->name(), fn () => $this->client($provider)->attach(
            'file',
            $content,
            $name,
            ['Content-Type' => $mime]
        )->post("{$uploadUrl}/files", array_replace_recursive([
            'file' => ['display_name' => $name],
        ], $providerOptions))->throw());

        return (new StoredFileResponse($response->json('file.name')))->withRawResponse($response);
    }

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(FileProvider $provider, string $fileId): void
    {
        /** @var FileProvider&Provider $provider */
        $fileId = $this->normalizeFileId($fileId);

        $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->delete($this->baseUrl($provider) . "/{$fileId}")->throw(),
        );
    }

    /**
     * Normalize the file ID to include the resource prefix.
     */
    protected function normalizeFileId(string $fileId): string
    {
        return 'files/' . $this->resourceId(Str::chopStart($fileId, 'files/'));
    }

    /**
     * Get an HTTP client for the Gemini Files API.
     */
    protected function client(Provider $provider): PendingRequest
    {
        return $this->createClient(
            $this->baseUrl($provider),
            array_filter(['x-goog-api-key' => $provider->providerCredentials()['key']]),
            $provider->additionalConfiguration()['headers'] ?? [],
            timeout: null,
            throw: false,
        )->connection($this->httpConnection($provider));
    }

    /**
     * Get the base URL for the Gemini API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://generativelanguage.googleapis.com/v1beta', '/');
    }
}
