<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Introspection;

use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\StatementInterface;
use Marko\Database\Introspection\IntrospectorInterface;
use Marko\Database\MySql\Introspection\MySqlIntrospector;
use Marko\Database\Schema\Column;
use Marko\Database\Schema\Expression;
use Marko\Database\Schema\ForeignKey;
use Marko\Database\Schema\Index;
use Marko\Database\Schema\IndexType;
use Marko\Database\Schema\Literal;
use Marko\Database\Schema\Table;
use RuntimeException;

/**
 * The columns of a table whose varchar columns have the given COLUMN_DEFAULT and EXTRA values, in order.
 *
 * @param list<array{0: string, 1: string}> $defaults
 * @return array<Column>
 */
function mysqlColumnsWithDefaults(
    array $defaults,
): array {
    $connection = createMockConnection([
        'information_schema.columns' => array_map(
            static fn (array $default, int $index): array => [
                'COLUMN_NAME' => "col_$index",
                'DATA_TYPE' => 'varchar',
                'CHARACTER_MAXIMUM_LENGTH' => '255',
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => $default[0],
                'EXTRA' => $default[1],
                'COLUMN_TYPE' => 'varchar(255)',
                'COLLATION_NAME' => null,
            ],
            $defaults,
            array_keys($defaults),
        ),
    ]);

    return new MySqlIntrospector($connection, 'testdb')->getColumns('posts');
}

/**
 * The columns of a table with the given (DATA_TYPE, COLUMN_TYPE, COLUMN_DEFAULT) definitions, as MySQL or, with
 * $version naming MariaDB, as MariaDB reports them.
 *
 * @param list<array{0: string, 1: string, 2: string|null}> $definitions
 * @return array<Column>
 */
function mysqlTypedColumns(
    array $definitions,
    string $version = '8.4.3',
): array {
    $connection = createMockConnection([
        'VERSION()' => [['version' => $version]],
        'information_schema.columns' => array_map(
            static fn (array $definition, int $index): array => [
                'COLUMN_NAME' => "col_$index",
                'DATA_TYPE' => $definition[0],
                'CHARACTER_MAXIMUM_LENGTH' => null,
                'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => $definition[2],
                'EXTRA' => '',
                'COLUMN_TYPE' => $definition[1],
                'COLLATION_NAME' => null,
            ],
            $definitions,
            array_keys($definitions),
        ),
    ]);

    return new MySqlIntrospector($connection, 'testdb')->getColumns('items');
}

/**
 * Creates a mock connection that returns predefined query results.
 *
 * @param array<string, array<int, array<string, mixed>>> $queryResults Map of SQL patterns to results
 */
function createMockConnection(
    array $queryResults = [],
): ConnectionInterface {
    return new readonly class ($queryResults) implements ConnectionInterface
    {
        /**
         * @param array<string, array<int, array<string, mixed>>> $queryResults
         */
        public function __construct(
            private array $queryResults,
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
            // Find matching query result by SQL pattern
            foreach ($this->queryResults as $pattern => $results) {
                if (str_contains($sql, $pattern)) {
                    return $results;
                }
            }

            return [];
        }

        public function execute(
            string $sql,
            array $bindings = [],
        ): int {
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
            return 'sqlite';
        }
    };
}

