<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers;

use Hypervel\Ai\Contracts\Gateway\ClassificationGateway;
use Hypervel\Ai\Contracts\Providers\ClassificationProvider;
use Hypervel\Ai\Gateway\TypeSafeGateway;
use Hypervel\Ai\Providers\Concerns\Classifies;
use Hypervel\Ai\Providers\Concerns\HasClassificationGateway;
use Hypervel\Contracts\Events\Dispatcher;

class TypeSafeProvider extends Provider implements ClassificationProvider
{
    use Classifies;
    use HasClassificationGateway;

    /**
     * Create a TypeSafe provider instance.
     */
    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {
    }

    /**
     * Get the name of the default classification model.
     */
    public function defaultClassificationModel(): string
    {
        return $this->config['models']['classification']['default'] ?? 'jev-latest';
    }

    /**
     * Get the provider's classification gateway.
     */
    public function classificationGateway(): ClassificationGateway
    {
        return $this->classificationGateway ??= new TypeSafeGateway;
    }
}
