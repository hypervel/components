<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Support\HtmlString;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class PaginatorLoadMorphTest extends TestCase
{
    use VerifiesDoubles;

    public function testCollectionLoadMorphCanChainOnThePaginator(): void
    {
        $relations = [
            'App\User' => 'photos',
            'App\Company' => ['employees', 'calendars'],
        ];

        $items = Double::for(Collection::class);
        $items->expects('loadMorph')->with('parentable', $relations);

        $p = (new class extends AbstractPaginator {
            /**
             * Render an empty pagination view.
             */
            public function render(?string $view = null, array $data = []): Htmlable
            {
                return new HtmlString('');
            }

            /**
             * Determine whether more pages are available.
             */
            public function hasMorePages(): bool
            {
                return false;
            }
        })->setCollection($items);

        $this->assertSame($p, $p->loadMorph('parentable', $relations));
    }
}
