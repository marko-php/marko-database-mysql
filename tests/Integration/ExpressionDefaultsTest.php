<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Integration;

use Marko\Database\Diff\DiffCalculator;
use Marko\Database\Diff\ExpressionDefaultCanonicalizer;
use Marko\Database\Exceptions\MigrationException;
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

describe('MySQL expression defaults the server respells', function (): void {
    beforeEach(function (): void {
        $this->connection->execute('DROP TABLE IF EXISTS expression_default_items');

        $this->respelledTable = fn (string $label, string $expiresAt): Table => new Table(
            name: 'expression_default_items',
            columns: [
                new Column(name: 'id', type: 'integer', primaryKey: true, autoIncrement: true),
                new Column(name: 'label', type: 'varchar', length: 40, default: new Expression($label)),
                new Column(name: 'expires_at', type: 'datetime', default: new Expression($expiresAt)),
            ],
        );

        // As db:diff does: expression defaults the server respells are settled before the diff
        $this->diffAgainst = function (Table $entityTable) {
            $databaseSchema = [
                'expression_default_items' => $this->introspector->getTable('expression_default_items'),
            ];

            return new DiffCalculator()->calculate(
                new ExpressionDefaultCanonicalizer($this->introspector)->canonicalize(
                    ['expression_default_items' => $entityTable],
                    $databaseSchema,
                ),
                $databaseSchema,
            );
        };
    });

    it('diffs CONCAT and interval arithmetic expression defaults as empty right after creation', function (): void {
        $entityTable = ($this->respelledTable)("(CONCAT('it''s', 'b'))", '(CURRENT_TIMESTAMP + INTERVAL 1 DAY)');
        $this->connection->execute($this->generator->generateCreateTable($entityTable));
        $this->connection->execute('INSERT INTO expression_default_items () VALUES ()');

        $row = $this->connection->query('SELECT label FROM expression_default_items')[0];

        expect($row['label'])->toBe("it'sb")
            ->and(($this->diffAgainst)($entityTable)->isEmpty())->toBeTrue();
    });

    it('still diffs a changed expression and modifies the column', function (): void {
        $this->connection->execute($this->generator->generateCreateTable(
            ($this->respelledTable)("(CONCAT('a', 'b'))", '(CURRENT_TIMESTAMP + INTERVAL 1 DAY)'),
        ));

        $entityTable = ($this->respelledTable)("(CONCAT('a', 'c'))", '(CURRENT_TIMESTAMP + INTERVAL 1 DAY)');
        $diff = ($this->diffAgainst)($entityTable);
        $statements = $this->generator->generateUp($diff);

        foreach ($statements as $statement) {
            $this->connection->execute($statement);
        }

        $this->connection->execute('INSERT INTO expression_default_items () VALUES ()');

        expect(array_keys($diff->tablesToAlter['expression_default_items']->columnsToModify))->toBe(['label'])
            ->and($statements)->toHaveCount(1)
            ->and($statements[0])->toContain("DEFAULT (CONCAT('a', 'c'))")
            ->and($this->connection->query('SELECT label FROM expression_default_items')[0]['label'])->toBe('ac')
            ->and(($this->diffAgainst)($entityTable)->isEmpty())->toBeTrue();
    });

    it('fails at diff time for an expression MySQL rejects', function (): void {
        $this->connection->execute($this->generator->generateCreateTable(
            ($this->respelledTable)("(CONCAT('a', 'b'))", '(CURRENT_TIMESTAMP + INTERVAL 1 DAY)'),
        ));

        $entityTable = ($this->respelledTable)("(CONCAT('a', 'b'))", '(CURRENT_TIMESTAMP + INTERVAL 1 FORTNIGHT)');

        expect(fn () => ($this->diffAgainst)($entityTable))->toThrow(
            MigrationException::class,
            'The database rejects the default expression "(CURRENT_TIMESTAMP + INTERVAL 1 FORTNIGHT)" of column '
            . "'expression_default_items.expires_at'",
        )
            ->and($this->connection->query("SHOW TABLES LIKE 'marko_default_probe'"))->toBe([]);
    });
});
