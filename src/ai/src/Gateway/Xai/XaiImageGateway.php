<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Xai;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Gateway\ImageGateway;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\ProviderImage;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\GeneratedImage;
use Hypervel\Ai\Responses\Data\ImageUsage;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\ImageResponse;
use Hypervel\Http\UploadedFile;
use InvalidArgumentException;

class XaiImageGateway implements ImageGateway
{
    use Concerns\CreatesXaiClient;
    use HandlesFailoverErrors;

    /**
     * Generate an image.
     *
     * @param array<Image> $attachments
     * @param null|'high'|'low'|'medium' $quality
     * @param array<string, mixed> $providerOptions
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
        array $providerOptions = [],
    ): ImageResponse {
        /** @var ImageProvider&Provider $provider */
        $options = $provider->defaultImageOptions($size, $quality);
        $body = array_merge($providerOptions, [
            'model' => $model,
            'prompt' => $prompt,
            'response_format' => 'b64_json',
        ], $options);

        if ($attachments !== []) {
            $images = array_values(array_map($this->mapImageAttachment(...), $attachments));

            if (count($images) === 1) {
                $body['image'] = $images[0];
            } else {
                $body['images'] = $images;
            }
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 120)
                ->post($attachments === [] ? 'images/generations' : 'images/edits', $body)
        );

        $response = $response->json();

        return new ImageResponse(
            collect($response['data'] ?? [])->map(fn (array $image): GeneratedImage => new GeneratedImage(
                $image['b64_json'] ?? '',
                $image['mime_type'] ?? 'image/jpeg',
            )),
            new ImageUsage,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Map a reference image to an xAI image input.
     */
    protected function mapImageAttachment(mixed $attachment): array
    {
        return match (true) {
            $attachment instanceof ProviderImage => ['file_id' => $attachment->id],
            $attachment instanceof RemoteImage => ['url' => $attachment->url],
            $attachment instanceof Base64Image => ['url' => 'data:' . ($attachment->mimeType() ?? 'image/png') . ';base64,' . $attachment->base64],
            $attachment instanceof Image && $attachment instanceof StorableFile => ['url' => 'data:' . ($attachment->mimeType() ?? 'image/png') . ';base64,' . base64_encode($attachment->content())],
            $attachment instanceof UploadedFile => ['url' => 'data:' . $attachment->getClientMimeType() . ';base64,' . base64_encode($attachment->get())],
            default => throw new InvalidArgumentException('Unsupported image attachment type [' . get_debug_type($attachment) . ']'),
        };
    }
}
