<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Tests\TestCase;
use Mockery as m;

class PaginatorLoadMorphTest extends TestCase
{
    public function testCollectionLoadMorphCanChainOnThePaginator()
    {
        $relations = [
            'App\User' => 'photos',
            'App\Company' => ['employees', 'calendars'],
        ];

        $items = m::mock(Collection::class);
        $items->shouldReceive('loadMorph')->once()->with('parentable', $relations);

        $p = (new class extends AbstractPaginator {
            public function render(?string $view = null, array $data = []): Htmlable
            {
                return new class implements Htmlable {
                    public function toHtml(): string
                    {
                        return '';
                    }
                };
            }

            public function hasMorePages(): bool
            {
                return false;
            }
        })->setCollection($items);

        $this->assertSame($p, $p->loadMorph('parentable', $relations));
    }
}
