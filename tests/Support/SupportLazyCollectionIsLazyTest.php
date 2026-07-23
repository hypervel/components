<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Exception;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\ItemNotFoundException;
use Hypervel\Support\LazyCollection;
use Hypervel\Support\MultipleItemsFoundException;
use Hypervel\Support\Sleep;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;

class SupportLazyCollectionIsLazyTest extends TestCase
{
    use Concerns\CountsEnumerations;

    public function testMakeWithClosureIsLazy(): void
    {
        [$closure, $recorder] = $this->makeGeneratorFunctionWithRecorder();

        LazyCollection::make($closure);

        $this->assertEquals([], $recorder->all());
    }

    public function testMakeWithLazyCollectionIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            LazyCollection::make($collection);
        });
    }

    public function testEagerEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection = $collection->eager();

            $collection->count();
            $collection->all();
        });
    }

    public function testChunkIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->chunk(3);
        });

        $this->assertEnumerates(15, function ($collection) {
            $collection->chunk(5)->take(3)->all();
        });
    }

    public function testChunkWhileIsLazy(): void
    {
        $collection = LazyCollection::make(['A', 'A', 'B', 'B', 'C', 'C', 'C']);

        $this->assertDoesNotEnumerateCollection($collection, function ($collection) {
            $collection->chunkWhile(function ($current, $key, $chunk) {
                return $current === $chunk->last();
            });
        });

        $this->assertEnumeratesCollection($collection, 3, function ($collection) {
            $collection->chunkWhile(function ($current, $key, $chunk) {
                return $current === $chunk->last();
            })->first();
        });

        $this->assertEnumeratesCollectionOnce($collection, function ($collection) {
            $collection->chunkWhile(function ($current, $key, $chunk) {
                return $current === $chunk->last();
            })->all();
        });
    }

    public function testCollapseIsLazy(): void
    {
        $collection = LazyCollection::make([
            [1, 2, 3],
            [4, 5, 6],
            [7, 8, 9],
        ]);

        $this->assertDoesNotEnumerateCollection($collection, function ($collection) {
            $collection->collapse();
        });

        $this->assertEnumeratesCollection($collection, 1, function ($collection) {
            $collection->collapse()->take(3)->all();
        });
    }

    public function testCombineIsLazy(): void
    {
        $firstEnumerations = 0;
        $secondEnumerations = 0;
        $first = $this->countEnumerations($this->make([1, 2]), $firstEnumerations);
        $second = $this->countEnumerations($this->make([1, 2]), $secondEnumerations);

        $first->combine($second);

        $this->assertEnumerations(0, $firstEnumerations);
        $this->assertEnumerations(0, $secondEnumerations);

        $first->combine($second)->take(1)->all();

        $this->assertEnumerations(1, $firstEnumerations);
        $this->assertEnumerations(1, $secondEnumerations);
    }

    public function testConcatIsLazy(): void
    {
        $firstEnumerations = 0;
        $secondEnumerations = 0;
        $first = $this->countEnumerations($this->make([1, 2]), $firstEnumerations);
        $second = $this->countEnumerations($this->make([1, 2]), $secondEnumerations);

        $first->concat($second);

        $this->assertEnumerations(0, $firstEnumerations);
        $this->assertEnumerations(0, $secondEnumerations);

        $first->concat($second)->take(2)->all();

        $this->assertEnumerations(2, $firstEnumerations);
        $this->assertEnumerations(0, $secondEnumerations);

        $firstEnumerations = 0;
        $secondEnumerations = 0;

        $first->concat($second)->take(3)->all();

        $this->assertEnumerations(2, $firstEnumerations);
        $this->assertEnumerations(1, $secondEnumerations);
    }

    public function testMultiplyIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->multiply(2);
        });

        $this->assertEnumeratesCollectionOnce(
            $this->make([1, 2, 3]),
            function ($collection) {
                return $collection->multiply(3)->all();
            }
        );
    }

    public function testContainsIsLazy(): void
    {
        $this->assertEnumerates(5, function ($collection) {
            $collection->contains(5);
        });
    }

    public function testDoesntContainIsLazy(): void
    {
        $this->assertEnumerates(5, function ($collection) {
            $collection->doesntContain(5);
        });
    }

    public function testContainsStrictIsLazy(): void
    {
        $this->assertEnumerates(5, function ($collection) {
            $collection->containsStrict(5);
        });
    }

    public function testCountEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->count();
        });
    }

    public function testCountByIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->countBy();
        });

        $this->assertEnumeratesCollectionOnce(
            $this->make([1, 2, 2, 3]),
            function ($collection) {
                $collection->countBy()->all();
            }
        );
    }

    public function testCrossJoinIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->crossJoin([1]);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->crossJoin([1], [2])->all();
        });
    }

    public function testDiffIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->diff([1, 2]);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->diff([1, 2])->all();
        });
    }

    public function testDiffAssocIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->diffAssoc([1, 2]);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->diffAssoc([1, 2])->all();
        });
    }

    public function testDiffAssocUsingIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->diffAssocUsing([1, 2], 'strcasecmp');
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->diffAssocUsing([1, 2], 'strcasecmp')->all();
        });
    }

    public function testDiffKeysIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->diffKeys([1, 2]);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->diffKeys([1, 2])->all();
        });
    }

    public function testDiffKeysUsingIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->diffKeysUsing([1, 2], 'strcasecmp');
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->diffKeysUsing([1, 2], 'strcasecmp')->all();
        });
    }

    public function testDiffUsingIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->diffUsing([1, 2], 'strcasecmp');
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->diffUsing([1, 2], 'strcasecmp')->all();
        });
    }

    public function testDuplicatesIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->duplicates();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->duplicates()->all();
        });
    }

    public function testDuplicatesStrictIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->duplicatesStrict();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->duplicatesStrict()->all();
        });
    }

    public function testEachIsLazy(): void
    {
        $this->assertEnumerates(5, function ($collection) {
            $collection->each(function ($value, $key) {
                if ($value == 5) {
                    return false;
                }
            });
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->each(function ($value, $key) {
                // Silence is golden!
            });
        });

        $this->assertEnumerates(5, function ($collection) {
            foreach ($collection as $key => $value) {
                if ($value == 5) {
                    return false;
                }
            }
        });

        $this->assertEnumeratesOnce(function ($collection) {
            foreach ($collection as $key => $value) {
                // Silence is golden!
            }
        });
    }

    public function testEachSpreadIsLazy(): void
    {
        $data = $this->make([[1, 2], [3, 4], [5, 6], [7, 8]]);

        $this->assertEnumeratesCollection($data, 2, function ($collection) {
            $collection->eachSpread(function ($first, $second, $key) {
                if ($first == 3) {
                    return false;
                }
            });
        });

        $this->assertEnumeratesCollectionOnce($data, function ($collection) {
            $collection->eachSpread(function ($first, $second, $key) {
                // Silence is golden!
            });
        });
    }

    public function testEveryIsLazy(): void
    {
        $this->assertEnumerates(2, function ($collection) {
            $collection->every(function ($value) {
                return $value == 1;
            });
        });

        $data = $this->make([['a' => 1], ['a' => 2], ['a' => 3]]);

        $this->assertEnumeratesCollection($data, 2, function ($collection) {
            $collection->every('a', 1);
        });
    }

    public function testExceptIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->except([1, 2]);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->except([1, 2])->all();
        });
    }

    public function testFilterIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->filter(function ($value) {
                return $value > 5;
            });
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->filter(function ($value) {
                return $value > 5;
            })->all();
        });
    }

    public function testFirstIsLazy(): void
    {
        $this->assertEnumerates(1, function ($collection) {
            $collection->first();
        });

        $this->assertEnumerates(2, function ($collection) {
            $collection->first(function ($value) {
                return $value == 2;
            });
        });
    }

    public function testFirstWhereIsLazy(): void
    {
        $data = $this->make([['a' => 1], ['a' => 2], ['a' => 3]]);

        $this->assertEnumeratesCollection($data, 2, function ($collection) {
            $collection->firstWhere('a', 2);
        });
    }

    public function testFlatMapIsLazy(): void
    {
        $data = $this->make([1, 2, 3, 4, 5]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->flatMap(function ($values) {
                return array_sum($values);
            });
        });

        $this->assertEnumeratesCollection($data, 3, function ($collection) {
            $collection->flatMap(function ($value) {
                return range(1, $value);
            })->take(5)->all();
        });
    }

    public function testFlattenIsLazy(): void
    {
        $data = $this->make([1, [2, 3], [4, 5], [6, 7]]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->flatten();
        });

        $this->assertEnumeratesCollection($data, 2, function ($collection) {
            $collection->flatten()->take(3)->all();
        });
    }

    public function testFlipIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->flip();
        });

        $this->assertEnumerates(2, function ($collection) {
            $collection->flip()->take(2)->all();
        });
    }

    public function testForPageIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->forPage(2, 10);
        });

        $this->assertEnumerates(20, function ($collection) {
            $collection->forPage(2, 10)->all();
        });
    }

    public function testGetIsLazy(): void
    {
        $this->assertEnumerates(5, function ($collection) {
            $collection->get(4);
        });
    }

    public function testGroupByIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->groupBy(function ($value) {
                return $value % 5;
            });
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->groupBy(function ($value) {
                return $value % 5;
            })->all();
        });
    }

    public function testHasIsLazy(): void
    {
        $this->assertEnumerates(5, function ($collection) {
            $collection->has(4);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->has('non-existent');
        });
    }

    public function testHasAnyIsLazy(): void
    {
        $this->assertEnumerates(5, function ($collection) {
            $collection->hasAny(4);
        });

        $this->assertEnumerates(2, function ($collection) {
            $collection->hasAny([1, 4]);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->hasAny(['non', 'existent']);
        });
    }

    public function testImplodeEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->implode(', ');
        });
    }

    public function testIntersectIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->intersect([1, 2, 3]);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->intersect([1, 2, 3])->all();
        });
    }

    public function testIntersectUsingIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->intersectUsing([1, 2], 'strcasecmp');
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->intersectUsing([1, 2], 'strcasecmp')->all();
        });
    }

    public function testIntersectAssocIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->intersectAssoc([1, 2]);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->intersectAssoc([1, 2])->all();
        });
    }

    public function testIntersectAssocUsingIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->intersectAssocUsing([1, 2], 'strcasecmp');
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->intersectAssocUsing([1, 2], 'strcasecmp')->all();
        });
    }

    public function testIntersectByKeysIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->intersectByKeys([1, 2, 3]);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->intersectByKeys([1, 2, 3])->all();
        });
    }

    public function testIsEmptyIsLazy(): void
    {
        $this->assertEnumerates(1, function ($collection) {
            $collection->isEmpty();
        });
    }

    public function testIsNotEmptyIsLazy(): void
    {
        $this->assertEnumerates(1, function ($collection) {
            $collection->isNotEmpty();
        });
    }

    // REMOVED: Laziness test for Laravel's deprecated containsOneItem(); use hasSole().

    public function testHasSoleIsLazy(): void
    {
        $this->assertEnumerates(2, function ($collection) {
            $collection->hasSole();
        });

        $this->assertEnumerates(2, function ($collection) {
            $collection->hasSole(fn ($item) => $item <= 2);
        });

        $this->assertEnumeratesCollection(
            LazyCollection::times(10, fn ($i) => ['age' => $i]),
            2,
            fn ($collection) => $collection->hasSole('age', '<=', 2),
        );
    }

    public function testHasManyIsLazy(): void
    {
        $this->assertEnumerates(2, function ($collection) {
            $collection->hasMany();
        });

        $this->assertEnumerates(2, function ($collection) {
            $collection->hasMany(fn ($item) => $item <= 2);
        });

        $this->assertEnumeratesCollection(
            LazyCollection::times(10, fn ($i) => ['age' => $i]),
            2,
            fn ($collection) => $collection->hasMany('age', '<=', 2),
        );
    }

    public function testJoinIsLazy(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->join(', ', ' and ');
        });
    }

    public function testJsonSerializeEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->jsonSerialize();
        });
    }

    public function testKeyByIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->keyBy(function ($value) {
                return "key-of-{$value}";
            });
        });

        $this->assertEnumerates(2, function ($collection) {
            $collection->keyBy(function ($value) {
                return "key-of-{$value}";
            })->take(2)->all();
        });
    }

    public function testKeysIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->keys();
        });

        $this->assertEnumerates(2, function ($collection) {
            $collection->keys()->take(2)->all();
        });
    }

    public function testLastEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->last();
        });
    }

    public function testMapIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->map(function ($value) {
                return $value + 1;
            });
        });

        $this->assertEnumerates(2, function ($collection) {
            $collection->map(function ($value) {
                return $value + 1;
            })->take(2)->all();
        });
    }

    public function testMapIntoIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->mapInto(stdClass::class);
        });

        $this->assertEnumerates(2, function ($collection) {
            $collection->mapInto(stdClass::class)->take(2)->all();
        });
    }

    public function testMapSpreadIsLazy(): void
    {
        $data = $this->make([[1, 2], [3, 4], [5, 6], [7, 8]]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->mapSpread(function ($first, $second, $key) {
                return $first + $second + $key;
            });
        });

        $this->assertEnumeratesCollection($data, 2, function ($collection) {
            $collection->mapSpread(function ($first, $second, $key) {
                return $first + $second + $key;
            })->take(2)->all();
        });
    }

    public function testMapToDictionaryIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->mapToDictionary(function ($value, $key) {
                return [$value => $key];
            });
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->mapToDictionary(function ($value, $key) {
                return [$value => $key];
            })->all();
        });
    }

    public function testMapToGroupsIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->mapToGroups(function ($value, $key) {
                return [$value => $key];
            });
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->mapToGroups(function ($value, $key) {
                return [$value => $key];
            })->all();
        });
    }

    public function testMapWithKeysIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->mapWithKeys(function ($value, $key) {
                return [$value => $key];
            });
        });

        $this->assertEnumerates(2, function ($collection) {
            $collection->mapWithKeys(function ($value, $key) {
                return [$value => $key];
            })->take(2)->all();
        });
    }

    public function testMaxEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->max();
        });
    }

    public function testMedianEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->median();
        });
    }

    public function testAvgEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->avg();
        });
    }

    public function testMergeIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->merge([1, 2, 3]);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->merge([1, 2, 3])->all();
        });
    }

    public function testMergeRecursiveIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->mergeRecursive([1, 2, 3]);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->mergeRecursive([1, 2, 3])->all();
        });
    }

    public function testMinEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->min();
        });
    }

    public function testModeEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->mode();
        });
    }

    public function testNthIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->nth(5);
        });

        $this->assertEnumerates(11, function ($collection) {
            $collection->nth(5)->take(3)->all();
        });
    }

    public function testOnlyIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->only(5, 6, 7);
        });

        $this->assertEnumerates(8, function ($collection) {
            $collection->only(5, 6, 7)->all();
        });
    }

    public function testPadIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->pad(200, null);
            $collection->pad(-200, null);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->pad(20, null)->all();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->pad(-20, null)->all();
        });
    }

    public function testPartitionEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->partition(function ($value) {
                return $value > 10;
            });
        });
    }

    public function testPipeDoesNotEnumerate(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->pipe(function () {
                // Silence is golden!
            });
        });
    }

    public function testPluckIsLazy(): void
    {
        $data = $this->make([['a' => 1], ['a' => 2], ['a' => 3], ['a' => 4]]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->pluck('a');
        });

        $this->assertEnumeratesCollectionOnce($data, function ($collection) {
            $collection->pluck('a')->all();
        });
    }

    public function testRandomEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->random();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->random(5);
        });
    }

    public function testRangeIsLazy(): void
    {
        $data = LazyCollection::range(10, 1000);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->take(50);
        });

        $this->assertEnumeratesCollection($data, 5, function ($collection) {
            $collection->take(5)->all();
        });
    }

    public function testReduceIsLazy(): void
    {
        $this->assertEnumerates(1, function ($collection) {
            $this->rescue(function () use ($collection) {
                $collection->reduce(function ($total, $value) {
                    throw new Exception('Short-circuit');
                }, 0);
            });
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->reduce(function ($total, $value) {
                return $total + $value;
            }, 0);
        });
    }

    public function testReduceSpreadIsLazy(): void
    {
        $this->assertEnumerates(1, function ($collection) {
            $this->rescue(function () use ($collection) {
                $collection->reduceSpread(function ($one, $two, $value) {
                    throw new Exception('Short-circuit');
                }, 0, 0);
            });
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->reduceSpread(function ($total, $max, $value) {
                return [$total + $value, max($max, $value)];
            }, 0, 0);
        });
    }

    public function testRejectIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->reject(function ($value) {
                return $value % 2;
            });
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->reject(function ($value) {
                return $value % 2;
            })->all();
        });
    }

    public function testRememberIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->remember();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection = $collection->remember();

            $collection->all();
            $collection->all();
        });

        $this->assertEnumerates(5, function ($collection) {
            $collection = $collection->remember();

            $collection->take(5)->all();
            $collection->take(5)->all();
        });
    }

    public function testReplaceIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->replace([5 => 'a', 10 => 'b']);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->replace([5 => 'a', 10 => 'b'])->all();
        });
    }

    public function testReplaceRecursiveIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->replaceRecursive([5 => 'a', 10 => 'b']);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->replaceRecursive([5 => 'a', 10 => 'b'])->all();
        });
    }

    public function testReverseIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->reverse();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->reverse()->all();
        });
    }

    public function testSearchIsLazy(): void
    {
        $this->assertEnumerates(5, function ($collection) {
            $collection->search(5);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->search('missing');
        });
    }

    public function testShuffleIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->shuffle();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->shuffle()->all();
        });
    }

    public function testSlidingIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->sliding();
        });

        $this->assertEnumerates(2, function ($collection) {
            $collection->sliding()->take(1)->all();
        });

        $this->assertEnumerates(3, function ($collection) {
            $collection->sliding()->take(2)->all();
        });

        $this->assertEnumerates(13, function ($collection) {
            $collection->sliding(3, 5)->take(3)->all();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->sliding()->all();
        });
    }

    public function testSkipIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->skip(10);
        });

        $this->assertEnumerates(12, function ($collection) {
            $collection->skip(10)->take(2)->all();
        });
    }

    public function testSkipUntilIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->skipUntil(INF);
        });

        $this->assertEnumerates(10, function ($collection) {
            $collection->skipUntil(10)->first();
        });

        $this->assertEnumerates(10, function ($collection) {
            $collection->skipUntil(function ($item) {
                return $item === 10;
            })->first();
        });
    }

    public function testSkipWhileIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->skipWhile(1);
        });

        $this->assertEnumerates(2, function ($collection) {
            $collection->skipWhile(1)->first();
        });

        $this->assertEnumerates(10, function ($collection) {
            $collection->skipWhile(function ($item) {
                return $item < 10;
            })->first();
        });
    }

    public function testSliceIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->slice(2);
            $collection->slice(2, 2);
            $collection->slice(-2, 2);
        });

        $this->assertEnumerates(4, function ($collection) {
            $collection->slice(2)->take(2)->all();
        });

        $this->assertEnumerates(4, function ($collection) {
            $collection->slice(2, 2)->all();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->slice(-2, 2)->all();
        });
    }

    public function testFindFirstOrFailIsLazy(): void
    {
        $this->assertEnumerates(1, function ($collection) {
            $collection->firstOrFail();
        });

        $this->assertEnumerates(1, function ($collection) {
            $collection->firstOrFail(function ($item) {
                return $item === 1;
            });
        });

        $this->assertEnumerates(100, function ($collection) {
            try {
                $collection->firstOrFail(function ($item) {
                    return $item === 101;
                });
            } catch (ItemNotFoundException) {
            }
        });

        $this->assertEnumerates(2, function ($collection) {
            $collection->firstOrFail(function ($item) {
                return $item % 2 === 0;
            });
        });
    }

    public function testSomeIsLazy(): void
    {
        $this->assertEnumerates(5, function ($collection) {
            $collection->some(function ($value) {
                return $value == 5;
            });
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->some(function ($value) {
                return false;
            });
        });
    }

    public function testSoleIsLazy(): void
    {
        $this->assertEnumerates(2, function ($collection) {
            try {
                $collection->sole();
            } catch (MultipleItemsFoundException) {
            }
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->sole(function ($item) {
                return $item === 1;
            });
        });

        $this->assertEnumerates(4, function ($collection) {
            try {
                $collection->sole(function ($item) {
                    return $item % 2 === 0;
                });
            } catch (MultipleItemsFoundException) {
            }
        });
    }

    public function testSortIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->sort();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->sort()->all();
        });
    }

    public function testSortDescIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->sortDesc();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->sortDesc()->all();
        });
    }

    public function testSortByIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->sortBy(function ($value) {
                return $value;
            });
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->sortBy(function ($value) {
                return $value;
            })->all();
        });
    }

    public function testSortByDescIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->sortByDesc(function ($value) {
                return $value;
            });
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->sortByDesc(function ($value) {
                return $value;
            })->all();
        });
    }

    public function testSortKeysIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->sortKeys();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->sortKeys()->all();
        });
    }

    public function testSortKeysDescIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->sortKeysDesc();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->sortKeysDesc()->all();
        });
    }

    public function testSplitIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->split(4);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->split(4)->all();
        });
    }

    public function testSumEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->sum();
        });
    }

    public function testTakeIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->take(10);
        });

        $this->assertEnumerates(10, function ($collection) {
            $collection->take(10)->all();
        });
    }

    public function testTakeUntilIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->takeUntil(INF);
        });

        $this->assertEnumerates(10, function ($collection) {
            $collection->takeUntil(10)->all();
        });

        $this->assertEnumerates(10, function ($collection) {
            $collection->takeUntil(function ($item) {
                return $item === 10;
            })->all();
        });
    }

    public function testTakeUntilTimeoutIsLazy(): void
    {
        tap(m::mock(LazyCollection::class . '[now]')->times(100), function ($mock) {
            $this->assertDoesNotEnumerateCollection($mock, function ($mock) {
                $timeout = CarbonImmutable::now();

                $results = $mock
                    ->tap(function ($collection) use ($mock, $timeout) {
                        tap($collection)
                            ->mockery_init($mock->mockery_getContainer())
                            ->shouldAllowMockingProtectedMethods()
                            ->shouldReceive('now')
                            ->times(1)
                            ->andReturn(
                                $timeout->getTimestamp()
                            );
                    })
                    ->takeUntilTimeout($timeout)
                    ->all();
            });
        });

        tap(m::mock(LazyCollection::class . '[now]')->times(100), function ($mock) {
            $this->assertEnumeratesCollection($mock, 1, function ($mock) {
                $timeout = CarbonImmutable::now();

                $results = $mock
                    ->tap(function ($collection) use ($mock, $timeout) {
                        tap($collection)
                            ->mockery_init($mock->mockery_getContainer())
                            ->shouldAllowMockingProtectedMethods()
                            ->shouldReceive('now')
                            ->times(2)
                            ->andReturn(
                                $timeout->sub(1, 'minute')->getTimestamp(),
                                $timeout->getTimestamp()
                            );
                    })
                    ->takeUntilTimeout($timeout)
                    ->all();
            });
        });

        tap(m::mock(LazyCollection::class . '[now]')->times(100), function ($mock) {
            $this->assertEnumeratesCollectionOnce($mock, function ($mock) {
                $timeout = CarbonImmutable::now();

                $results = $mock
                    ->tap(function ($collection) use ($mock, $timeout) {
                        tap($collection)
                            ->mockery_init($mock->mockery_getContainer())
                            ->shouldAllowMockingProtectedMethods()
                            ->shouldReceive('now')
                            ->times(100)
                            ->andReturn(
                                $timeout->sub(1, 'minute')->getTimestamp()
                            );
                    })
                    ->takeUntilTimeout($timeout)
                    ->all();
            });
        });
    }

    public function testTakeWhileIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->takeWhile(0);
        });

        $this->assertEnumerates(1, function ($collection) {
            $collection->takeWhile(0)->all();
        });

        $this->assertEnumerates(10, function ($collection) {
            $collection->takeWhile(function ($item) {
                return $item < 10;
            })->all();
        });
    }

    public function testTapDoesNotEnumerate(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->tap(function ($collection) {
                // Silence is golden!
            });
        });
    }

    public function testTapEachIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->tapEach(function ($value) {
                // Silence is golden!
            });
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->tapEach(function ($value) {
                // Silence is golden!
            })->all();
        });
    }

    public function testThrottleIsLazy(): void
    {
        Sleep::fake();

        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->throttle(10);
        });

        $this->assertEnumerates(5, function ($collection) {
            $collection->throttle(10)->take(5)->all();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->throttle(10)->all();
        });

        Sleep::fake(false);
    }

    public function testTimesIsLazy(): void
    {
        $data = LazyCollection::times(INF);

        $this->assertEnumeratesCollection($data, 2, function ($collection) {
            $collection->take(2)->all();
        });
    }

    public function testToArrayEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->toArray();
        });
    }

    public function testToJsonEnumeratesOnce(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            $collection->toJson();
        });
    }

    public function testUnionIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->union([4, 5, 6]);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->union([4, 5, 6])->all();
        });
    }

    public function testUniqueIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->unique();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->unique()->all();
        });
    }

    public function testUniqueStrictIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->uniqueStrict();
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->uniqueStrict()->all();
        });
    }

    public function testUnlessDoesNotEnumerate(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->unless(true, function ($collection) {
                // Silence is golden!
            });

            $collection->unless(false, function ($collection) {
                // Silence is golden!
            });
        });
    }

    public function testUnlessEmptyIsLazy(): void
    {
        $this->assertEnumerates(1, function ($collection) {
            $collection->unlessEmpty(function ($collection) {
                // Silence is golden!
            });
        });
    }

    public function testUnlessNotEmptyIsLazy(): void
    {
        $this->assertEnumerates(1, function ($collection) {
            $collection->unlessNotEmpty(function ($collection) {
                // Silence is golden!
            });
        });
    }

    public function testUnwrapEnumeratesOne(): void
    {
        $this->assertEnumeratesOnce(function ($collection) {
            LazyCollection::unwrap($collection);
        });
    }

    public function testValuesIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->values();
        });

        $this->assertEnumerates(2, function ($collection) {
            $collection->values()->take(2)->all();
        });
    }

    public function testWhenDoesNotEnumerate(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->when(true, function ($collection) {
                // Silence is golden!
            });

            $collection->when(false, function ($collection) {
                // Silence is golden!
            });
        });
    }

    public function testWhenEmptyIsLazy(): void
    {
        $this->assertEnumerates(1, function ($collection) {
            $collection->whenEmpty(function ($collection) {
                // Silence is golden!
            });
        });
    }

    public function testWhenNotEmptyIsLazy(): void
    {
        $this->assertEnumerates(1, function ($collection) {
            $collection->whenNotEmpty(function ($collection) {
                // Silence is golden!
            });
        });
    }

    public function testWhereIsLazy(): void
    {
        $data = $this->make([['a' => 1], ['a' => 2], ['a' => 3], ['a' => 4]]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->where('a', '<', 3);
        });

        $this->assertEnumeratesCollection($data, 1, function ($collection) {
            $collection->where('a', '<', 3)->take(1)->all();
        });
    }

    public function testWhereBetweenIsLazy(): void
    {
        $data = $this->make([['a' => 1], ['a' => 2], ['a' => 3], ['a' => 4]]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->whereBetween('a', [2, 4]);
        });

        $this->assertEnumeratesCollection($data, 2, function ($collection) {
            $collection->whereBetween('a', [2, 4])->take(1)->all();
        });
    }

    public function testWhereInIsLazy(): void
    {
        $data = $this->make([['a' => 1], ['a' => 2], ['a' => 3], ['a' => 4]]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->whereIn('a', [2, 3]);
        });

        $this->assertEnumeratesCollection($data, 2, function ($collection) {
            $collection->whereIn('a', [2, 3])->take(1)->all();
        });
    }

    public function testWhereInstanceOfIsLazy(): void
    {
        $data = $this->make(['a' => 0])->concat(
            $this->make([['a' => 1], ['a' => 2], ['a' => 3], ['a' => 4]])
                ->mapInto(stdClass::class)
        );

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->whereInstanceOf(stdClass::class);
        });

        $this->assertEnumeratesCollection($data, 2, function ($collection) {
            $collection->whereInstanceOf(stdClass::class)->take(1)->all();
        });
    }

    public function testWhereInStrictIsLazy(): void
    {
        $data = $this->make([['a' => 1], ['a' => 2], ['a' => 3], ['a' => 4]]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->whereInStrict('a', ['2', 3]);
        });

        $this->assertEnumeratesCollection($data, 3, function ($collection) {
            $collection->whereInStrict('a', ['2', 3])->take(1)->all();
        });
    }

    public function testWhereNotBetweenIsLazy(): void
    {
        $data = $this->make([['a' => 1], ['a' => 2], ['a' => 3], ['a' => 4]]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->whereNotBetween('a', [1, 2]);
        });

        $this->assertEnumeratesCollection($data, 3, function ($collection) {
            $collection->whereNotBetween('a', [1, 2])->take(1)->all();
        });
    }

    public function testWhereNotInIsLazy(): void
    {
        $data = $this->make([['a' => 1], ['a' => 2], ['a' => 3], ['a' => 4]]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->whereNotIn('a', [1, 2]);
        });

        $this->assertEnumeratesCollection($data, 3, function ($collection) {
            $collection->whereNotIn('a', [1, 2])->take(1)->all();
        });
    }

    public function testWhereNotInStrictIsLazy(): void
    {
        $data = $this->make([['a' => 1], ['a' => 2], ['a' => 3], ['a' => 4]]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->whereNotInStrict('a', ['1', 2]);
        });

        $this->assertEnumeratesCollection($data, 2, function ($collection) {
            $collection->whereNotInStrict('a', [1, '2'])->take(1)->all();
        });
    }

    public function testWhereNotNullIsLazy(): void
    {
        $data = $this->make([['a' => 1], ['a' => null], ['a' => 2], ['a' => 3]]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->whereNotNull('a');
        });

        $this->assertEnumeratesCollectionOnce($data, function ($collection) {
            $collection->whereNotNull('a')->all();
        });

        $data = $this->make([1, null, 2, null, 3]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->whereNotNull();
        });

        $this->assertEnumeratesCollectionOnce($data, function ($collection) {
            $collection->whereNotNull()->all();
        });
    }

    public function testWhereNullIsLazy(): void
    {
        $data = $this->make([['a' => 1], ['a' => null], ['a' => 2], ['a' => 3]]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->whereNull('a');
        });

        $this->assertEnumeratesCollectionOnce($data, function ($collection) {
            $collection->whereNull('a')->all();
        });

        $data = $this->make([1, null, 2, null, 3]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->whereNull();
        });

        $this->assertEnumeratesCollectionOnce($data, function ($collection) {
            $collection->whereNull()->all();
        });
    }

    public function testWhereStrictIsLazy(): void
    {
        $data = $this->make([['a' => 1], ['a' => 2], ['a' => 3], ['a' => 4]]);

        $this->assertDoesNotEnumerateCollection($data, function ($collection) {
            $collection->whereStrict('a', 2);
        });

        $this->assertEnumeratesCollection($data, 2, function ($collection) {
            $collection->whereStrict('a', 2)->take(1)->all();
        });
    }

    public function testWithHeartbeatIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            $collection->withHeartbeat(1, function () {
                // Heartbeat callback
            });
        });

        $this->assertEnumeratesOnce(function ($collection) {
            $collection->withHeartbeat(1, function () {
                // Heartbeat callback
            })->all();
        });
    }

    public function testWrapIsLazy(): void
    {
        $this->assertDoesNotEnumerate(function ($collection) {
            LazyCollection::wrap($collection);
        });

        $this->assertEnumeratesOnce(function ($collection) {
            LazyCollection::wrap($collection)->all();
        });
    }

    public function testZipIsLazy(): void
    {
        $firstEnumerations = 0;
        $secondEnumerations = 0;
        $first = $this->countEnumerations($this->make([1, 2]), $firstEnumerations);
        $second = $this->countEnumerations($this->make([1, 2]), $secondEnumerations);

        $first->zip($second);

        $this->assertEnumerations(0, $firstEnumerations);
        $this->assertEnumerations(0, $secondEnumerations);

        $first->zip($second)->take(1)->all();

        $this->assertEnumerations(1, $firstEnumerations);
        $this->assertEnumerations(1, $secondEnumerations);
    }

    protected function make($source): LazyCollection
    {
        return new LazyCollection($source);
    }

    protected function rescue($callback): void
    {
        try {
            $callback();
        } catch (Exception) {
            // Silence is golden
        }
    }
}
