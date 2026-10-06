<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Integration;

use Marko\Database\Attributes\Column;
use Marko\Database\Attributes\Table;
use Marko\Database\Diff\DiffCalculator;
use Marko\Database\Diff\SchemaDiff;
use Marko\Database\Entity\Entity;
use Marko\Database\Entity\EntityMetadataFactory;
use Marko\Database\Entity\SchemaBuilder;
use Marko\Database\Exceptions\MigrationException;
use Marko\Database\Exceptions\QueryException;
use Marko\Database\Exceptions\UniqueConstraintViolationException;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Introspection\MySqlIntrospector;
use Marko\Database\MySql\Sql\MySqlGenerator;
use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase;
use Marko\Database\Schema\IdentifierName;
use Marko\Database\Schema\Table as SchemaTable;

/*
 * Schema diffs that must settle against a real MySQL server: typed defaults right after creation, and uniqueness
 * added to or removed from an existing column. Entities go through EntityMetadataFactory and SchemaBuilder, as
 * db:migrate builds them. The tests create and drop the settle_* tables.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With MARKO_INTEGRATION_REQUIRED set (CI), a missing host
 * fails instead of skipping. Part of the integration-services group.
 */

/**
 * The schema table an entity declares.
 */
function mysqlSettleSchema(
    object $entity,
): SchemaTable {
    return new SchemaBuilder()->build(new EntityMetadataFactory()->parse($entity::class));
}

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new MySqlConnection($config);
    $this->dropTables = function (): void {
        $tables = [
            'settle_customer_subscription_events',
            'settle_members',
            'settle_teams',
            'settle_defaults',
            'settle_tokens',
            'settle_documents',
            'settle_users',
            'settle_pivots',
        ];

        foreach ($tables as $table) {
            $this->connection->execute("DROP TABLE IF EXISTS $table");
        }
    };
    ($this->dropTables)();

    $this->generator = new MySqlGenerator();
    $this->introspector = new MySqlIntrospector($this->connection, $config->database);

    $this->diffAgainst = function (SchemaTable $entityTable): SchemaDiff {
        $databaseTable = $this->introspector->getTable($entityTable->name);

        return new DiffCalculator()->calculate(
            [$entityTable->name => $entityTable],
            $databaseTable !== null ? [$entityTable->name => $databaseTable] : [],
        );
    };

    $this->run = function (array $statements): void {
        foreach ($statements as $statement) {
            $this->connection->execute($statement);
        }
    };

    $this->create = function (SchemaTable $entityTable): void {
        ($this->run)($this->generator->generateUp(($this->diffAgainst)($entityTable)));
    };

    $this->plainUsers = mysqlSettleSchema(new #[Table('settle_users')] class () extends Entity
    {
        #[Column(primaryKey: true, autoIncrement: true)]
        public int $id;

        #[Column(length: 191)]
        public string $email;
    });

    $this->uniqueUsers = mysqlSettleSchema(new #[Table('settle_users')] class () extends Entity
    {
        #[Column(primaryKey: true, autoIncrement: true)]
        public int $id;

        #[Column(length: 191, unique: true)]
        public string $email;
    });

    // Every name derived from this table and column is over 63 bytes before shortening
    $this->longBody = 'settle_customer_subscription_events_external_billing_reference_id';

    $this->plainEvents = mysqlSettleSchema(new #[Table('settle_customer_subscription_events')] class () extends Entity
    {
        #[Column(primaryKey: true, autoIncrement: true)]
        public int $id;

        #[Column]
        public int $externalBillingReferenceId;
    });

    $this->linkedEvents = mysqlSettleSchema(new #[Table('settle_customer_subscription_events')] class () extends Entity
    {
        #[Column(primaryKey: true, autoIncrement: true)]
        public int $id;

        #[Column(unique: true, references: 'settle_users.id')]
        public int $externalBillingReferenceId;
    });

    $this->referencedEvents = mysqlSettleSchema(
        new #[Table('settle_customer_subscription_events')]
        class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;

            #[Column(references: 'settle_users.id')]
            public int $externalBillingReferenceId;
        },
    );
});

afterEach(function (): void {
    if (isset($this->connection)) {
        ($this->dropTables)();
        $this->connection->disconnect();
    }
});

