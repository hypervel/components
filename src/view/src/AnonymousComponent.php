<?php

declare(strict_types=1);

namespace Hypervel\View;

class AnonymousComponent extends Component
{
    /**
     * Create a new anonymous component instance.
     */
    public function __construct(
        protected string $view,
        protected array $data = []
    ) {
    }

    /**
     * Get the view / view contents that represent the component.
     */
    public function render(): string
    {
        return $this->view;
    }

    /**
     * Get the data that should be supplied to the view.
     */
    public function data(): array
    {
        $this->attributes = $this->attributes ?: $this->newAttributeBag();

        return array_merge(
            ($this->data['attributes'] ?? null)?->getAttributes() ?: [],
            $this->attributes->getAttributes(),
            $this->data,
            ['attributes' => $this->attributes]
        );
    }
}
