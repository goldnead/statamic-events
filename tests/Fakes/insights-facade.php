<?php

/**
 * A stand-in for the analytics addon's facade, declared under its namespace.
 *
 * The service provider couples by class name and nothing else, so this is the
 * whole of what it takes to prove the registration without installing the
 * sibling — which is the point of coupling that way.
 *
 * Loaded from `tests/Pest.php`, before the application boots: the provider asks
 * `class_exists` while booting the addon, and a file required after that has
 * already missed its only chance to be seen. Every test in the run therefore
 * boots as though the sibling were installed; with no root bound, the
 * provider's second guard turns the registration into an early return, which is
 * exactly the "installed but not quite" case it is there for.
 *
 * Guarded, so a real installation wins. Same rule as the contracts file beside
 * it.
 */

namespace Goldnead\StatamicInsights\Facades;

if (! class_exists('Goldnead\StatamicInsights\Facades\Insights')) {
    class Insights
    {
        public static ?object $root = null;

        public static function getFacadeRoot(): ?object
        {
            return static::$root;
        }

        public static function __callStatic(string $method, array $arguments): mixed
        {
            if (static::$root === null) {
                throw new \RuntimeException('The Insights stand-in has no root bound.');
            }

            return static::$root->{$method}(...$arguments);
        }
    }
}
