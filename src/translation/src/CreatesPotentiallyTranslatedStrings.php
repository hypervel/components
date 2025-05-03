<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Closure;
use Hypervel\Translation\Contracts\Translator;

trait CreatesPotentiallyTranslatedStrings
{
    /**
     * Create a pending potentially translated string.
     */
    protected function pendingPotentiallyTranslatedString(string $attribute, ?string $message): PotentiallyTranslatedString
    {
        $destructor = $message === null
            ? fn ($message) => $this->messages[] = $message
            : fn ($message) => $this->messages[$attribute] = $message;

        return new class($message ?? $attribute, $this->validator->getTranslator(), $destructor) extends PotentiallyTranslatedString {
            /**
             * The callback to call when the object destructs.
             */
            protected Closure $destructor;

            /**
             * Create a new pending potentially translated string.
             */
            public function __construct(string $message, Translator $translator, Closure $destructor)
            {
                parent::__construct($message, $translator);

                $this->destructor = $destructor;
            }

            /**
             * Handle the object's destruction.
             */
            public function __destruct()
            {
                ($this->destructor)($this->toString());
            }
        };
    }
}
