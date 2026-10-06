<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Introspection;

use Closure;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\StatementInterface;
use RuntimeException;

/**
 * Records every statement and query in order, answering them through closures, so the expression default
 * probe can be asserted without a MySQL server.
 */
class ProbeRecordingConnection implements ConnectionInterface
{
    /**
     * @var list<string>
     */
    public array $log = [];

    /**
     * @param Closure(string, array<mixed>): array<array<string, mixed>> $onQuery
     * @param Closure(string): void|null $onExecute Throws to make a statement fail
     */
    public function __construct(
        private readonly Closure $onQuery,
        private readonly ?Closure $onExecute = null,
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
        $this->log[] = $sql;

        return ($this->onQuery)($sql, $bindings);
    }

    public function execute(
        string $sql,
        array $bindings = [],
    ): int {
        $this->log[] = $sql;

        if ($this->onExecute !== null) {
            ($this->onExecute)($sql);
        }

        return 0;
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
}