describe('MySQL schema diffs that settle', function (): void {
    it('diffs integer, boolean, decimal, varchar and timestamp defaults as empty after creation', function (): void {
        $entityTable = mysqlSettleSchema(new #[Table('settle_defaults')] class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;

            #[Column(default: 0)]
            public int $qty;

            #[Column(default: false)]
            public bool $active;

            #[Column(default: 0.00)]
            public float $price;

            #[Column(default: 'x')]
            public string $label;

            #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
            public string $createdAt;

            #[Column(type: 'int')]
            public int $plain;
        });

        ($this->create)($entityTable);

        expect(($this->diffAgainst)($entityTable)->isEmpty())->toBeTrue();
    });

    it('diffs a uuid primary key with and without a UUID() default as empty after creation', function (): void {
        $entityTable = mysqlSettleSchema(new #[Table('settle_tokens')] class () extends Entity
        {
            #[Column(type: 'uuid', primaryKey: true, default: 'UUID()')]
            public string $id;

            #[Column(type: 'uuid')]
            public string $ownerId;
        });

        ($this->create)($entityTable);

        expect(($this->diffAgainst)($entityTable)->isEmpty())->toBeTrue();
    });

    it('diffs json columns as empty after creation and after a nullability change', function (): void {
        // MariaDB stores JSON as LONGTEXT with a json_valid() check; the introspector reads it back as json
        $required = mysqlSettleSchema(new #[Table('settle_documents')] class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;

            #[Column(type: 'json')]
            public array $body;
        });
        $nullable = mysqlSettleSchema(new #[Table('settle_documents')] class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;

            #[Column(type: 'json')]
            public ?array $body;
        });

        ($this->create)($required);
        $afterCreate = ($this->diffAgainst)($required)->isEmpty();
        ($this->create)($nullable);
        $body = $this->introspector->getTable('settle_documents')->columns[1];

        expect($afterCreate)->toBeTrue()
            ->and(($this->diffAgainst)($nullable)->isEmpty())->toBeTrue()
            ->and($body->type)->toBe('json')
            ->and($body->nullable)->toBeTrue();
    });

    it('diffs a table created with a unique column as empty', function (): void {
        ($this->create)($this->uniqueUsers);

        expect(($this->diffAgainst)($this->uniqueUsers)->isEmpty())->toBeTrue();
    });

    it('adds the unique index when a column becomes unique and the diff is then empty', function (): void {
        ($this->create)($this->plainUsers);
        $diff = ($this->diffAgainst)($this->uniqueUsers);
        ($this->run)($this->generator->generateUp($diff));

        expect($diff->isEmpty())->toBeFalse()
            ->and(($this->diffAgainst)($this->uniqueUsers)->isEmpty())->toBeTrue()
            ->and(fn () => $this->connection->execute(
                "INSERT INTO settle_users (email) VALUES ('a@example.com'), ('a@example.com')",
            ))->toThrow(UniqueConstraintViolationException::class);
    });

    it('drops the unique index when a column stops being unique and the diff is then empty', function (): void {
        ($this->create)($this->uniqueUsers);
        ($this->run)($this->generator->generateUp(($this->diffAgainst)($this->plainUsers)));
        $this->connection->execute("INSERT INTO settle_users (email) VALUES ('a@example.com'), ('a@example.com')");

        expect(($this->diffAgainst)($this->plainUsers)->isEmpty())->toBeTrue();
    });

    it('restores uniqueness in down', function (): void {
        ($this->create)($this->uniqueUsers);
        $removal = ($this->diffAgainst)($this->plainUsers);
        ($this->run)($this->generator->generateUp($removal));
        ($this->run)($this->generator->generateDown($removal));

        expect(($this->diffAgainst)($this->uniqueUsers)->isEmpty())->toBeTrue()
            ->and(fn () => $this->connection->execute(
                "INSERT INTO settle_users (email) VALUES ('a@example.com'), ('a@example.com')",
            ))->toThrow(UniqueConstraintViolationException::class);
    });

    it('removes an added unique index in down', function (): void {
        ($this->create)($this->plainUsers);
        $addition = ($this->diffAgainst)($this->uniqueUsers);
        ($this->run)($this->generator->generateUp($addition));
        ($this->run)($this->generator->generateDown($addition));

        expect(($this->diffAgainst)($this->plainUsers)->isEmpty())->toBeTrue();
    });

    it('drops uniqueness from a foreign key column, migrates down, and the diff is then empty', function (): void {
        ($this->create)(mysqlSettleSchema(new #[Table('settle_teams')] class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;
        }));

        $uniqueMembers = mysqlSettleSchema(new #[Table('settle_members')] class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;

            #[Column(unique: true, references: 'settle_teams.id')]
            public int $teamId;
        });

        $plainMembers = mysqlSettleSchema(new #[Table('settle_members')] class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;

            #[Column(references: 'settle_teams.id')]
            public int $teamId;
        });

        ($this->create)($uniqueMembers);
        $removal = ($this->diffAgainst)($plainMembers);
        ($this->run)($this->generator->generateUp($removal));
        $settledAfterUp = ($this->diffAgainst)($plainMembers)->isEmpty();
        ($this->run)($this->generator->generateDown($removal));

        expect($removal->isEmpty())->toBeFalse()
            ->and($settledAfterUp)->toBeTrue()
            ->and(($this->diffAgainst)($uniqueMembers)->isEmpty())->toBeTrue();
    });

    it(
        'adds a unique index and foreign key with over-long derived names and the diff is then empty',
        function (): void {
            ($this->create)($this->plainUsers);
            ($this->create)($this->plainEvents);
            ($this->run)($this->generator->generateUp(($this->diffAgainst)($this->linkedEvents)));
            $table = $this->introspector->getTable('settle_customer_subscription_events');

            expect(($this->diffAgainst)($this->linkedEvents)->isEmpty())->toBeTrue()
                ->and(array_column($table->indexes, 'name'))
                ->toContain(IdentifierName::derive($this->longBody, suffix: '_unique'))
                ->and(array_column($table->foreignKeys, 'name'))
                ->toContain(IdentifierName::derive($this->longBody, prefix: 'fk_'));
        },
    );

    it(
        'adds the over-long derived replacement index when a foreign key column stops being unique',
        function (): void {
            ($this->create)($this->plainUsers);
            ($this->create)($this->plainEvents);
            ($this->run)($this->generator->generateUp(($this->diffAgainst)($this->linkedEvents)));
            ($this->run)($this->generator->generateUp(($this->diffAgainst)($this->referencedEvents)));
            $table = $this->introspector->getTable('settle_customer_subscription_events');

            expect(($this->diffAgainst)($this->referencedEvents)->isEmpty())->toBeTrue()
                ->and(array_column($table->indexes, 'name'))
                ->toContain(IdentifierName::derive($this->longBody, suffix: '_index'));
        },
    );
});

