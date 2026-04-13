<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Testing;

use Hypervel\Foundation\Application;
use Hypervel\Foundation\Testing\Concerns\MakesHttpRequests;
use Hypervel\Http\Response;
use Hypervel\Inertia\Support\Header;
use Hypervel\Testing\TestResponse;

class ReloadRequest
{
    use MakesHttpRequests;

    /**
     * Create a new Inertia reload request instance.
     */
    public function __construct(
        protected string $url,
        protected string $component,
        protected string $version,
        protected ?string $only = null,
        protected ?string $except = null,
        protected ?Application $app = null
    ) {
        $this->app ??= app();
    }

    /**
     * Execute the reload request with appropriate Inertia headers.
     *
     * @return TestResponse<Response>
     */
    public function __invoke(): TestResponse
    {
        $headers = [Header::VERSION => $this->version];

        if (! blank($this->only)) {
            $headers[Header::PARTIAL_COMPONENT] = $this->component;
            $headers[Header::PARTIAL_ONLY] = $this->only;
        }

        if (! blank($this->except)) {
            $headers[Header::PARTIAL_COMPONENT] = $this->component;
            $headers[Header::PARTIAL_EXCEPT] = $this->except;
        }

        return $this->get($this->url, $headers);
    }
}
