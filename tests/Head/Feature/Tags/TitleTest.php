<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Tags;

use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadBuilder;
use Hypervel\Tests\Head\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

class TitleTest extends TestCase
{
    public function testRendersTheDefaultTitleWithoutApplyingItsInheritedSuffix(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->title('Acme', suffix: ' - Acme'));

        $this->assertStringContainsString('<title>Acme</title>', Head::toHtml());
    }

    public function testCanRenderAnExactTitleWithoutTheConfiguredSuffix(): void
    {
        Head::defaults(fn (HeadBuilder $head): HeadBuilder => $head->title('Acme', suffix: ' - Acme'));

        Head::title('Checkout', exact: true);

        $this->assertStringContainsString('<title>Checkout</title>', Head::toHtml());
    }

    #[TestWith(['0', ['<title>0</title>', '<meta name="description" content="0">']])]
    #[TestWith(['', []])]
    public function testDistinguishesZeroFromEmptyTitleAndDescription(string $value, array $elements): void
    {
        Head::title($value)->description($value);

        $this->assertSame($elements, Head::toElements());
        $data = Head::toArray();
        $this->assertSame($value, $data['title']);
        $this->assertSame($value, $data['description']);
    }
}
