<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Mercure;

use Closure;
use Hypervel\Contracts\ObjectPool\Factory;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolProxy;
use Symfony\Component\Mercure\Hub as SymfonyHub;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\RemoteHubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Keep a publishing client's network state exclusive to its current coroutine.
 */
class Hub extends PoolProxy implements RemoteHubInterface
{
    protected string $cookieName;

    /**
     * Create a hub backed by reusable HTTP clients.
     *
     * @param Closure(): HttpClientInterface $createClient
     */
    public function __construct(
        PoolDefinition $definition,
        Closure $createClient,
        Factory $pools,
        protected UrlGenerator $urls,
        protected string $url,
        protected TokenProviderInterface $jwtProvider,
        protected ?TokenFactoryInterface $jwtFactory = null,
        protected ?string $publicUrl = null,
        ?string $cookieName = null,
    ) {
        parent::__construct($definition, $createClient, $pools);

        $this->cookieName = $cookieName ?? ProtocolVersion::V1->getDefaultCookieName();
    }

    /**
     * Get the publishing URL for the current request or application origin.
     */
    public function getUrl(): string
    {
        return $this->urls->to($this->url);
    }

    /**
     * Get the public URL for the current request or application origin.
     */
    public function getPublicUrl(): string
    {
        return $this->urls->to($this->publicUrl ?? $this->url);
    }

    /**
     * Get the publisher token provider.
     */
    public function getProvider(): TokenProviderInterface
    {
        return $this->jwtProvider;
    }

    /**
     * Get the subscriber token factory.
     */
    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->jwtFactory;
    }

    /**
     * Get the Mercure protocol version.
     */
    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::V1;
    }

    /**
     * Get the subscriber authorization cookie name.
     */
    public function getCookieName(): string
    {
        return $this->cookieName;
    }

    /**
     * Publish an update while holding its HTTP client through response consumption.
     */
    public function publish(Update $update): string
    {
        $url = $this->getUrl();
        $publicUrl = $this->getPublicUrl();
        $lease = $this->lease();

        try {
            /** @var HttpClientInterface $client */
            $client = $lease->get();

            $result = (new SymfonyHub(
                $url,
                $this->jwtProvider,
                $this->jwtFactory,
                $publicUrl,
                $client,
                $this->cookieName,
                $this->getProtocolVersion(),
            ))->publish($update);
        } catch (Throwable $exception) {
            $lease->discardAfterFailure($exception);
        }

        $lease->release();

        return $result;
    }
}