describe('MySQL primary key columns added to existing tables', function (): void {
    beforeEach(function (): void {
        // A key-less pivot with rows, as tables created by hand before an entity owned them can be
        $this->connection->execute('CREATE TABLE settle_pivots (user_id INT NOT NULL, role_id INT NOT NULL)');
        $this->connection->execute('INSERT INTO settle_pivots (user_id, role_id) VALUES (1, 10), (2, 20)');

        $this->keyedPivots = mysqlSettleSchema(new #[Table('settle_pivots')] class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;

            #[Column]
            public int $userId;

            #[Column]
            public int $roleId;
        });

        $this->showCreate = fn (string $table): string => (string) array_values(
            $this->connection->query("SHOW CREATE TABLE $table")[0],
        )[1];
    });

    it('adds an auto-increment primary key column to a table with rows and the diff is then empty', function (): void {
        $statements = $this->generator->generateUp(($this->diffAgainst)($this->keyedPivots));
        ($this->run)($statements);
        $id = array_find(
            $this->introspector->getTable('settle_pivots')->columns,
            fn ($column): bool => $column->name === 'id',
        );

        expect($statements)->toHaveCount(1)
            ->and($statements[0])->toContain('ADD PRIMARY KEY (`id`)')
            ->and(($this->diffAgainst)($this->keyedPivots)->isEmpty())->toBeTrue()
            ->and($id->primaryKey)->toBeTrue()
            ->and($id->autoIncrement)->toBeTrue()
            ->and(array_column(
                $this->connection->query('SELECT id, user_id FROM settle_pivots ORDER BY user_id'),
                'id',
            ))->toEqual([1, 2]);
    });

    it(
        'adds a uuid primary key column with a UUID() default to a table with rows on MariaDB, and MySQL refuses it',
        function (): void {
            $this->connection->execute('CREATE TABLE settle_tokens (owner_id CHAR(36) NOT NULL)');
            $this->connection->execute("INSERT INTO settle_tokens (owner_id) VALUES ('a'), ('b')");
            $original = ($this->showCreate)('settle_tokens');
            $keyedTokens = mysqlSettleSchema(new #[Table('settle_tokens')] class () extends Entity
            {
                #[Column(type: 'uuid', primaryKey: true, default: 'UUID()')]
                public string $id;

                #[Column(type: 'uuid')]
                public string $ownerId;
            });
            $statements = $this->generator->generateUp(($this->diffAgainst)($keyedTokens));

            if (!IntegrationDatabase::isMariaDb($this->connection)) {
                // With binary logging on (the MySQL 8 default), MySQL refuses any ADD COLUMN whose default is
                // non-deterministic (error 1674), key or not. One statement leaves the table as it was.
                expect(fn () => ($this->run)($statements))->toThrow(QueryException::class, '1674')
                    ->and(($this->showCreate)('settle_tokens'))->toBe($original);

                return;
            }

            ($this->run)($statements);
            $ids = array_column($this->connection->query('SELECT id FROM settle_tokens'), 'id');
            $id = array_find(
                $this->introspector->getTable('settle_tokens')->columns,
                fn ($column): bool => $column->name === 'id',
            );

            // MariaDB evaluates the default once per existing row, so the rows get distinct keys
            expect(($this->diffAgainst)($keyedTokens)->isEmpty())->toBeTrue()
                ->and($id->primaryKey)->toBeTrue()
                ->and(array_unique($ids))->toHaveCount(2);
        },
    );

    it(
        'adds a non-auto-increment primary key column without a default to an empty table and the diff is then empty',
        function (): void {
            $this->connection->execute('CREATE TABLE settle_tokens (owner_id CHAR(36) NOT NULL)');
            $keyedTokens = mysqlSettleSchema(new #[Table('settle_tokens')] class () extends Entity
            {
                #[Column(type: 'uuid', primaryKey: true)]
                public string $id;

                #[Column(type: 'uuid')]
                public string $ownerId;
            });

            ($this->run)($this->generator->generateUp(($this->diffAgainst)($keyedTokens)));
            $id = array_find(
                $this->introspector->getTable('settle_tokens')->columns,
                fn ($column): bool => $column->name === 'id',
            );

            expect(($this->diffAgainst)($keyedTokens)->isEmpty())->toBeTrue()
                ->and($id->primaryKey)->toBeTrue();
        },
    );

    it(
        'fails loudly adding a primary key column without a default to a table with rows and leaves the table '
        . 'unchanged',
        function (): void {
            $original = ($this->showCreate)('settle_pivots');
            $codedPivots = mysqlSettleSchema(new #[Table('settle_pivots')] class () extends Entity
            {
                #[Column(length: 20, primaryKey: true)]
                public string $code;

                #[Column]
                public int $userId;

                #[Column]
                public int $roleId;
            });
            $statements = $this->generator->generateUp(($this->diffAgainst)($codedPivots));

            // Both existing rows get the implicit default '', a duplicate key
            expect(fn () => ($this->run)($statements))->toThrow(UniqueConstraintViolationException::class)
                ->and(($this->showCreate)('settle_pivots'))->toBe($original);
        },
    );

    it('refuses to add a primary key column to a table that already has a primary key', function (): void {
        ($this->create)($this->plainUsers);
        $compositeUsers = mysqlSettleSchema(new #[Table('settle_users')] class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;

            #[Column(length: 20, primaryKey: true)]
            public string $tenant;

            #[Column(length: 191)]
            public string $email;
        });

        expect(fn () => $this->generator->generateUp(($this->diffAgainst)($compositeUsers)))->toThrow(
            MigrationException::class,
            "Cannot add primary key column 'tenant' to table 'settle_users', which already has a primary key on 'id'",
        );
    });

    it('drops the added primary key column in down and the table matches the original', function (): void {
        $original = ($this->showCreate)('settle_pivots');
        $originalTable = $this->introspector->getTable('settle_pivots');
        $addition = ($this->diffAgainst)($this->keyedPivots);
        ($this->run)($this->generator->generateUp($addition));
        ($this->run)($this->generator->generateDown($addition));

        expect(($this->showCreate)('settle_pivots'))->toBe($original)
            ->and($this->introspector->getTable('settle_pivots'))->toEqual($originalTable)
            ->and($this->connection->query('SELECT user_id, role_id FROM settle_pivots ORDER BY user_id'))
            ->toEqual([['user_id' => 1, 'role_id' => 10], ['user_id' => 2, 'role_id' => 20]]);
    });
});
