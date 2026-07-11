<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Closure;
use Hypervel\Contracts\Filesystem\Cloud;
use Hypervel\Contracts\Filesystem\Filesystem as FilesystemContract;
use Hypervel\Filesystem\Concerns\InteractsWithPooledFilesystem;
use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolErrorReporter;
use Hypervel\ObjectPool\PoolProxy;
use RuntimeException;
use Throwable;

class FilesystemPoolProxy extends PoolProxy implements Cloud
{
    use InteractsWithPooledFilesystem;

    /**
     * Create a whole-driver pooled filesystem proxy.
     */
    public function __construct(
        PoolDefinition $definition,
        Closure $resolver,
        Factory $pools,
        protected array $config,
        ?Closure $releaseCallback = null,
    ) {
        parent::__construct($definition, $resolver, $pools, $releaseCallback);
    }

    /**
     * Apply every proxy-held callback slot to a borrowed adapter.
     */
    protected function configureBorrowed(object $object): void
    {
        if (! $object instanceof FilesystemContract) {
            throw new RuntimeException(
                'Pooled filesystem resolvers must return an instance of ' . FilesystemContract::class . '.',
            );
        }

        if (! $object instanceof FilesystemAdapter) {
            if ($this->serveCallback === null
                && $this->temporaryUrlCallback === null
                && $this->temporaryUploadUrlCallback === null
            ) {
                return;
            }

            throw new RuntimeException(
                'Pooled filesystem driver [' . $object::class . '] cannot receive serve or temporary URL callbacks. '
                . 'These callbacks require a driver based on ' . FilesystemAdapter::class . '.',
            );
        }

        $object->serveUsing($this->serveCallback);
        $object->buildTemporaryUrlsUsing($this->temporaryUrlCallback);
        $object->buildTemporaryUploadUrlsUsing($this->temporaryUploadUrlCallback);
    }

    /**
     * Open a stream that owns its whole-driver lease until closure.
     *
     * @param Closure(FilesystemContract): mixed $operation
     * @return null|resource
     */
    protected function leasedStream(Closure $operation): mixed
    {
        $lease = $this->lease();

        try {
            $filesystem = $lease->get();
            $stream = $operation($filesystem);
        } catch (Throwable $operationException) {
            try {
                $lease->release();
            } catch (Throwable $finalizationException) {
                PoolErrorReporter::report($finalizationException);
            }

            throw $operationException;
        }

        if (! is_resource($stream)) {
            $lease->release();

            return $stream;
        }

        return LeasedStream::wrap($stream, $lease);
    }

    /**
     * Run a callback with an accessor result from a borrowed driver.
     */
    protected function withBorrowedAccessor(string $accessor, Closure $callback): mixed
    {
        $lease = $this->lease();

        try {
            $filesystem = $lease->get();

            if (! method_exists($filesystem, $accessor)) {
                throw new RuntimeException(
                    'Pooled filesystem driver [' . $filesystem::class . "] does not support [{$accessor}] access.",
                );
            }

            $result = $callback($filesystem->{$accessor}());
        } catch (Throwable $operationException) {
            try {
                $lease->release();
            } catch (Throwable $finalizationException) {
                PoolErrorReporter::report($finalizationException);
            }

            throw $operationException;
        }

        $lease->release();

        return $result;
    }
}
