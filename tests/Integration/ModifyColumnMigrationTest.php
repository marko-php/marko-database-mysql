<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Integration;

use Marko\Database\Diff\DiffCalculator;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Introspection\MySqlIntrospector;
use Marko\Database\MySql\Sql\MySqlGenerator;
use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase;
use Marko\Database\Schema\Column;
use Marko\Database\Schema\Expression;
use Marko\Database\Schema\Table;

/*
 * Column modification migrations against a real MySQL server. Set
 * MARKO_TEST_MYSQL_HOST (and optionally MARKO_TEST_MYSQL_PORT, _DATABASE,
 * _USERNAME, _PASSWORD) to enable; the tests skip otherwise. The tests create
 * and drop the modify_column_products table.
 *
 * The table is created with raw DDL because it holds what an entity cannot
 * declare: DECIMAL precision, UNSIGNED, a column collation and ON UPDATE.
 * Entity columns use MySQL type names (int, varchar, decimal, timestamp) so
 * the diff compares like with like.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With
 * MARKO_INTEGRATION_REQUIRED set (CI), a missing host fails instead of
 * skipping. Part of the integration-services group.
 */

/**
 * The modify_column_products table as an entity would declare it.
 */
function mysqlModifyColumnTable(
    Column ...$columns,
): Table {
    return new Table(
        name: 'modify_column_products',
        columns: [
            new Column(name: 'id', type: 'int', primaryKey: true, autoIncrement: true),
            ...$columns,
        ],
    );
}

function mysqlShowCreateTable(
    MySqlConnection $connection,
): string {
    return $connection->query('SHOW CREATE TABLE modify_column_products')[0]['Create Table'];
}

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new MySqlConnection($config);
    $this->connection->execute('DROP TABLE IF EXISTS modify_column_products');
    $this->connection->execute(
        'CREATE TABLE modify_column_products ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
        . "title VARCHAR(500) NOT NULL DEFAULT 'untitled', "
        . 'code VARCHAR(32) COLLATE utf8mb4_bin NOT NULL, '
        . "price DECIMAL(12,4) UNSIGNED NOT NULL DEFAULT '0.0000', "
        . 'author_id INT UNSIGNED NOT NULL, '
        . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        . ') DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
    );
    $this->original = mysqlShowCreateTable($this->connection);

    $this->generator = new MySqlGenerator();
    $this->calculator = new DiffCalculator();
    $this->introspector = new MySqlIntrospector($this->connection, $config->database);

    $this->diffAgainst = fn (Table $entityTable) => $this->calculator->calculate(
        ['modify_column_products' => $entityTable],
        ['modify_column_products' => $this->introspector->getTable('modify_column_products')],
    );

    $this->run = function (array $statements): void {
        foreach ($statements as $statement) {
            $this->connection->execute($statement);
        }
    };

    // Every column becomes nullable; nothing else is declared, so the database keeps the rest
    $this->nullableEntity = mysqlModifyColumnTable(
        new Column(name: 'title', type: 'varchar', nullable: true),
        new Column(name: 'code', type: 'varchar', nullable: true),
        new Column(name: 'price', type: 'decimal', nullable: true),
        new Column(name: 'author_id', type: 'int', nullable: true),
        new Column(name: 'updated_at', type: 'timestamp', nullable: true),
    );
});

afterEach(function (): void {
    if (isset($this->connection)) {
        $this->connection->execute('DROP TABLE IF EXISTS modify_column_products');
        $this->connection->disconnect();
    }
});

describe('MySQL column modification migrations', function (): void {
    it('keeps the length, default and native definition when only nullability changes', function (): void {
        $diff = ($this->diffAgainst)($this->nullableEntity);
        ($this->run)($this->generator->generateUp($diff));
        $columns = $this->introspector->getTable('modify_column_products')->columns;

        expect($diff->isEmpty())->toBeFalse()
            ->and($columns[1]->nullable)->toBeTrue()
            ->and($columns[1]->length)->toBe(500)
            ->and($columns[1]->default)->toBe('untitled')
            ->and($columns[2]->collation)->toBe('utf8mb4_bin')
            ->and($columns[3]->nativeType)->toBe('decimal(12,4) unsigned')
            ->and($columns[3]->default)->toBe('0.0000')
            ->and($columns[4]->nativeType)->toBe('int unsigned')
            ->and($columns[5]->default)->toEqual(new Expression('CURRENT_TIMESTAMP'))
            ->and($columns[5]->onUpdateExpression)->toBe('CURRENT_TIMESTAMP');
    });

    it('yields an empty diff after applying a generated change', function (): void {
        ($this->run)($this->generator->generateUp(($this->diffAgainst)($this->nullableEntity)));

        expect(($this->diffAgainst)($this->nullableEntity)->isEmpty())->toBeTrue();
    });

    it('restores SHOW CREATE TABLE exactly after up and down', function (): void {
        $diff = ($this->diffAgainst)($this->nullableEntity);
        ($this->run)($this->generator->generateUp($diff));
        $afterUp = mysqlShowCreateTable($this->connection);
        ($this->run)($this->generator->generateDown($diff));

        expect($afterUp)->not->toBe($this->original)
            ->and(mysqlShowCreateTable($this->connection))->toBe($this->original);
    });

    it('restores SHOW CREATE TABLE exactly after up and down of type changes', function (): void {
        $entityTable = mysqlModifyColumnTable(
            new Column(name: 'title', type: 'text'),
            new Column(name: 'code', type: 'varchar', length: 64),
            new Column(name: 'price', type: 'double'),
            new Column(name: 'author_id', type: 'bigint'),
            new Column(name: 'updated_at', type: 'datetime', nullable: true),
        );

        $diff = ($this->diffAgainst)($entityTable);
        ($this->run)($this->generator->generateUp($diff));
        $code = $this->introspector->getTable('modify_column_products')->columns[2];
        ($this->run)($this->generator->generateDown($diff));

        expect($code->nativeType)->toBe('varchar(64)')
            ->and($code->collation)->toBe('utf8mb4_bin')
            ->and(mysqlShowCreateTable($this->connection))->toBe($this->original);
    });
});
