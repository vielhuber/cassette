<?php
// Intentionally NO `declare(strict_types=1);`.
//
// CassetteHooks.php is strict-typed, so calling an internal function via
// `$original(...$args)` from there triggers strict argument type checks.
// Real-world code routinely relies on PHP's coercive defaults — e.g.
// `uniqid(mt_rand(), true)` passes an int where a string is declared. Without
// the hook the call site is non-strict and PHP coerces silently; with the
// hook the call moves into our strict file and throws a TypeError.
//
// This helper exists solely to give those `$original(...$args)` calls a
// non-strict invocation context so coercion works exactly like before.

/**
 * Invoke a captured original callable with the original argument list using
 * PHP's coercive (non-strict) type rules.
 *
 * @param callable    $fn
 * @param array<int, mixed> $args
 * @return mixed
 */
function cassetteInvokeOriginal(callable $fn, array $args)
{
    return $fn(...$args);
}

/**
 * Invoke an instance method via ReflectionMethod under non-strict type rules.
 * Reflection bypasses uopz (which is why the instance hook uses it instead of
 * a Closure), but it still inherits the calling file's strict_types — so the
 * actual `invoke()` call has to live here, not in CassetteHooks.php.
 *
 * @param array<int, mixed> $args
 * @return mixed
 */
function cassetteInvokeReflectionMethod(\ReflectionMethod $method, object $object, array $args)
{
    return $method->invoke($object, ...$args);
}
