<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Closure;
use Hyperf\Context\ApplicationContext;
use Hyperf\Macroable\Macroable;
use Hypervel\Filesystem\Contracts\Factory as FilesystemFactory;
use Hypervel\Filesystem\Contracts\Filesystem;
use Hypervel\Notifications\Messages\MailMessage;
use RuntimeException;

use function Hyperf\Support\with;

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
     * Create a mail attachment from in-memory data.
     */
    public static function fromData(Closure $data, ?string $name = null): static
    {
        return (new static(
            fn ($attachment, $pathStrategy, $dataStrategy) => $dataStrategy($data, $attachment)
        ))->as($name);
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
            $attachment
                ->as($attachment->as ?? basename($path))
                ->withMime($attachment->mime ?? static::getStorageDisk($disk)->mimeType($path)); // @phpstan-ignore-line

            return $dataStrategy(fn () => static::getStorageDisk($disk)->get($path), $attachment);
        });
    }

    protected static function getStorageDisk(?string $disk): Filesystem
    {
        return ApplicationContext::getContainer()->get(
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
     *
     * @return $this
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
     * @phpstan-ignore-next-line
     */
    public function attachTo(Mailable|MailMessage|Message $mail, array $options = []): mixed
    {
        /** @var Mailable $mail */
        return $this->attachWith(
            fn ($path) => $mail->attach($path, [
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
}
