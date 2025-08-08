<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Model\Factory as BaseFactory;
use Symfony\Component\Finder\Finder;

class LegacyFactory extends BaseFactory
{
    /**
     * Define a class with a given set of attributes.
     *
     * @param string $class
     * @return $this
     */
    public function define($class, callable $attributes, ?string $name = null)
    {
        $name = $name ?: $this->getConnection();

        return parent::define($class, $attributes, $name);
    }

    /**
     * Define a callback to run after making a model.
     *
     * @param string $class
     * @return $this
     */
    public function afterMaking($class, callable $callback, ?string $name = null)
    {
        $name = $name ?: $this->getConnection();

        return parent::afterMaking($class, $callback, $name);
    }

    /**
     * Define a callback to run after creating a model.
     *
     * @param string $class
     * @return $this
     */
    public function afterCreating($class, callable $callback, ?string $name = null)
    {
        $name = $name ?: $this->getConnection();

        return parent::afterCreating($class, $callback, $name);
    }

    /**
     * Get the raw attribute array for a given model.
     *
     * @param string $class
     */
    public function raw($class, array $attributes = [], ?string $name = null): array
    {
        $name = $name ?: $this->getConnection();

        return parent::raw($class, $attributes, $name);
    }

    /**
     * Create a builder for the given model.
     *
     * @param string $class
     * @return \Hyperf\Database\Model\FactoryBuilder
     */
    public function of($class, ?string $name = null)
    {
        $name = $name ?: $this->getConnection();

        return parent::of($class, $name)
            ->connection($name);
    }

    /**
     * Load factories from path.
     *
     * @return $this
     */
    public function load(string $path)
    {
        $factory = $this;

        if (is_dir($path)) {
            foreach (Finder::create()->files()->name('*.php')->in($path) as $file) {
                $realPath = $file->getRealPath();
                if ($this->isClass($realPath)) {
                    continue;
                }

                require $realPath;
            }
        }

        return $factory;
    }

    protected function isClass(string $file): bool
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return false;
        }

        return preg_match('/^\s*class\s+(\w+)/m', $contents) === 1;
    }

    protected function getConnection(): string
    {
        return ApplicationContext::getContainer()
            ->get(ConfigInterface::class)
            ->get('database.default');
    }
}
