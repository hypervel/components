<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenAi;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Gateway\FileGateway;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\Concerns\PreparesStorableFiles;
use Hypervel\Ai\Gateway\OpenAi\Concerns\CreatesOpenAiClient;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\FileResponse;
use Hypervel\Ai\Responses\StoredFileResponse;

class OpenAiFileGateway implements FileGateway
{
    use CreatesOpenAiClient;
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
                ->get("files/{$fileId}")
        );

        return new FileResponse(
            id: $response->json('id'),
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

        [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($file, $this->providerOptionsKey());

        $provider = $provider->withHeaders($headers);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->attach('file', $content, $name, ['Content-Type' => $mime])
                ->post('files', array_merge(
                    ['purpose' => $this->defaultPurpose()],
                    $providerOptions,
                ))
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
            fn () => $this->client($provider)
                ->delete("files/{$fileId}")
        );
    }

    /**
     * Get the default purpose to use when a file does not specify one.
     */
    protected function defaultPurpose(): string
    {
        return 'user_data';
    }

    /**
     * Get the provider key used to resolve file upload options.
     */
    protected function providerOptionsKey(): Lab
    {
        return Lab::OpenAI;
    }
}
