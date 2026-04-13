<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class UnauthorizedException extends HttpException
{
}
