<?php

declare(strict_types=1);

namespace Hypervel\Fortify;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use RuntimeException;

class TwoFactorQrCodeRenderer
{
    private const string DARK = '#2d3748';

    private const string LIGHT = '#fff';

    /**
     * Render the QR code URL as SVG.
     */
    public function svg(string $url): string
    {
        $svg = (new QRCode(new QROptions([
            'addQuietzone' => false,
            'drawLightModules' => true,
            'eccLevel' => EccLevel::L,
            'moduleValues' => $this->moduleValues(),
            'outputBase64' => false,
            'outputInterface' => TwoFactorQrCodeSvgOutput::class,
            'svgUseFillAttributes' => true,
        ])))->render($url);

        if (! is_string($svg)) {
            throw new RuntimeException('Two-factor QR code renderer did not return SVG output.');
        }

        return trim($svg);
    }

    /**
     * Get the QR module color values.
     *
     * @return array<int, string>
     */
    private function moduleValues(): array
    {
        $values = [];

        foreach (QRMarkupSVG::DEFAULT_MODULE_VALUES as $module => $isDark) {
            $values[$module] = $isDark ? self::DARK : self::LIGHT;
        }

        return $values;
    }
}
