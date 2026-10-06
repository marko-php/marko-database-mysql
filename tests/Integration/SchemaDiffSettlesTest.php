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
use Marko\Database\Exceptions\UniqueConstraintViolationException;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Introspection\MySqlIntrospector;
use Marko\Database\MySql\Sql\MySqlGenerator;
use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase;
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
        $tables = ['settle_members', 'settle_teams', 'settle_defaults', 'settle_tokens', 'settle_documents', 'settle_users'];

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
});
