<?php

declare(strict_types=1);

use Symfony\Component\HttpKernel\Exception\HttpException;

throw new HttpException(403, 'http exception message');
