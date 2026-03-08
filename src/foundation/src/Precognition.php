<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Validation\Validator;

class Precognition
{
    /**
     * Get the "after" validation hook that can be used for precognition requests.
     */
    public static function afterValidationHook(Request $request): Closure
    {
        return function (Validator $validator) use ($request) {
            if ($validator->messages()->isEmpty() && $request->headers->has('Precognition-Validate-Only')) {
                abort(204, headers: ['Precognition-Success' => 'true']);
            }
        };
    }
}
