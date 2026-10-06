<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Integration;

use Marko\Database\Exceptions\QueryException;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase;

/*
 * Server-side prepares and single-statement queries against a real MySQL server (CI also runs this file
 * against MariaDB). The connection turns off PDO::ATTR_EMULATE_PREPARES and PDO\Mysql::ATTR_MULTI_STATEMENTS,
 * so a stacked statement fails instead of running, and int bindings reach the server as integers. The tests
 * create and drop the native_prepares_rows table.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With MARKO_INTEGRATION_REQUIRED set (CI), a missing
 * host fails instead of skipping. Part of the integration-services group.
 */

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new MySqlConnection($config);
    $this->connection->execute('DROP TABLE IF EXISTS native_prepares_rows');
    $this->connection->execute('CREATE TABLE native_prepares_rows (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL)');
    $this->connection->execute(
        'INSERT INTO native_prepares_rows (id, name) VALUES (?, ?), (?, ?), (?, ?)',
        [1, 'Alice', 2, 'Bob', 3, 'Carol'],
    );
});

afterEach(function (): void {
    if (isset($this->connection)) {
        $this->connection->execute('DROP TABLE IF EXISTS native_prepares_rows');
        $this->connection->disconnect();
    }
});

it('rejects a stacked second statement and leaves the table in place', function (): void {
    expect(fn () => $this->connection->query('SELECT 1; DROP TABLE native_prepares_rows'))
        ->toThrow(QueryException::class)
        ->and($this->connection->query('SELECT COUNT(*) AS total FROM native_prepares_rows')[0]['total'])
        ->toBe(3);
});

it('rejects a stacked second statement through execute()', function (): void {
    expect(fn () => $this->connection->execute(
        "UPDATE native_prepares_rows SET name = 'x' WHERE id = 1; DELETE FROM native_prepares_rows",
    ))->toThrow(QueryException::class)
        ->and($this->connection->query('SELECT name FROM native_prepares_rows WHERE id = 1')[0]['name'])
        ->toBe('Alice');
});

it('binds int values for LIMIT and OFFSET placeholders', function (): void {
    $rows = $this->connection->query(
        'SELECT name FROM native_prepares_rows ORDER BY id LIMIT ? OFFSET ?',
        [2, 1],
    );

    expect(array_column($rows, 'name'))->toBe(['Bob', 'Carol']);
});

it('treats a quote in a bound value as data, not SQL', function (): void {
    $rows = $this->connection->query(
        'SELECT id FROM native_prepares_rows WHERE name = ?',
        ["' OR 1=1 #"],
    );

    expect($rows)->toBe([]);
});
