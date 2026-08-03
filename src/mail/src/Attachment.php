<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Filesystem\Factory as FilesystemFactory;
use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Http\UploadedFile;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use RuntimeException;

use function with;

class Attachment
{
    use Macroable;

    /**
     * The attached file's filename.
     */
    public ?string $as = null;

    /**
     * The attached file's mime type.
     */
    public ?string $mime = null;

    /**
     * Create a mail attachment.
     *
     * @param Closure $resolver a callback that attaches the attachment to the mail message
     */
    private function __construct(
        protected Closure $resolver
    ) {
    }

    /**
     * Create a mail attachment from a path.
     */
    public static function fromPath(string $path): static
    {
        return new static(fn ($attachment, $pathStrategy) => $pathStrategy($path, $attachment));
    }

    /**
     * Create a mail attachment from a URL.
     */
    public static function fromUrl(string $url): static
    {
        if (! Str::isUrl($url, ['http', 'https'])) {
            throw new InvalidArgumentException('Attachment URLs must use the http or https scheme.');
        }

        return static::fromPath($url);
    }

    /**
     * Create a mail attachment from in-memory data.
     */
    public static function fromData(Closure $data, ?string $name = null): static
    {
        return (new static(
            fn ($attachment, $pathStrategy, $dataStrategy) => $dataStrategy($data, $attachment)
        ))->as($name);
    }

    /**
     * Create a mail attachment from an UploadedFile instance.
     */
    public static function fromUploadedFile(UploadedFile $file): static
    {
        return new static(function ($attachment, $pathStrategy, $dataStrategy) use ($file) {
            $attachment
                ->as($file->getClientOriginalName())
                ->withMime($file->getMimeType() ?? $file->getClientMimeType());

            return $dataStrategy(fn () => $file->get(), $attachment);
        });
    }

    /**
     * Create a mail attachment from a file in the default storage disk.
     */
    public static function fromStorage(string $path): static
    {
        return static::fromStorageDisk(null, $path);
    }

    /**
     * Create a mail attachment from a file in the specified storage disk.
     */
    public static function fromStorageDisk(?string $disk, string $path): static
    {
        return new static(function ($attachment, $pathStrategy, $dataStrategy) use ($disk, $path) {
            $storage = static::getStorageDisk($disk);
            $mime = $attachment->mime;

            if ($mime === null) {
                // The contract omits adapter metadata methods, which every shipped disk provides.
                // @phpstan-ignore method.notFound
                $mime = $storage->mimeType($path);
            }

            $attachment->as($attachment->as ?? basename($path));

            if ($mime !== false) {
                $attachment->withMime($mime);
            }

            return $dataStrategy(fn () => $storage->get($path), $attachment);
        });
    }

    // REMOVED: Laravel's fromCloudStorage() helper is intentionally not ported.
    // Use fromStorageDisk('s3', $path) or another named disk instead.

    /**
     * Get a storage disk instance.
     */
    protected static function getStorageDisk(?string $disk): Filesystem
    {
        return Container::getInstance()->make(
            FilesystemFactory::class
        )->disk($disk);
    }

    /**
     * Set the attached file's filename.
     */
    public function as(?string $name): static
    {
        $this->as = $name;

        return $this;
    }

    /**
     * Set the attached file's mime type.
     */
    public function withMime(string $mime): static
    {
        $this->mime = $mime;

        return $this;
    }

    /**
     * Attach the attachment with the given strategies.
     */
    public function attachWith(Closure $pathStrategy, Closure $dataStrategy): mixed
    {
        return ($this->resolver)($this, $pathStrategy, $dataStrategy);
    }

    /**
     * Attach the attachment to a built-in mail type.
     *
     * @param Mailable|MailMessage|Message $mail
     *
     * @throws RuntimeException
     */
    public function attachTo(object $mail, array $options = []): mixed
    {
        return $this->attachWith(
            fn (string $path) => $mail->attach($path, [
                'as' => $options['as'] ?? $this->as,
                'mime' => $options['mime'] ?? $this->mime,
            ]),
            function ($data) use ($mail, $options) {
                $options = [
                    'as' => $options['as'] ?? $this->as,
                    'mime' => $options['mime'] ?? $this->mime,
                ];

                if ($options['as'] === null) {
                    throw new RuntimeException('Attachment requires a filename to be specified.');
                }

                return $mail->attachData($data(), $options['as'], ['mime' => $options['mime']]);
            }
        );
    }

    /**
     * Determine if the given attachment is equivalent to this attachment.
     */
    public function isEquivalent(Attachment $attachment, array $options = []): bool
    {
        return with([
            'as' => $options['as'] ?? $attachment->as,
            'mime' => $options['mime'] ?? $attachment->mime,
        ], fn ($options) => $this->attachWith(
            fn ($path) => [$path, ['as' => $this->as, 'mime' => $this->mime]],
            fn ($data) => [$data(), ['as' => $this->as, 'mime' => $this->mime]],
        ) === $attachment->attachWith(
            fn ($path) => [$path, $options],
            fn ($data) => [$data(), $options],
        ));
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
