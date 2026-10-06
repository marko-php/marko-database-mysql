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
use Marko\Database\Schema\Literal;
use Marko\Database\Schema\Table;

/*
 * Expression defaults against a real MySQL server. Set MARKO_TEST_MYSQL_HOST
 * (and optionally MARKO_TEST_MYSQL_PORT, _DATABASE, _USERNAME, _PASSWORD) to
 * enable; the tests skip otherwise. The tests create and drop the
 * expression_default_items table.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With
 * MARKO_INTEGRATION_REQUIRED set (CI), a missing host fails instead of
 * skipping. Part of the integration-services group.
 */

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new MySqlConnection($config);
    $this->connection->execute('DROP TABLE IF EXISTS expression_default_items');
    $this->generator = new MySqlGenerator();
    $this->introspector = new MySqlIntrospector($this->connection, $config->database);

    // Abstract type names (integer, varchar, json, timestamp), as the introspector reports them
    $this->entityTable = new Table(
        name: 'expression_default_items',
        columns: [
            new Column(name: 'id', type: 'integer', primaryKey: true, autoIncrement: true),
            new Column(name: 'ref', type: 'varchar', length: 36, default: new Expression('(UUID())')),
            new Column(name: 'tags', type: 'json', default: new Expression('JSON_ARRAY()')),
            new Column(name: 'created_at', type: 'timestamp', default: 'CURRENT_TIMESTAMP'),
            new Column(name: 'label', type: 'varchar', length: 20, default: new Literal('UUID()')),
        ],
    );
    $this->connection->execute($this->generator->generateCreateTable($this->entityTable));
});

afterEach(function (): void {
    if (isset($this->connection)) {
        $this->connection->execute('DROP TABLE IF EXISTS expression_default_items');
        $this->connection->disconnect();
    }
});

describe('MySQL expression defaults', function (): void {
    it('creates expression defaults and diffs clean', function (): void {
        $this->connection->execute('INSERT INTO expression_default_items () VALUES ()');
        $row = $this->connection->query('SELECT ref, tags, created_at, label FROM expression_default_items')[0];
        $columns = $this->introspector->getTable('expression_default_items')->columns;

        $diff = new DiffCalculator()->calculate(
            ['expression_default_items' => $this->entityTable],
            ['expression_default_items' => $this->introspector->getTable('expression_default_items')],
        );

        expect($row['ref'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
            ->and($row['tags'])->toBe('[]')
            ->and($row['created_at'])->not->toBeNull()
            ->and($row['label'])->toBe('UUID()')
            ->and($columns[1]->default)->toEqual(new Expression('uuid()'))
            ->and($columns[4]->default)->toEqual(new Literal('UUID()'))
            ->and($diff->isEmpty())->toBeTrue();
    });
});
