<?php

declare(strict_types=1);

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hypervel\Support;

use Exception;
use finfo;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Guesses the MIME type using the PECL extension FileInfo.
 */
class FileinfoMimeTypeGuesser
{
    /**
     * @var array<string, finfo>
     */
    private static $finfoCache = [];

    /**
     * @param null|string $magicFile A magic file to use with the finfo instance
     *
     * @see http://www.php.net/manual/en/function.finfo-open.php
     */
    public function __construct(
        private ?string $magicFile = null,
    ) {
    }

    public function isGuesserSupported(): bool
    {
        return function_exists('finfo_open');
    }

    public function guessMimeType(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(sprintf('The "%s" file does not exist or is not readable.', $path));
        }

        if (! $this->isGuesserSupported()) {
            throw new LogicException(sprintf('The "%s" guesser is not supported.', __CLASS__));
        }

        try {
            $finfo = self::$finfoCache[$this->magicFile] ??= new finfo(FILEINFO_MIME_TYPE, $this->magicFile);
        } catch (Exception $e) {
            throw new RuntimeException($e->getMessage());
        }
        $mimeType = $finfo->file($path) ?: null;

        if ($mimeType && 0 === (strlen($mimeType) % 2)) {
            $mimeStart = substr($mimeType, 0, strlen($mimeType) >> 1);
            $mimeType = $mimeStart . $mimeStart === $mimeType ? $mimeStart : $mimeType;
        }

        return $mimeType;
    }
}
