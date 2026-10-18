<?php

declare(strict_types=1);

namespace Hypervel\Ai\Concerns;

use Hypervel\Container\Container;
use Hypervel\Contracts\Filesystem\Factory as FilesystemFactory;
use UnitEnum;

trait Storable
{
    /**
     * The cached copy of the file's random name.
     */
    protected ?string $randomStorageName = null;

    /**
     * Store the file on a filesystem disk.
     */
    public function store(string $path = '', UnitEnum|string|null $disk = null, array $options = []): string|false
    {
        return $this->storeAs($path, $this->randomStorageName(), $disk, $options);
    }

    /**
     * Store the file on a filesystem disk with public visibility.
     */
    public function storePublicly(string $path = '', UnitEnum|string|null $disk = null, array $options = []): string|false
    {
        $options['visibility'] = 'public';

        return $this->storeAs($path, $this->randomStorageName(), $disk, $options);
    }

    /**
     * Store the file on a filesystem disk with public visibility.
     */
    public function storePubliclyAs(string $path, ?string $name = null, UnitEnum|string|null $disk = null, array $options = []): string|false
    {
        if (is_null($name)) {
            [$path, $name] = ['', $path];
        }

        $options['visibility'] = 'public';

        return $this->storeAs($path, $name, $disk, $options);
    }

    /**
     * Store the file on a filesystem disk.
     */
    public function storeAs(string $path, ?string $name = null, UnitEnum|string|null $disk = null, array $options = []): string|false
    {
        if (is_null($name)) {
            [$path, $name] = ['', $path];
        }

        $result = Container::getInstance()->make(FilesystemFactory::class)->disk($disk)->put(
            $path = trim($path . '/' . $name, '/'),
            $this->content(),
            $options
        );

        return $result ? $path : false;
    }
}
