<?php

namespace App\Support\Database;

use App\Exceptions\ReadOnlyConnectionException;
use Illuminate\Database\SqlServerConnection;

/**
 * A SQL Server connection that refuses to execute anything but reads.
 *
 * The `readonly` config flag alone only adds `ApplicationIntent=ReadOnly` to the
 * DSN, which routes to a replica in an availability group but does not stop
 * writes. Blocking the write paths here means no entry point in the app can
 * modify the source system, even if the SQL login is over-privileged.
 */
class ReadOnlySqlServerConnection extends SqlServerConnection
{
    /**
     * {@inheritdoc}
     */
    public function insert($query, $bindings = [])
    {
        throw ReadOnlyConnectionException::forConnection($this->getName());
    }

    /**
     * {@inheritdoc}
     */
    public function update($query, $bindings = [])
    {
        throw ReadOnlyConnectionException::forConnection($this->getName());
    }

    /**
     * {@inheritdoc}
     */
    public function delete($query, $bindings = [])
    {
        throw ReadOnlyConnectionException::forConnection($this->getName());
    }

    /**
     * {@inheritdoc}
     */
    public function statement($query, $bindings = [])
    {
        throw ReadOnlyConnectionException::forConnection($this->getName());
    }

    /**
     * {@inheritdoc}
     */
    public function affectingStatement($query, $bindings = [])
    {
        throw ReadOnlyConnectionException::forConnection($this->getName());
    }

    /**
     * {@inheritdoc}
     */
    public function unprepared($query)
    {
        throw ReadOnlyConnectionException::forConnection($this->getName());
    }
}
