<?php

declare(strict_types=1);

namespace Hypervel\Session;

use BadMethodCallException;
use Hypervel\Contracts\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;

class SymfonySessionDecorator implements SessionInterface
{
    /**
     * Create a new session decorator.
     */
    public function __construct(
        public readonly Session $store,
    ) {
    }

    public function start(): bool
    {
        return $this->store->start();
    }

    public function getId(): string
    {
        return $this->store->getId();
    }

    public function setId(string $id): void
    {
        $this->store->setId($id);
    }

    public function getName(): string
    {
        return $this->store->getName();
    }

    public function setName(string $name): void
    {
        $this->store->setName($name);
    }

    public function invalidate(?int $lifetime = null): bool
    {
        $this->store->invalidate();

        return true;
    }

    public function migrate(bool $destroy = false, ?int $lifetime = null): bool
    {
        $this->store->migrate($destroy);

        return true;
    }

    public function save(): void
    {
        $this->store->save();
    }

    public function has(string $name): bool
    {
        return $this->store->has($name);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->store->get($name, $default);
    }

    public function set(string $name, mixed $value): void
    {
        $this->store->put($name, $value);
    }

    public function all(): array
    {
        return $this->store->all();
    }

    public function replace(array $attributes): void
    {
        $this->store->replace($attributes);
    }

    public function remove(string $name): mixed
    {
        return $this->store->remove($name);
    }

    public function clear(): void
    {
        $this->store->flush();
    }

    public function isStarted(): bool
    {
        return $this->store->isStarted();
    }

    /**
     * @throws BadMethodCallException
     */
    public function registerBag(SessionBagInterface $bag): void
    {
        throw new BadMethodCallException('Method not implemented by Hypervel.');
    }

    /**
     * @throws BadMethodCallException
     */
    public function getBag(string $name): SessionBagInterface
    {
        throw new BadMethodCallException('Method not implemented by Hypervel.');
    }

    /**
     * @throws BadMethodCallException
     */
    public function getMetadataBag(): MetadataBag
    {
        throw new BadMethodCallException('Method not implemented by Hypervel.');
    }
}
