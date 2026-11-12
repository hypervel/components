<?php

declare(strict_types=1);

namespace RuntimeInputs;

use Hypervel\Contracts\Foundation\Application;
use RuntimeInputs\Greeters\EnglishGreeter;
use RuntimeInputs\Greeters\FrenchGreeter;
use RuntimeInputs\Greeters\Greeter;

class Registrar
{
    /**
     * Bind the greeter the configuration selects.
     */
    public static function register(Application $app): void
    {
        $app->bind(Greeter::class, $app->make('config')->get('fixture.greeter') === 'french' ? FrenchGreeter::class : EnglishGreeter::class);
    }
}
