<?php

declare(strict_types=1);

namespace Hypervel\Support;

use RuntimeException;

class Lottery
{
    /**
     * The number of expected wins.
     */
    protected int|float $chances;

    /**
     * The number of potential opportunities to win.
     */
    protected ?int $outOf;

    /**
     * The winning callback.
     *
     * @var null|callable
     */
    protected mixed $winner = null;

    /**
     * The losing callback.
     *
     * @var null|callable
     */
    protected mixed $loser = null;

    /**
     * The factory that should be used to generate results.
     *
     * @var null|callable
     */
    protected static mixed $resultFactory = null;

    /**
     * Create a new Lottery instance.
     *
     * @param null|int<1, max> $outOf
     *
     * @throws RuntimeException
     */
    public function __construct(int|float $chances, ?int $outOf = null)
    {
        if ($outOf === null && is_float($chances) && $chances > 1) {
            throw new RuntimeException('Float must not be greater than 1.');
        }

        if ($outOf !== null && $outOf < 1) { /* @phpstan-ignore smaller.alwaysFalse, booleanAnd.alwaysFalse */
            throw new RuntimeException('Lottery "out of" value must be greater than or equal to 1.');
        }

        $this->chances = $chances;

        $this->outOf = $outOf;
    }

    /**
     * Create a new Lottery instance.
     */
    public static function odds(int|float $chances, ?int $outOf = null): static
    {
        return new static($chances, $outOf);
    }

    /**
     * Set the winner callback.
     */
    public function winner(callable $callback): static
    {
        $this->winner = $callback;

        return $this;
    }

    /**
     * Set the loser callback.
     */
    public function loser(callable $callback): static
    {
        $this->loser = $callback;

        return $this;
    }

    /**
     * Run the lottery.
     */
    public function __invoke(mixed ...$args): mixed
    {
        return $this->runCallback(...$args);
    }

    /**
     * Run the lottery.
     */
    public function choose(?int $times = null): mixed
    {
        if ($times === null) {
            return $this->runCallback();
        }

        $results = [];

        for ($i = 0; $i < $times; ++$i) {
            $results[] = $this->runCallback();
        }

        return $results;
    }

    /**
     * Run the winner or loser callback, randomly.
     */
    protected function runCallback(mixed ...$args): mixed
    {
        return $this->wins()
            ? ($this->winner ?? fn () => true)(...$args)
            : ($this->loser ?? fn () => false)(...$args);
    }

    /**
     * Determine if the lottery "wins" or "loses".
     */
    protected function wins(): bool
    {
        return static::resultFactory()($this->chances, $this->outOf);
    }

    /**
     * The factory that determines the lottery result.
     */
    protected static function resultFactory(): callable
    {
        return static::$resultFactory ?? fn ($chances, $outOf) => $outOf === null
            ? random_int(0, PHP_INT_MAX) / PHP_INT_MAX <= $chances
            : random_int(1, $outOf) <= $chances;
    }

    /**
     * Force the lottery to always result in a win.
     */
    public static function alwaysWin(?callable $callback = null): void
    {
        self::setResultFactory(fn () => true);

        if ($callback === null) {
            return;
        }

        $callback();

        static::determineResultNormally();
    }

    /**
     * Force the lottery to always result in a lose.
     */
    public static function alwaysLose(?callable $callback = null): void
    {
        self::setResultFactory(fn () => false);

        if ($callback === null) {
            return;
        }

        $callback();

        static::determineResultNormally();
    }

    /**
     * Set the sequence that will be used to determine lottery results.
     */
    public static function fix(array $sequence, ?callable $whenMissing = null): void
    {
        static::forceResultWithSequence($sequence, $whenMissing);
    }

    /**
     * Set the sequence that will be used to determine lottery results.
     */
    public static function forceResultWithSequence(array $sequence, ?callable $whenMissing = null): void
    {
        $next = 0;

        $whenMissing ??= function ($chances, $outOf) use (&$next) {
            $factoryCache = static::$resultFactory;

            static::$resultFactory = null;

            $result = static::resultFactory()($chances, $outOf);

            static::$resultFactory = $factoryCache;

            ++$next;

            return $result;
        };

        static::setResultFactory(function ($chances, $outOf) use (&$next, $sequence, $whenMissing) {
            if (array_key_exists($next, $sequence)) {
                return $sequence[$next++];
            }

            return $whenMissing($chances, $outOf);
        });
    }

    /**
     * Indicate that the lottery results should be determined normally.
     */
    public static function determineResultsNormally(): void
    {
        static::determineResultNormally();
    }

    /**
     * Indicate that the lottery results should be determined normally.
     */
    public static function determineResultNormally(): void
    {
        static::$resultFactory = null;
    }

    /**
     * Set the factory that should be used to determine the lottery results.
     */
    public static function setResultFactory(callable $factory): void
    {
        self::$resultFactory = $factory;
    }
}
