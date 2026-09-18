<?php

namespace App\Support\Database;

use App\Exceptions\ReadOnlyConnectionException;
use Illuminate\Database\Eloquent\Builder;

class ReadOnlyEloquentBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        throw ReadOnlyConnectionException::rcpaSql();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function insertGetId(array $values, $sequence = null)
    {
        throw ReadOnlyConnectionException::rcpaSql();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        throw ReadOnlyConnectionException::rcpaSql();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        throw ReadOnlyConnectionException::rcpaSql();
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw ReadOnlyConnectionException::rcpaSql();
    }

    public function delete(): mixed
    {
        throw ReadOnlyConnectionException::rcpaSql();
    }

    public function forceDelete(): mixed
    {
        throw ReadOnlyConnectionException::rcpaSql();
    }

    public function truncate(): void
    {
        throw ReadOnlyConnectionException::rcpaSql();
    }
}
