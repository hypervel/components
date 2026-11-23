<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Validation\Data;

use Hypervel\Data\Attributes\Validation\Rule;
use Hypervel\Data\Data;

class AttributedData extends Data
{
    #[Rule('required|email')]
    public string $email;

    public function __construct(
        #[Rule('required|string')]
        public string $name,
        #[Rule('required', 'max:5|min:1')]
        public string $code,
        #[Rule(['required|string'])]
        public string $title,
        #[Rule('regex:/^(a|b)$/')]
        public string $pattern,
        #[Rule('required', 'string')]
        public string $plain,
    ) {
    }
}
