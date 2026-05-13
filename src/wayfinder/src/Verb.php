<?php

declare(strict_types=1);

namespace Hypervel\Wayfinder;

class Verb
{
    public string $formSafe;

    /**
     * Create a new Verb instance.
     */
    public function __construct(public string $actual)
    {
        $this->actual = strtolower($actual);

        $this->formSafe = in_array(strtolower($actual), ['get', 'head', 'options'], true) ? 'get' : 'post';
    }
}
