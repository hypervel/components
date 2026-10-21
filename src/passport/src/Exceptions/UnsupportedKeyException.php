<?php

declare(strict_types=1);

namespace Hypervel\Passport\Exceptions;

use UnexpectedValueException;

/**
 * Identify public key requirements that may be disclosed in protocol errors.
 */
class UnsupportedKeyException extends UnexpectedValueException
{
}
