<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Hypervel\Fortify\TwoFactorQrCodeRenderer;
use Hypervel\Tests\TestCase;

class TwoFactorQrCodeRendererTest extends TestCase
{
    private const string URL = 'otpauth://totp/Hypervel:taylor%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Hypervel&algorithm=SHA1&digits=6&period=30';

    public function testReturnsRawSvgWithoutXmlDeclaration(): void
    {
        $svg = (new TwoFactorQrCodeRenderer)->svg(self::URL);

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);
    }

    public function testSvgHasFixedWidthAndHeight(): void
    {
        $svg = $this->document((new TwoFactorQrCodeRenderer)->svg(self::URL))->documentElement;

        $this->assertInstanceOf(DOMElement::class, $svg);
        $this->assertSame('192', $svg->getAttribute('width'));
        $this->assertSame('192', $svg->getAttribute('height'));
    }

    public function testSvgIsWellFormedXml(): void
    {
        $document = $this->document((new TwoFactorQrCodeRenderer)->svg(self::URL));

        $this->assertSame('svg', $document->documentElement?->localName);
    }

    public function testSvgViewBoxHasNoQuietZonePadding(): void
    {
        $svg = $this->document((new TwoFactorQrCodeRenderer)->svg(self::URL))->documentElement;
        // The otpauth URL contains lowercase and symbols, so chillerlan renders it in byte mode.
        $matrix = (new QRCode(new QROptions([
            'addQuietzone' => false,
            'eccLevel' => EccLevel::L,
        ])))->addByteSegment(self::URL)->getQRMatrix();

        $this->assertInstanceOf(DOMElement::class, $svg);
        $this->assertSame(
            sprintf('0 0 %d %d', $matrix->moduleCount, $matrix->moduleCount),
            $svg->getAttribute('viewBox'),
        );
    }

    public function testRenderedDarkModulesUseFortifyDarkColor(): void
    {
        $document = $this->document((new TwoFactorQrCodeRenderer)->svg(self::URL));
        $paths = (new DOMXPath($document))->query('//*[local-name() = "path" and contains(concat(" ", normalize-space(@class), " "), " dark ")]');

        $this->assertNotFalse($paths);
        $this->assertGreaterThan(0, $paths->length);

        foreach ($paths as $path) {
            $this->assertInstanceOf(DOMElement::class, $path);
            $this->assertSame('#2d3748', $path->getAttribute('fill'));
        }
    }

    public function testSvgDoesNotContainDefaultBlack(): void
    {
        $svg = (new TwoFactorQrCodeRenderer)->svg(self::URL);

        $this->assertStringNotContainsString('#000', $svg);
        $this->assertStringNotContainsString('black', $svg);
    }

    public function testRenderingTwiceDoesNotAccumulateSegments(): void
    {
        $renderer = new TwoFactorQrCodeRenderer;

        $this->assertSame(
            $renderer->svg(self::URL),
            $renderer->svg(self::URL),
        );
    }

    public function testDifferentUrlsRenderAsValidSvgs(): void
    {
        $renderer = new TwoFactorQrCodeRenderer;
        $firstDocument = $this->document($renderer->svg(self::URL));
        $secondDocument = $this->document($renderer->svg(str_replace('taylor', 'abigail', self::URL)));

        $this->assertSame('svg', $firstDocument->documentElement?->localName);
        $this->assertSame('svg', $secondDocument->documentElement?->localName);
    }

    /**
     * Parse the SVG document.
     */
    private function document(string $svg): DOMDocument
    {
        $document = new DOMDocument;

        $this->assertTrue($document->loadXML($svg));

        return $document;
    }
}
