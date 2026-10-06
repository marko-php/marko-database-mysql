<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Query;

use Closure;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\StatementInterface;
use Marko\Database\Connection\TransactionInterface;
use RuntimeException;

/**
 * Records the SQL a query builder sends and reports a configurable
 * transaction state and server version, so row-lock and upsert compilation
 * can be asserted without a MySQL server. SELECT VERSION() is answered
 * with $serverVersion and counted, never recorded as the last query.
 */
class RecordingTransactionalConnection implements ConnectionInterface, TransactionInterface
{
    public string $lastQuerySql = '';

    /** @var array<mixed> */
    public array $lastQueryBindings = [];

    public string $lastExecuteSql = '';

    /** How many times SELECT VERSION() was queried. */
    public int $versionQueries = 0;

    /** @var array<mixed> */
    public array $lastExecuteBindings = [];

    public function __construct(
        public bool $open = true,
        private readonly int $executeReturn = 0,
        private readonly string $serverVersion = '8.4.3',
    ) {}

    public function connect(): void {}

    public function disconnect(): void {}

    public function isConnected(): bool
    {
        return true;
    }

    public function query(
        string $sql,
        array $bindings = [],
    ): array {
        if ($sql === 'SELECT VERSION() AS version') {
            $this->versionQueries++;

            return [['version' => $this->serverVersion]];
        }

        $this->lastQuerySql = $sql;
        $this->lastQueryBindings = $bindings;

        return [];
    }

    public function execute(
        string $sql,
        array $bindings = [],
    ): int {
        $this->lastExecuteSql = $sql;
        $this->lastExecuteBindings = $bindings;

        return $this->executeReturn;
    }

    public function prepare(
        string $sql,
    ): StatementInterface {
        throw new RuntimeException('Not implemented');
    }

    public function lastInsertId(): int
    {
        return 0;
    }

    public function driverName(): string
    {
        return 'mysql';
    }

    public function supportsReturning(): bool
    {
        return false;
    }

    public function quoteIdentifier(
        string $identifier,
    ): string {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function beginTransaction(): void
    {
        $this->open = true;
    }

    public function commit(): void
    {
        $this->open = false;
    }

    public function rollback(): void
    {
        $this->open = false;
    }

    public function inTransaction(): bool
    {
        return $this->open;
    }

    public function transactionLevel(): int
    {
        return $this->open ? 1 : 0;
    }

    public function transaction(
        callable $callback,
        int $attempts = 1,
        int|Closure|null $backoff = null,
    ): mixed {
        return $callback();
    }

    public function afterCommit(
        callable $callback,
    ): void {}

    public function afterRollback(
        callable $callback,
    ): void {}
}
