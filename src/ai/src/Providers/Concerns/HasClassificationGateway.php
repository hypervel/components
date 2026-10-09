<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\ClassificationGateway;

trait HasClassificationGateway
{
    protected ClassificationGateway $classificationGateway;

    /**
     * Get the provider's classification gateway.
     */
    public function classificationGateway(): ClassificationGateway
    {
        return $this->classificationGateway;
    }

    /**
     * Set the provider's classification gateway.
     *
     * Boot or tests only for configured providers, which are shared across requests.
     */
    public function useClassificationGateway(ClassificationGateway $gateway): self
    {
        $this->classificationGateway = $gateway;

        return $this;
    }
}
