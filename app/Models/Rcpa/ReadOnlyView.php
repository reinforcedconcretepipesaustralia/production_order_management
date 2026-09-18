<?php

namespace App\Models\Rcpa;

use App\Exceptions\ReadOnlyConnectionException;
use App\Support\Database\ReadOnlyEloquentBuilder;
use Illuminate\Database\Eloquent\Model;

abstract class ReadOnlyView extends Model
{
    /**
     * Additional SQL Server data source. The app default connection stays local.
     */
    protected $connection = 'rcpa_sql';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Views are mapped with a named primary key in each concrete model.
     */
    protected $guarded = ['*'];

    public function save(array $options = []): bool
    {
        throw ReadOnlyConnectionException::rcpaSql();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw ReadOnlyConnectionException::rcpaSql();
    }

    public function delete(): ?bool
    {
        throw ReadOnlyConnectionException::rcpaSql();
    }

    public function newEloquentBuilder($query): ReadOnlyEloquentBuilder
    {
        return new ReadOnlyEloquentBuilder($query);
    }
}
