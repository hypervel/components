<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\ClassificationGateway;
use Hypervel\Support\Attributes\BootOrTestsOnly;

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
     * Changing a shared provider's gateway affects subsequent operations.
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function useClassificationGateway(ClassificationGateway $gateway): self
    {
        $this->classificationGateway = $gateway;

        return $this;
    }
}