describe('MySqlIntrospector', function (): void {
    it('implements IntrospectorInterface', function (): void {
        $connection = createMockConnection();
        $introspector = new MySqlIntrospector($connection, 'testdb');

        expect($introspector)->toBeInstanceOf(IntrospectorInterface::class);
    });

    it('reads table list from information_schema.tables', function (): void {
        $connection = createMockConnection([
            'information_schema.tables' => [
                ['TABLE_NAME' => 'users'],
                ['TABLE_NAME' => 'posts'],
                ['TABLE_NAME' => 'comments'],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $tables = $introspector->getTables();

        expect($tables)->toBe(['users', 'posts', 'comments']);
    });

    it('reads column definitions from information_schema.columns', function (): void {
        $connection = createMockConnection([
            'information_schema.columns' => [
                [
                    'COLUMN_NAME' => 'id',
                    'DATA_TYPE' => 'int',
                    'CHARACTER_MAXIMUM_LENGTH' => null,
                    'IS_NULLABLE' => 'NO',
                    'COLUMN_DEFAULT' => null,
                    'EXTRA' => 'auto_increment',
                    'COLUMN_TYPE' => 'int',
                    'COLLATION_NAME' => null,
                ],
                [
                    'COLUMN_NAME' => 'name',
                    'DATA_TYPE' => 'varchar',
                    'CHARACTER_MAXIMUM_LENGTH' => '255',
                    'IS_NULLABLE' => 'NO',
                    'COLUMN_DEFAULT' => null,
                    'EXTRA' => '',
                    'COLUMN_TYPE' => 'varchar(255)',
                    'COLLATION_NAME' => 'utf8mb4_0900_ai_ci',
                ],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $columns = $introspector->getColumns('users');

        expect($columns)
            ->toHaveCount(2)
            ->and($columns[0])->toBeInstanceOf(Column::class)
            ->and($columns[0]->name)->toBe('id')
            ->and($columns[1]->name)->toBe('name');
    });

    it('maps MySQL data types to the abstract type names entities use', function (): void {
        $connection = createMockConnection([
            'information_schema.columns' => [
                [
                    'COLUMN_NAME' => 'id',
                    'DATA_TYPE' => 'bigint',
                    'CHARACTER_MAXIMUM_LENGTH' => null,
                    'IS_NULLABLE' => 'NO',
                    'COLUMN_DEFAULT' => null,
                    'EXTRA' => '',
                    'COLUMN_TYPE' => 'bigint',
                    'COLLATION_NAME' => null,
                ],
                [
                    'COLUMN_NAME' => 'title',
                    'DATA_TYPE' => 'varchar',
                    'CHARACTER_MAXIMUM_LENGTH' => '100',
                    'IS_NULLABLE' => 'NO',
                    'COLUMN_DEFAULT' => null,
                    'EXTRA' => '',
                    'COLUMN_TYPE' => 'varchar(100)',
                    'COLLATION_NAME' => 'utf8mb4_0900_ai_ci',
                ],
                [
                    'COLUMN_NAME' => 'content',
                    'DATA_TYPE' => 'text',
                    'CHARACTER_MAXIMUM_LENGTH' => null,
                    'IS_NULLABLE' => 'YES',
                    'COLUMN_DEFAULT' => null,
                    'EXTRA' => '',
                    'COLUMN_TYPE' => 'text',
                    'COLLATION_NAME' => 'utf8mb4_0900_ai_ci',
                ],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $columns = $introspector->getColumns('posts');

        expect($columns[0]->type)
            ->toBe('bigint')
            ->and($columns[1]->type)->toBe('varchar')
            ->and($columns[1]->length)->toBe(100)
            ->and($columns[2]->type)->toBe('text');
    });

    it('detects nullable columns', function (): void {
        $connection = createMockConnection([
            'information_schema.columns' => [
                [
                    'COLUMN_NAME' => 'id',
                    'DATA_TYPE' => 'int',
                    'CHARACTER_MAXIMUM_LENGTH' => null,
                    'IS_NULLABLE' => 'NO',
                    'COLUMN_DEFAULT' => null,
                    'EXTRA' => '',
                    'COLUMN_TYPE' => 'int',
                    'COLLATION_NAME' => null,
                ],
                [
                    'COLUMN_NAME' => 'bio',
                    'DATA_TYPE' => 'text',
                    'CHARACTER_MAXIMUM_LENGTH' => null,
                    'IS_NULLABLE' => 'YES',
                    'COLUMN_DEFAULT' => null,
                    'EXTRA' => '',
                    'COLUMN_TYPE' => 'text',
                    'COLLATION_NAME' => 'utf8mb4_0900_ai_ci',
                ],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $columns = $introspector->getColumns('users');

        expect($columns[0]->nullable)
            ->toBeFalse()
            ->and($columns[1]->nullable)->toBeTrue();
    });

    it('detects default values', function (): void {
        $connection = createMockConnection([
            'information_schema.columns' => [
                [
                    'COLUMN_NAME' => 'status',
                    'DATA_TYPE' => 'varchar',
                    'CHARACTER_MAXIMUM_LENGTH' => '20',
                    'IS_NULLABLE' => 'NO',
                    'COLUMN_DEFAULT' => 'active',
                    'EXTRA' => '',
                    'COLUMN_TYPE' => 'varchar(20)',
                    'COLLATION_NAME' => 'utf8mb4_0900_ai_ci',
                ],
                [
                    'COLUMN_NAME' => 'priority',
                    'DATA_TYPE' => 'int',
                    'CHARACTER_MAXIMUM_LENGTH' => null,
                    'IS_NULLABLE' => 'NO',
                    'COLUMN_DEFAULT' => '0',
                    'EXTRA' => '',
                    'COLUMN_TYPE' => 'int',
                    'COLLATION_NAME' => null,
                ],
                [
                    'COLUMN_NAME' => 'created_at',
                    'DATA_TYPE' => 'timestamp',
                    'CHARACTER_MAXIMUM_LENGTH' => null,
                    'IS_NULLABLE' => 'NO',
                    'COLUMN_DEFAULT' => 'CURRENT_TIMESTAMP',
                    'EXTRA' => 'DEFAULT_GENERATED',
                    'COLUMN_TYPE' => 'timestamp',
                    'COLLATION_NAME' => null,
                ],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $columns = $introspector->getColumns('tasks');

        expect($columns[0]->default)
            ->toBe('active')
            ->and($columns[1]->default)->toBe(0)
            ->and($columns[2]->default)->toEqual(new Expression('CURRENT_TIMESTAMP'));
    });

    it('reads a DEFAULT_GENERATED default as an expression', function (): void {
        $columns = mysqlColumnsWithDefaults([
            ['uuid()', 'DEFAULT_GENERATED'],
            ['CURRENT_TIMESTAMP(6)', 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP(6)'],
        ]);

        expect($columns[0]->default)->toEqual(new Expression('uuid()'))
            ->and($columns[1]->default)->toEqual(new Expression('CURRENT_TIMESTAMP(6)'));
    });

    it('reads a literal default that looks like a function as a literal', function (): void {
        $columns = mysqlColumnsWithDefaults([['now()', '']]);

        expect($columns[0]->default)->toEqual(new Literal('now()'));
    });

    it('keeps a plain string default as a string', function (): void {
        $columns = mysqlColumnsWithDefaults([['draft', '']]);

        expect($columns[0]->default)->toBe('draft');
    });

    it('keeps CURRENT_TIMESTAMP without DEFAULT_GENERATED as a string', function (): void {
        $columns = mysqlColumnsWithDefaults([
            ['CURRENT_TIMESTAMP', ''],
            ['current_timestamp()', 'on update current_timestamp()'],
            ['LOCALTIMESTAMP', ''],
        ]);

        expect($columns[0]->default)->toBe('CURRENT_TIMESTAMP')
            ->and($columns[1]->default)->toBe('current_timestamp()')
            ->and($columns[2]->default)->toBe('LOCALTIMESTAMP');
    });

    it('reads tinyint(1) as boolean and char(36) as uuid', function (): void {
        $columns = mysqlTypedColumns([
            ['tinyint', 'tinyint(1)', null],
            ['char', 'char(36)', null],
            ['tinyint', 'tinyint', null],
            ['char', 'char(2)', null],
            ['int', 'int unsigned', null],
        ]);

        expect(array_map(static fn (Column $column): string => $column->type, $columns))
            ->toBe(['boolean', 'uuid', 'tinyint', 'char', 'integer'])
            ->and($columns[0]->nativeType)->toBe('tinyint(1)')
            ->and($columns[1]->nativeType)->toBe('char(36)');
    });

    it('casts integer, boolean and decimal defaults to their PHP types', function (): void {
        $columns = mysqlTypedColumns([
            ['int', 'int', '0'],
            ['bigint', 'bigint', '-42'],
            ['tinyint', 'tinyint(1)', '0'],
            ['tinyint', 'tinyint(1)', '1'],
            ['decimal', 'decimal(10,2)', '0.00'],
            ['double', 'double', '1.5'],
            ['varchar', 'varchar(255)', '0'],
        ]);

        expect(array_map(static fn (Column $column): mixed => $column->default, $columns))
            ->toBe([0, -42, false, true, 0.0, 1.5, '0']);
    });

    it('unquotes MariaDB string defaults', function (): void {
        $columns = mysqlTypedColumns([
            ['varchar', 'varchar(255)', "'abc'"],
            ['varchar', 'varchar(255)', "'it''s'"],
            ['varchar', 'varchar(255)', "'now()'"],
            ['varchar', 'varchar(255)', "''"],
        ], '10.11.6-MariaDB');

        expect($columns[0]->default)->toBe('abc')
            ->and($columns[1]->default)->toBe("it's")
            ->and($columns[2]->default)->toEqual(new Literal('now()'))
            ->and($columns[3]->default)->toBe('');
    });

    it('reads the MariaDB NULL default as no default', function (): void {
        $columns = mysqlTypedColumns([['varchar', 'varchar(255)', 'NULL']], '10.11.6-MariaDB');

        expect($columns[0]->default)->toBeNull();
    });

    it(
        'reads MariaDB unquoted defaults as expressions and current_timestamp() as CURRENT_TIMESTAMP',
        function (): void {
            $columns = mysqlTypedColumns([
                ['timestamp', 'timestamp', 'current_timestamp()'],
                ['timestamp', 'timestamp(3)', 'current_timestamp(3)'],
                ['char', 'char(36)', 'uuid()'],
                ['int', 'int', '0'],
                ['tinyint', 'tinyint(1)', '1'],
            ], '10.11.6-MariaDB');

            expect($columns[0]->default)->toBe('CURRENT_TIMESTAMP')
                ->and($columns[1]->default)->toBe('CURRENT_TIMESTAMP(3)')
                ->and($columns[2]->default)->toEqual(new Expression('uuid()'))
                ->and($columns[3]->default)->toBe(0)
                ->and($columns[4]->default)->toBeTrue();
        },
    );

    it('keeps a MySQL default with quotes in it as the literal it is', function (): void {
        $columns = mysqlTypedColumns([['varchar', 'varchar(255)', "'abc'"], ['varchar', 'varchar(255)', 'NULL']]);

        expect($columns[0]->default)->toBe("'abc'")
            ->and($columns[1]->default)->toBe('NULL');
    });

    it('reads the native column definition the restating of a column needs', function (): void {
        $connection = createMockConnection([
            'information_schema.columns' => [
                [
                    'COLUMN_NAME' => 'price',
                    'DATA_TYPE' => 'decimal',
                    'CHARACTER_MAXIMUM_LENGTH' => null,
                    'IS_NULLABLE' => 'NO',
                    'COLUMN_DEFAULT' => null,
                    'EXTRA' => '',
                    'COLUMN_TYPE' => 'decimal(12,4) unsigned',
                    'COLLATION_NAME' => null,
                ],
                [
                    'COLUMN_NAME' => 'code',
                    'DATA_TYPE' => 'varchar',
                    'CHARACTER_MAXIMUM_LENGTH' => '32',
                    'IS_NULLABLE' => 'NO',
                    'COLUMN_DEFAULT' => null,
                    'EXTRA' => '',
                    'COLUMN_TYPE' => 'varchar(32)',
                    'COLLATION_NAME' => 'utf8mb4_bin',
                ],
                [
                    'COLUMN_NAME' => 'updated_at',
                    'DATA_TYPE' => 'timestamp',
                    'CHARACTER_MAXIMUM_LENGTH' => null,
                    'IS_NULLABLE' => 'NO',
                    'COLUMN_DEFAULT' => 'CURRENT_TIMESTAMP(3)',
                    'EXTRA' => 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP(3)',
                    'COLUMN_TYPE' => 'timestamp(3)',
                    'COLLATION_NAME' => null,
                ],
                [
                    'COLUMN_NAME' => 'touched_at',
                    'DATA_TYPE' => 'datetime',
                    'CHARACTER_MAXIMUM_LENGTH' => null,
                    'IS_NULLABLE' => 'YES',
                    'COLUMN_DEFAULT' => null,
                    'EXTRA' => 'on update current_timestamp()',
                    'COLUMN_TYPE' => 'datetime',
                    'COLLATION_NAME' => null,
                ],
            ],
        ]);

        $columns = new MySqlIntrospector($connection, 'testdb')->getColumns('products');

        expect($columns[0]->nativeType)->toBe('decimal(12,4) unsigned')
            ->and($columns[0]->collation)->toBeNull()
            ->and($columns[0]->onUpdateExpression)->toBeNull()
            ->and($columns[1]->nativeType)->toBe('varchar(32)')
            ->and($columns[1]->collation)->toBe('utf8mb4_bin')
            ->and($columns[2]->nativeType)->toBe('timestamp(3)')
            ->and($columns[2]->onUpdateExpression)->toBe('CURRENT_TIMESTAMP(3)')
            ->and($columns[3]->onUpdateExpression)->toBe('current_timestamp()');
    });

    it('selects the native type and only a collation that differs from the table default', function (): void {
        $connection = new class () implements ConnectionInterface
        {
            public string $columnsSql = '';

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
                if (str_contains($sql, 'information_schema.columns')) {
                    $this->columnsSql = $sql;
                }

                return [];
            }

            public function execute(
                string $sql,
                array $bindings = [],
            ): int {
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
        };

        new MySqlIntrospector($connection, 'testdb')->getColumns('products');

        expect($connection->columnsSql)->toContain('COLUMN_TYPE')
            ->toContain('CASE WHEN c.COLLATION_NAME = t.TABLE_COLLATION THEN NULL ELSE c.COLLATION_NAME END');
    });

    it('detects auto_increment columns', function (): void {
        $connection = createMockConnection([
            'information_schema.columns' => [
                [
                    'COLUMN_NAME' => 'id',
                    'DATA_TYPE' => 'int',
                    'CHARACTER_MAXIMUM_LENGTH' => null,
                    'IS_NULLABLE' => 'NO',
                    'COLUMN_DEFAULT' => null,
                    'EXTRA' => 'auto_increment',
                    'COLUMN_TYPE' => 'int',
                    'COLLATION_NAME' => null,
                ],
                [
                    'COLUMN_NAME' => 'name',
                    'DATA_TYPE' => 'varchar',
                    'CHARACTER_MAXIMUM_LENGTH' => '255',
                    'IS_NULLABLE' => 'NO',
                    'COLUMN_DEFAULT' => null,
                    'EXTRA' => '',
                    'COLUMN_TYPE' => 'varchar(255)',
                    'COLLATION_NAME' => 'utf8mb4_0900_ai_ci',
                ],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $columns = $introspector->getColumns('users');

        expect($columns[0]->autoIncrement)
            ->toBeTrue()
            ->and($columns[1]->autoIncrement)->toBeFalse();
    });

    it('reads indexes from information_schema.statistics', function (): void {
        $connection = createMockConnection([
            'information_schema.statistics' => [
                [
                    'INDEX_NAME' => 'idx_email',
                    'COLUMN_NAME' => 'email',
                    'NON_UNIQUE' => '1',
                    'INDEX_TYPE' => 'BTREE',
                    'SEQ_IN_INDEX' => '1',
                ],
                [
                    'INDEX_NAME' => 'idx_name_created',
                    'COLUMN_NAME' => 'name',
                    'NON_UNIQUE' => '1',
                    'INDEX_TYPE' => 'BTREE',
                    'SEQ_IN_INDEX' => '1',
                ],
                [
                    'INDEX_NAME' => 'idx_name_created',
                    'COLUMN_NAME' => 'created_at',
                    'NON_UNIQUE' => '1',
                    'INDEX_TYPE' => 'BTREE',
                    'SEQ_IN_INDEX' => '2',
                ],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $indexes = $introspector->getIndexes('users');

        expect($indexes)
            ->toHaveCount(2)
            ->and($indexes[0])->toBeInstanceOf(Index::class)
            ->and($indexes[0]->name)->toBe('idx_email')
            ->and($indexes[0]->columns)->toBe(['email'])
            ->and($indexes[1]->name)->toBe('idx_name_created')
            ->and($indexes[1]->columns)->toBe(['name', 'created_at']);
    });

    it('returns single-column unique indexes from getIndexes', function (): void {
        $connection = createMockConnection([
            'information_schema.statistics' => [
                [
                    'INDEX_NAME' => 'email',
                    'COLUMN_NAME' => 'email',
                    'NON_UNIQUE' => '0',
                    'INDEX_TYPE' => 'BTREE',
                    'SEQ_IN_INDEX' => '1',
                ],
            ],
        ]);

        $table = new MySqlIntrospector($connection, 'testdb')->getIndexes('users');

        expect($table)->toEqual([new Index(name: 'email', columns: ['email'], type: IndexType::Unique)]);
    });

    it('detects unique indexes', function (): void {
        $connection = createMockConnection([
            'information_schema.statistics' => [
                [
                    'INDEX_NAME' => 'idx_email',
                    'COLUMN_NAME' => 'email',
                    'NON_UNIQUE' => '0',
                    'INDEX_TYPE' => 'BTREE',
                    'SEQ_IN_INDEX' => '1',
                ],
                [
                    'INDEX_NAME' => 'idx_name',
                    'COLUMN_NAME' => 'name',
                    'NON_UNIQUE' => '1',
                    'INDEX_TYPE' => 'BTREE',
                    'SEQ_IN_INDEX' => '1',
                ],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $indexes = $introspector->getIndexes('users');

        expect($indexes[0]->type)
            ->toBe(IndexType::Unique)
            ->and($indexes[1]->type)->toBe(IndexType::Btree);
    });

    it('reads foreign keys from information_schema.key_column_usage', function (): void {
        $connection = createMockConnection([
            'key_column_usage' => [
                [
                    'CONSTRAINT_NAME' => 'fk_posts_user',
                    'COLUMN_NAME' => 'user_id',
                    'REFERENCED_TABLE_NAME' => 'users',
                    'REFERENCED_COLUMN_NAME' => 'id',
                    'ORDINAL_POSITION' => '1',
                ],
            ],
            'referential_constraints' => [
                [
                    'CONSTRAINT_NAME' => 'fk_posts_user',
                    'DELETE_RULE' => 'CASCADE',
                    'UPDATE_RULE' => 'NO ACTION',
                ],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $foreignKeys = $introspector->getForeignKeys('posts');

        expect($foreignKeys)
            ->toHaveCount(1)
            ->and($foreignKeys[0])->toBeInstanceOf(ForeignKey::class)
            ->and($foreignKeys[0]->name)->toBe('fk_posts_user')
            ->and($foreignKeys[0]->columns)->toBe(['user_id'])
            ->and($foreignKeys[0]->referencedTable)->toBe('users')
            ->and($foreignKeys[0]->referencedColumns)->toBe(['id']);
    });

    it('detects ON DELETE and ON UPDATE actions', function (): void {
        $connection = createMockConnection([
            'key_column_usage' => [
                [
                    'CONSTRAINT_NAME' => 'fk_orders_user',
                    'COLUMN_NAME' => 'user_id',
                    'REFERENCED_TABLE_NAME' => 'users',
                    'REFERENCED_COLUMN_NAME' => 'id',
                    'ORDINAL_POSITION' => '1',
                ],
            ],
            'referential_constraints' => [
                [
                    'CONSTRAINT_NAME' => 'fk_orders_user',
                    'DELETE_RULE' => 'SET NULL',
                    'UPDATE_RULE' => 'CASCADE',
                ],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $foreignKeys = $introspector->getForeignKeys('orders');

        expect($foreignKeys[0]->onDelete)
            ->toBe('SET NULL')
            ->and($foreignKeys[0]->onUpdate)->toBe('CASCADE');
    });

    it('filters to current database only', function (): void {
        $capturedQueries = [];

        $connection = new class ($capturedQueries) implements ConnectionInterface
        {
            public function __construct(
                /** @noinspection PhpPropertyOnlyWrittenInspection - Reference property modifies external variable */
                private array &$capturedQueries,
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
                $this->capturedQueries[] = ['sql' => $sql, 'bindings' => $bindings];

                return [];
            }

            public function execute(
                string $sql,
                array $bindings = [],
            ): int {
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
                return 'sqlite';
            }
        };

        $introspector = new MySqlIntrospector($connection, 'my_app_db');
        $introspector->getTables();

        // Verify the query includes database filter
        expect($capturedQueries)
            ->toHaveCount(1)
            ->and($capturedQueries[0]['bindings'])->toContain('my_app_db');
    });

    it('checks if table exists', function (): void {
        $connection = createMockConnection([
            'information_schema.tables' => [
                ['TABLE_NAME' => 'users'],
                ['TABLE_NAME' => 'posts'],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');

        expect($introspector->tableExists('users'))
            ->toBeTrue()
            ->and($introspector->tableExists('nonexistent'))->toBeFalse();
    });

    it('gets table schema with columns and indexes', function (): void {
        $queryResults = [];
        $callOrder = [];

        $connection = new class ($queryResults, $callOrder) implements ConnectionInterface
        {
            /**
             * @noinspection PhpPropertyOnlyWrittenInspection, PhpPropertyCanBeReadonlyInspection
             *     Reference properties modify external variables and cannot be readonly
             */
            public function __construct(
                private array &$queryResults,
                private array &$callOrder,
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
                // Match the tables query by its FROM clause: the columns query joins information_schema.tables
                if (str_contains($sql, 'FROM information_schema.tables')) {
                    $this->callOrder[] = 'tables';

                    return [['TABLE_NAME' => 'users']];
                }

                if (str_contains($sql, 'information_schema.columns')) {
                    $this->callOrder[] = 'columns';

                    return [
                        [
                            'COLUMN_NAME' => 'id',
                            'DATA_TYPE' => 'int',
                            'CHARACTER_MAXIMUM_LENGTH' => null,
                            'IS_NULLABLE' => 'NO',
                            'COLUMN_DEFAULT' => null,
                            'EXTRA' => 'auto_increment',
                            'COLUMN_TYPE' => 'int',
                            'COLLATION_NAME' => null,
                        ],
                    ];
                }

                if (str_contains($sql, 'information_schema.statistics')) {
                    $this->callOrder[] = 'indexes';

                    // Return a non-unique index
                    return [
                        [
                            'INDEX_NAME' => 'idx_id',
                            'COLUMN_NAME' => 'id',
                            'NON_UNIQUE' => '1',
                            'INDEX_TYPE' => 'BTREE',
                            'SEQ_IN_INDEX' => '1',
                        ],
                    ];
                }

                return [];
            }

            public function execute(
                string $sql,
                array $bindings = [],
            ): int {
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
                return 'sqlite';
            }
        };

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $table = $introspector->getTable('users');

        expect($table)
            ->toBeInstanceOf(Table::class)
            ->and($table->name)->toBe('users')
            ->and($table->columns)->toHaveCount(1)
            ->and($table->indexes)->toHaveCount(1);
    });

    it('returns null for non-existent table', function (): void {
        $connection = createMockConnection([
            'information_schema.tables' => [],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $table = $introspector->getTable('nonexistent');

        expect($table)->toBeNull();
    });

    it('gets primary key columns', function (): void {
        $connection = createMockConnection([
            "INDEX_NAME = 'PRIMARY'" => [
                [
                    'COLUMN_NAME' => 'id',
                ],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $primaryKey = $introspector->getPrimaryKey('users');

        expect($primaryKey)->toBe(['id']);
    });

    it('gets composite primary key columns', function (): void {
        $connection = createMockConnection([
            "INDEX_NAME = 'PRIMARY'" => [
                [
                    'COLUMN_NAME' => 'post_id',
                ],
                [
                    'COLUMN_NAME' => 'tag_id',
                ],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $primaryKey = $introspector->getPrimaryKey('post_tags');

        expect($primaryKey)->toBe(['post_id', 'tag_id']);
    });

    it('detects fulltext indexes', function (): void {
        $connection = createMockConnection([
            'information_schema.statistics' => [
                [
                    'INDEX_NAME' => 'ft_content',
                    'COLUMN_NAME' => 'content',
                    'NON_UNIQUE' => '1',
                    'INDEX_TYPE' => 'FULLTEXT',
                    'SEQ_IN_INDEX' => '1',
                ],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $indexes = $introspector->getIndexes('posts');

        expect($indexes[0]->type)->toBe(IndexType::Fulltext);
    });

    it('handles composite foreign keys', function (): void {
        $connection = createMockConnection([
            'key_column_usage' => [
                [
                    'CONSTRAINT_NAME' => 'fk_composite',
                    'COLUMN_NAME' => 'tenant_id',
                    'REFERENCED_TABLE_NAME' => 'tenants',
                    'REFERENCED_COLUMN_NAME' => 'id',
                    'ORDINAL_POSITION' => '1',
                ],
                [
                    'CONSTRAINT_NAME' => 'fk_composite',
                    'COLUMN_NAME' => 'user_id',
                    'REFERENCED_TABLE_NAME' => 'tenants',
                    'REFERENCED_COLUMN_NAME' => 'user_id',
                    'ORDINAL_POSITION' => '2',
                ],
            ],
            'referential_constraints' => [
                [
                    'CONSTRAINT_NAME' => 'fk_composite',
                    'DELETE_RULE' => 'CASCADE',
                    'UPDATE_RULE' => 'CASCADE',
                ],
            ],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $foreignKeys = $introspector->getForeignKeys('orders');

        expect($foreignKeys)
            ->toHaveCount(1)
            ->and($foreignKeys[0]->columns)->toBe(['tenant_id', 'user_id'])
            ->and($foreignKeys[0]->referencedColumns)->toBe(['id', 'user_id']);
    });

    it('handles empty table list', function (): void {
        $connection = createMockConnection([
            'information_schema.tables' => [],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $tables = $introspector->getTables();

        expect($tables)->toBe([]);
    });

    it('handles table with no indexes', function (): void {
        $connection = createMockConnection([
            'information_schema.statistics' => [],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $indexes = $introspector->getIndexes('simple_table');

        expect($indexes)->toBe([]);
    });

    it('handles table with no foreign keys', function (): void {
        $connection = createMockConnection([
            'key_column_usage' => [],
        ]);

        $introspector = new MySqlIntrospector($connection, 'testdb');
        $foreignKeys = $introspector->getForeignKeys('standalone_table');

        expect($foreignKeys)->toBe([]);
    });
});
