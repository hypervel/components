<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Anthropic;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Gateway\FileGateway;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\Anthropic\Concerns\CreatesAnthropicClient;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\Concerns\PreparesStorableFiles;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\FileResponse;
use Hypervel\Ai\Responses\StoredFileResponse;
use Hypervel\Http\Client\PendingRequest;

class AnthropicFileGateway implements FileGateway
{
    use CreatesAnthropicClient;
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
            fn () => $this->client($provider)->get("files/{$fileId}"),
        );

        return new FileResponse(
            id: $response->json('id'),
            mimeType: $response->json('mime_type'),
        );
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

        [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($file, Lab::Anthropic);

        $provider = $provider->withHeaders($headers);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->attach('file', $content, $name, ['Content-Type' => $mime])
                ->post('files', $providerOptions),
        );

        return new StoredFileResponse($response->json('id'));
    }

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(FileProvider $provider, string $fileId): void
    {
        /** @var FileProvider&Provider $provider */
        $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->delete("files/{$fileId}"),
        );
    }

    /**
     * Get an HTTP client for the Anthropic Files API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        return $this->createClient(
            $this->baseUrl($provider),
            array_filter([
                'x-api-key' => $provider->providerCredentials()['key'],
                'anthropic-version' => $config['version'] ?? '2023-06-01',
                'anthropic-beta' => 'files-api-2025-04-14',
            ]),
            $config['headers'] ?? [],
            $timeout ?? 60,
        )->connection($this->httpConnection($provider));
    }

    /**
     * Get the status codes that indicate a provider is transiently unavailable and the request should fail over.
     */
    protected function overloadedStatusCodes(): array
    {
        // 529 is Anthropic's own "overloaded" status, plus the shared transient gateway and Cloudflare codes.
        return [529, 502, 503, 504, 520, 522, 524];
    }
}
