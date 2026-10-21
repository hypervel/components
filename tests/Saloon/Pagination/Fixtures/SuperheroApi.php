<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination\Fixtures;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Query;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;

// Upstream's pagination tests call the superhero endpoints of the public Saloon test API. This answers them from the
// received query so the tests need no network access.
class SuperheroApi
{
    /**
     * The base URL of the test API.
     */
    public const string BASE_URL = 'https://tests.saloon.dev/api';

    /**
     * The superhero names in ID order.
     */
    protected const array SUPERHEROES = [
        'Batman', 'Superman', 'Flash', 'Green Lantern', 'Green Arrow', 'Wonder Woman', 'Martian Manhunter',
        'Robin/Nightwing', 'Blue Beetle', 'Black Canary', 'Spider Man', 'Captain America', 'Iron Man', 'Thor', 'Hulk',
        'Wolverine', 'Daredevil', 'Hawkeye', 'Cyclops', 'Silver Surfer',
    ];

    /**
     * Fake the paged, cursor and limit-offset superhero endpoints.
     */
    public static function fake(): void
    {
        Http::fake(static function (Request $request): PromiseInterface {
            $uri = $request->toPsrRequest()->getUri();
            $query = Query::parse($uri->getQuery());
            $total = count(self::SUPERHEROES);
            $page = (int) ($query['page'] ?? 1);
            $perPage = (int) ($query['per_page'] ?? 5);
            $cursor = (int) ($query['cursor'] ?? 0);

            return match ($uri->getPath()) {
                '/api/superheroes/per-page' => Factory::response([
                    'data' => self::superheroes(($page - 1) * $perPage, $perPage),
                    'total' => $total,
                    'per_page' => $perPage,
                    'next_page_url' => $page * $perPage < $total
                        ? self::BASE_URL . '/superheroes/per-page?page=' . ($page + 1)
                        : null,
                ]),
                '/api/superheroes/cursor' => Factory::response([
                    'data' => self::superheroes($cursor, $perPage),
                    'next_page_url' => $cursor + $perPage < $total
                        ? self::BASE_URL . '/superheroes/cursor?cursor=' . ($cursor + $perPage)
                        : null,
                ]),
                '/api/superheroes/limit-offset' => Factory::response([
                    'data' => self::superheroes((int) ($query['offset'] ?? 0), (int) ($query['limit'] ?? 5)),
                    'total' => $total,
                ]),
                default => Factory::response(status: 404),
            };
        });
    }

    /**
     * Get one slice of superheroes.
     *
     * @return list<array{id: int, superhero: string}>
     */
    protected static function superheroes(int $offset, int $limit): array
    {
        $superheroes = [];

        foreach (array_slice(self::SUPERHEROES, $offset, $limit) as $index => $name) {
            $superheroes[] = ['id' => $offset + $index + 1, 'superhero' => $name];
        }

        return $superheroes;
    }
}
