<?php

declare(strict_types=1);

namespace Hypervel\Fortify;

use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QROptions;

use function sprintf;

class TwoFactorQrCodeSvgOutput extends QRMarkupSVG
{
    private const int SIZE = 192;

    /**
     * Return the SVG header.
     */
    protected function header(): string
    {
        /** @var QROptions $options */
        $options = $this->options;

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="%s">%s',
            self::SIZE,
            self::SIZE,
            $this->getViewBox(),
            $options->eol,
        );
    }
}
