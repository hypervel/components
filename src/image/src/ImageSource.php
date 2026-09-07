<?php

declare(strict_types=1);

namespace Hypervel\Image;

use Closure;
use Hypervel\Coroutine\Locker;
use Throwable;

/**
 * Share one lazy source across every image derived from it.
 *
 * @internal
 */
class ImageSource
{
    protected const string LOCK_KEY_PREFIX = '__image.source.';

    protected ?string $contents = null;

    protected ?Closure $resolver = null;

    protected ?Throwable $exception = null;

    /**
     * Create a new image source instance.
     */
    public function __construct(Closure|string $contents)
    {
        if (is_string($contents)) {
            $this->contents = $contents;
        } else {
            $this->resolver = $contents;
        }
    }

    /**
     * Resolve the image source contents.
     */
    public function contents(): string
    {
        if ($this->contents !== null) {
            return $this->contents;
        }

        if ($this->exception !== null) {
            throw $this->exception;
        }

        // This object stays alive while its lock is held, so PHP cannot reuse its object ID for another source.
        $key = self::LOCK_KEY_PREFIX . spl_object_id($this);

        if (Locker::lock($key)) {
            try {
                /** @var Closure $resolver */
                $resolver = $this->resolver;
                $contents = $resolver();

                if (! is_string($contents)) {
                    throw new ImageException(sprintf(
                        'Image source resolver must return a string, %s returned.',
                        get_debug_type($contents),
                    ));
                }

                $this->contents = $contents;
            } catch (Throwable $exception) {
                $this->exception = $exception;
            } finally {
                $this->resolver = null;
                Locker::unlock($key);
            }
        }

        return $this->contents ?? throw $this->exception
            ?? new ImageException('Image source resolution was interrupted.');
    }
}
