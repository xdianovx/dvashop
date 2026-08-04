<?php

namespace App\Services\Catalog;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class KnownUniqueConstraintGuard
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @param  array<int, string>  $identifiers
     * @return TResult
     */
    public function run(
        Closure $callback,
        string $field,
        string $message,
        array $identifiers,
    ): mixed {
        try {
            return $callback();
        } catch (QueryException $exception) {
            if (! $this->matches($exception, $identifiers)) {
                throw $exception;
            }

            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /** @param array<int, string> $identifiers */
    public function matches(QueryException $exception, array $identifiers): bool
    {
        $message = mb_strtolower($exception->getMessage());
        $duplicate = in_array((string) $exception->getCode(), ['19', '23000', '23505'], true)
            || (int) ($exception->errorInfo[1] ?? 0) === 1062
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry');

        return $duplicate && collect($identifiers)
            ->contains(fn (string $identifier): bool => str_contains($message, mb_strtolower($identifier)));
    }
}
